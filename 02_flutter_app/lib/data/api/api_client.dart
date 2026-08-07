import 'dart:convert';
import 'dart:io';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:get/get_connect/http/src/request/request.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/common/models/error_model.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';

class ApiClient extends GetxService {
   String appBaseUrl = AppConstants.baseUrl ;
  final SharedPreferences sharedPreferences;
  final String noInternetMessage = 'Connection to API server failed due to internet connection';
  final int timeoutInSeconds = 30;
  BaseDeviceInfo deiceInfo;
  final String uniqueId;
  String? token;
  Map<String, String>? _mainHeaders;

  ApiClient({
    required this.appBaseUrl,
    required this.sharedPreferences,
    required this.deiceInfo,
    required this.uniqueId,

  })
  {

    _mainHeaders = {
      'Content-Type': 'application/json; charset=UTF-8',
      // AMIAL-FIX(LOGIN): بلا Accept: application/json يُرجع Laravel أخطاء 500
      // كصفحة HTML بدل JSON، فيفشل jsonDecode ويرى المستخدم «فشل تسجيل الدخول»
      // العامّة بدل السبب الحقيقي. هذا يضمن ردّاً JSON قابلاً للعرض دائماً.
      'Accept': 'application/json',
      'Authorization': 'Bearer $token',
    };

    if(('${deiceInfo.data['isPhysicalDevice']}' == 'true') || AppConstants.demo) {
      _mainHeaders!.addAll({
        'device-id': uniqueId,
        'os': GetPlatform.isAndroid ? 'android' : 'ios',
        'device-model': '${deiceInfo.data['brand']} ${deiceInfo.data['model']}'
      });
    }
  }

   void updateHeader(String token) {
     _mainHeaders = {
       'Content-Type': 'application/json; charset=UTF-8',
       'Accept': 'application/json',
       'Authorization': 'Bearer $token',
     };

     if(('${deiceInfo.data['isPhysicalDevice']}' == 'true') || AppConstants.demo) {
       _mainHeaders!.addAll({
         'device-id': uniqueId,
         'os': GetPlatform.isAndroid ? 'android' : 'ios',
         'device-model': '${GetPlatform.isAndroid
             ? '${deiceInfo.data['brand']} ${deiceInfo.data['device-model']}'
             : ''} ${deiceInfo.data['model']}'.replaceAll(' null ', ' ')
       });
     }
   }

   /// P1-BRANCHES — يحدّد الفرع النشط للـ requests القادمة.
   /// يُرسَل كـ X-Amial-Branch-ID في كل request.
   void setActiveBranchId(int? branchId) {
     if (_mainHeaders == null) return;
     if (branchId == null || branchId <= 0) {
       _mainHeaders!.remove('X-Amial-Branch-ID');
     } else {
       _mainHeaders!['X-Amial-Branch-ID'] = branchId.toString();
     }
   }



   Future<Response> getData(String uri, {Map<String, dynamic>? query, Map<String, String>? headers}) async {
    if(await ApiChecker.isVpnActive()) {
      return const Response(statusCode: -1, statusText: 'you are using vpn');
    }else{
      try {
        if (kDebugMode) debugPrint("====> GET $uri");
        http.Response response = await http.get(
          Uri.parse(appBaseUrl+uri),
          headers: headers ?? _mainHeaders,
        ).timeout(Duration(seconds: timeoutInSeconds));
        return handleResponse(response, uri);
      } catch (e) {
        return Response(statusCode: 1, statusText: noInternetMessage);
      }

    }
   }

  /// AMIAL-PILOT-IDEM-002 — مفاتيحُ الطلبات المعلّقة.
  ///
  /// المفتاحُ حيٌّ ما دام الطلبُ بلا جواب. فإعادةُ الطلب نفسِه — بعنوانه
  /// وجسده — تحمل المفتاح نفسَه، فيراها الخادمُ إعادةً لا عمليّةً ثانية.
  final Map<String, String> _inFlightKeys = {};

  /// هويّةُ الطلب: عنوانُه وجسدُه. فطلبان مختلفا الجسد عمليّتان مختلفتان
  /// ولو تشابه عنوانُهما.
  String _autoIdentity(String uri, dynamic body) {
    String payload;
    try {
      payload = jsonEncode(body);
    } catch (_) {
      payload = body.toString();
    }
    return '$uri|$payload';
  }

  Future<Response> postData(
      String uri, dynamic body, {Map<String, String>? headers, String? idempotencyKey}) async {
    if(await ApiChecker.isVpnActive()) {
      return const Response(statusCode: -1, statusText: 'you are using vpn');
    }{
      try {
        // AMYAL-SECURITY-002 (v0.7-C): debug logs آمنة
        if (kDebugMode) {
          debugPrint('====> POST $uri');
          debugPrint('====> Body keys: ${body is Map ? body.keys.toList() : "<non-map>"}');
          // لا نلوغ token أو body الكامل (قد يحتوي PIN/OTP)
        }

        // AMYAL-SECURITY-002: Idempotency-Key + Zone hint
        final requestHeaders = Map<String, String>.from(headers ?? _mainHeaders!);

        // AMIAL-PILOT-IDEM-002 — **ولا يُترك المفتاح لاجتهاد كلّ نداء.**
        //
        // قِيس: ٩٧ نداءَ `postData` بلا مفتاح مقابل ٢٦ به. ووسيطُ الخادم
        // يُولّد مفتاحاً عشوائيّاً حين لا يجد واحداً — **فالحمايةُ صفر
        // بالضبط حيث لا يتذكّرها كاتبُ النداء.**
        //
        // فصار المفتاحُ يُولَّد هنا لمن لم يُمرّره: مفتاحٌ لكلّ (عنوان +
        // جسد)، يبقى ما دام الطلبُ معلّقاً ويُتلَف حين يُجيب الخادم. ومن
        // مرّره صراحةً فمفتاحُه أولى — لأنّه يعرف حدودَ النيّة البشريّة
        // أكثرَ ممّا يعرفها هذا الموضع.
        final String autoAction = _autoIdentity(uri, body);
        final String effectiveKey = (idempotencyKey != null && idempotencyKey.isNotEmpty)
            ? idempotencyKey
            : _inFlightKeys.putIfAbsent(
                autoAction, () => IdempotencyKeyGenerator.forFinancialAction('auto'));
        requestHeaders['Idempotency-Key'] = effectiveKey;

        requestHeaders['X-Amial-Zone'] = 'SOUTH';
        requestHeaders['X-Amyal-Client-Version'] = '0.7.0';

        http.Response response0 = await http.post(
          Uri.parse(appBaseUrl+uri),
          body: jsonEncode(body),
          headers: requestHeaders,
        ).timeout(Duration(seconds: timeoutInSeconds));
        Response response = handleResponse(response0, uri);

        // **أجاب الخادمُ ولو بالرفض ⇒ النيّةُ حُسمت، والمفتاحُ يُتلَف.**
        // ولولا ذلك لعلق المستعملُ: الوسيطُ يُسجّل المفتاح `failed` عند
        // أيّ ٤xx ثمّ يردّ ٤٠٩ على إعادته، فمن أخطأ رمزَه لا يُعيد أبداً.
        _inFlightKeys.remove(autoAction);
        return response;

      } catch (e) {
        // **ولم يصل جواب: يبقى المفتاح.** لا نعلم أوصل الطلبُ أم لا،
        // فتكون الإعادةُ إعادةً لا عمليّةً ثانية. و«لا نعلم» ليست صفراً.
        return Response(statusCode: 1, statusText: noInternetMessage);
      }

    }
  }
   Future<Response> postMultipartData(String uri, Map<String, String> body, List<MultipartBody>? multipartBody, {Map<String, String>? headers}) async {

     if(await ApiChecker.isVpnActive()) {
       return const Response(statusCode: -1, statusText: 'you are using vpn');
     }{
       try {
         // AMYAL-SECURITY-002 (v0.7-C): logs آمنة
         if (kDebugMode) {
           debugPrint('====> Multipart POST $uri (${multipartBody!.length} files)');
         }
         http.MultipartRequest request = http.MultipartRequest('POST', Uri.parse(appBaseUrl+uri));
         request.headers.addAll(headers ?? _mainHeaders!);
         for(MultipartBody multipart in multipartBody!) {
           if(multipart.file != null) {
             if(kIsWeb) {
               Uint8List list = await multipart.file!.readAsBytes();
               http.MultipartFile part = http.MultipartFile(
                 multipart.key, multipart.file!.readAsBytes().asStream(), list.length,
                 filename: multipart.file!.path,
               );
               request.files.add(part);
             }else {
               File file = File(multipart.file!.path);
               request.files.add(http.MultipartFile(
                 multipart.key, file.readAsBytes().asStream(), file.lengthSync(), filename: file.path.split('/').last,
               ));
             }
           }
         }
         request.fields.addAll(body);
         http.Response response0 = await http.Response.fromStream(await request.send());
         Response response = handleResponse(response0, uri);
         
         if (kDebugMode) debugPrint("====> Response [${response.statusCode}] $uri");
         
         return response;
       } catch (e) {
         return Response(statusCode: 1, statusText: noInternetMessage);
       }
     }

   }


   Future<Response> putData(
    String uri,
    dynamic body, {
    Map<String, dynamic>? query,
    String? contentType,
    Map<String, String>? headers,
    Function(dynamic)? decoder,
    Function(double)? uploadProgress
  }) async {
     if(await ApiChecker.isVpnActive()) {
       return const Response(statusCode: -1, statusText: 'you are using vpn');
     } {
       try {
         if (kDebugMode) debugPrint('====> PUT $uri');
         
         http.Response response0 = await http.put(
           Uri.parse(appBaseUrl+uri),
           body: jsonEncode(body),
           headers: headers ?? _mainHeaders,
         ).timeout(Duration(seconds: timeoutInSeconds));
         Response response = handleResponse(response0, uri);
         
         if (kDebugMode) debugPrint("====> Response [${response.statusCode}] $uri");
         
         return response;

       } catch (e) {
         return Response(statusCode: 1, statusText: noInternetMessage);
       }

     }

   }
   Future<Response> putMultipartData(String uri, Map<String, String> body, List<MultipartBody> multipartBody, {Map<String, String>? headers}) async {

     if(await ApiChecker.isVpnActive()) {
       return const Response(statusCode: -1, statusText: 'you are using vpn');
     } {
       try {
         if (kDebugMode) debugPrint('====> Multipart PUT $uri (${multipartBody.length} files)');
         
         http.MultipartRequest request = http.MultipartRequest('PUT', Uri.parse(appBaseUrl+uri));
         request.headers.addAll(headers ?? _mainHeaders!);
         for(MultipartBody multipart in multipartBody) {
           if(multipart.file != null) {
             if(kIsWeb) {
               Uint8List list = await multipart.file!.readAsBytes();
               http.MultipartFile part = http.MultipartFile(
                 multipart.key, multipart.file!.readAsBytes().asStream(), list.length,
                 filename: multipart.file!.path,
               );
               request.files.add(part);
             }else {
               File file = File(multipart.file!.path);
               request.files.add(http.MultipartFile(
                 multipart.key, file.readAsBytes().asStream(), file.lengthSync(), filename: file.path.split('/').last,
               ));
             }
           }
         }
         request.fields.addAll(body);
         http.Response response0 = await http.Response.fromStream(await request.send());
         Response response = handleResponse(response0, uri);
         
         if (kDebugMode) debugPrint("====> Response [${response.statusCode}] $uri");

         return response;
       } catch (e) {
         return Response(statusCode: 1, statusText: noInternetMessage);
       }
     }

   }

   Future<Response> deleteData(String uri, {Map<String, String>? headers}) async {
     if(await ApiChecker.isVpnActive()) {
       return const Response(statusCode: -1, statusText: 'you are using vpn');
     } {
       try {
         if (kDebugMode) debugPrint("====> GET $uri");
         http.Response response = await http.delete(
           Uri.parse(appBaseUrl+uri),
           headers: headers ?? _mainHeaders,
         ).timeout(Duration(seconds: timeoutInSeconds));
         return handleResponse(response, uri);
       } catch (e) {
         return Response(statusCode: 1, statusText: noInternetMessage);
       }
     }

   }

   Response handleResponse(http.Response response, String uri) {
     dynamic body;
     try {
       body = jsonDecode(response.body);
     }catch(e) {
       debugPrint('error ---> $e');
     }
     Response response0 = Response(
       body: body ?? response.body, bodyString: response.body.toString(),
       request: Request(headers: response.request!.headers, method: response.request!.method, url: response.request!.url),
       headers: response.headers, statusCode: response.statusCode, statusText: response.reasonPhrase,
     );
     if(response0.statusCode != 200 && response0.body != null && response0.body is !String) {
       if(response0.body.toString().startsWith('{errors: [{code:')) {
         ErrorResponseModel errorResponse = ErrorResponseModel.fromJson(response0.body);
         response0 = Response(statusCode: response0.statusCode, body: response0.body, statusText: errorResponse.errors![0].message);
       }else if(response0.body.toString().startsWith('{message')) {
         response0 = Response(statusCode: response0.statusCode, body: response0.body, statusText: response0.body['message']);
       }
     }else if(response0.statusCode != 200 && response0.body == null) {
       response0 = Response(statusCode: 0, statusText: noInternetMessage);
     }
     debugPrint('====> API Response: [${response0.statusCode}] $uri\n${response0.body}');
     return response0;
   }

 }
class MultipartBody {
  String key;
  File? file;

  MultipartBody(this.key, this.file);
}
