import 'dart:io';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/amial/screens/terms_acceptance_screen.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';

class ApiChecker {
  static bool _openingTerms = false;
  static bool _handlingDeviceSession = false;

  static bool _hasArabic(String value) =>
      RegExp(r'[\u0600-\u06FF]').hasMatch(value);

  static String _rawMessage(Response response) {
    final body = response.body;
    if (body is Map) {
      final direct = body['message']?.toString().trim();
      if (direct != null && direct.isNotEmpty) return direct;

      final errors = body['errors'];
      if (errors is List && errors.isNotEmpty) {
        final first = errors.first;
        if (first is Map) {
          final m = first['message']?.toString().trim();
          if (m != null && m.isNotEmpty) return m;
        }
      }
    }
    return response.statusText?.trim() ?? '';
  }

  static String _arabicMessage(Response response) {
    final raw = _rawMessage(response);
    if (raw.isNotEmpty && _hasArabic(raw)) return raw;

    final normalized = raw.toLowerCase();
    if (normalized.contains('access denied') ||
        normalized.contains('access forbidden') ||
        normalized.contains('permission denied') ||
        response.statusCode == 403) {
      return 'لا تملك صلاحية الوصول إلى هذه الخدمة بحسابك الحالي.';
    }
    if (normalized.contains('unauthorized') || response.statusCode == 401) {
      return 'انتهت الجلسة. سجّل الدخول من جديد.';
    }
    if (normalized.contains('not found') || response.statusCode == 404) {
      return 'المحتوى المطلوب غير متاح حالياً.';
    }
    if (normalized.contains('invalid') ||
        normalized.contains('missing') ||
        response.statusCode == 400 ||
        response.statusCode == 422) {
      return 'بعض البيانات المطلوبة غير صحيحة أو ناقصة.';
    }
    if (response.statusCode == 429) {
      return 'محاولات كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.';
    }
    if (response.statusCode != null && response.statusCode! >= 500) {
      return 'حدثت مشكلة في الخادم. أعد المحاولة، وإذا استمرت المشكلة فتواصل مع الدعم.';
    }
    if (normalized.contains('connection') ||
        normalized.contains('network') ||
        normalized.contains('internet')) {
      return 'تعذّر الاتصال بالخادم. تحقق من الإنترنت ثم أعد المحاولة.';
    }
    return 'تعذّر تنفيذ الطلب. أعد المحاولة.';
  }

  static void checkApi(Response response) {
    // AMIAL-FIX(POST-LOGIN): عند انتهاء الجلسة (401/429) نُعيد لشاشة الدخول
    // الموحّدة لأميال باي — لا لشاشة PIN القديمة (6cash) التي تظهر بعلم دولة
    // أجنبية ولا تخصّ المشروع. نحرس من الحلقة بتفادي إعادة التوجيه إن كنّا فيها.
    final onUnifiedLogin = Get.currentRoute.contains(RouteHelper.unifiedLoginScreen);
    final responseCode = response.body is Map
        ? response.body['code']?.toString()
        : null;

    // AMIAL-DEVICE-SESSION-UX-001 — الجهاز غير النشط ليس «Access denied»
    // يرنّ من كل API في الصفحة. ننهي الجلسة مرة واحدة ونشرح الإجراء.
    if (response.statusCode == 403 &&
        (responseCode == 'DEVICE_NOT_ACTIVE' ||
         responseCode == 'DEVICE_BLOCKED' ||
         responseCode == 'DEVICE_ID_REQUIRED')) {
      if (!_handlingDeviceSession) {
        _handlingDeviceSession = true;
        final message = _arabicMessage(response);
        Future<void>.microtask(() async {
          try {
            Get.find<AuthController>().removeCustomerToken();
            if (!Get.currentRoute.contains(RouteHelper.unifiedLoginScreen)) {
              Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
            }
            showCustomSnackBarHelper(message, isError: true);
          } finally {
            await Future<void>.delayed(const Duration(seconds: 2));
            _handlingDeviceSession = false;
          }
        });
      }
      return;
    }

    // AMIAL-LEGAL-LOOP-001:
    // الخادم هو مصدر الحقيقة. إذا تغيّر إصدار الشروط أثناء جلسة قائمة،
    // لا نكتفي برسالة 403؛ نفتح شاشة الإصدار الحالي مرة واحدة، ونعود
    // للشاشة التي كان عليها العميل بعد القبول.
    if (response.statusCode == 403 &&
        responseCode == 'TERMS_ACCEPTANCE_REQUIRED') {
      if (!_openingTerms) {
        _openingTerms = true;
        Future<void>.microtask(() async {
          try {
            await Get.to(() => TermsAcceptanceScreen(
                  mandatory: true,
                  onAccepted: () => Get.back(result: true),
                ));
          } finally {
            _openingTerms = false;
          }
        });
      }
      showCustomSnackBarHelper(
        'terms_acceptance_required_to_continue'.tr,
        isError: true,
      );
      return;
    }

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-MERCHANT-SESSION-001 — **٤٢٩ لم تعد تُنهي الجلسة.**
    //
    // ٤٢٩ = «تجاوزتَ حدَّ المحاولات في الدقيقة» — حدٌّ يمرّ بعد ثوانٍ.
    // ومعاملتُه معاملةَ رمزٍ منتهٍ **تحذف رمزَ الدخول وتطرد المستعمل**
    // على ضغطتين متتاليتين، ولا سبيلَ له إلى فهم ما جرى: يُعاد إلى شاشة
    // الدخول برسالةٍ عن حدٍّ لا عن جلسة.
    //
    // **و٤٠١ وحدَها تعني «الرمزُ لم يعد صالحاً»** — وهي وحدَها ما يستحقّ
    // إنهاءَ الجلسة. والباقي يُقال ولا يُطرَد صاحبُه.
    // ══════════════════════════════════════════════════════════════════
    if(response.statusCode == 429 && !onUnifiedLogin) {
      showCustomSnackBarHelper(
        'محاولاتٌ كثيرةٌ في وقتٍ قصير — انتظر دقيقةً ثمّ أعد المحاولة',
        isError: true,
      );
      return;
    }

    if(response.statusCode == 401 && !onUnifiedLogin) {
      Get.find<AuthController>().removeCustomerToken();
      Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());

      showCustomSnackBarHelper(_arabicMessage(response), isError: true);

    }else if(response.statusCode == -1 && !onUnifiedLogin){
      Get.find<AuthController>().removeCustomerToken();
      Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
      showCustomSnackBarHelper('you are using vpn', isVpn: true, duration: const Duration(minutes: 10));

    }
    else {
      showCustomSnackBarHelper(_arabicMessage(response), isError: true);
    }
  }

  static Future<bool> isVpnActive() async {
    bool isVpnActive;
    List<NetworkInterface> interfaces = await NetworkInterface.list(
        includeLoopback: false, type: InternetAddressType.any);
    interfaces.isNotEmpty
        ? isVpnActive = interfaces.any((interface) =>
    interface.name.contains("tun") ||
        interface.name.contains("ppp") ||
        interface.name.contains("pptp"))
        : isVpnActive = false;

    return isVpnActive;
  }
}
