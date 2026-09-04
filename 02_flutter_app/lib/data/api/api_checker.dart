import 'dart:io';
import 'package:get/get.dart';
import 'package:amial_pay/common/models/error_model.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';

class ApiChecker {
  static void checkApi(Response response) {
    // AMIAL-FIX(POST-LOGIN): عند انتهاء الجلسة (401/429) نُعيد لشاشة الدخول
    // الموحّدة لأميال باي — لا لشاشة PIN القديمة (6cash) التي تظهر بعلم دولة
    // أجنبية ولا تخصّ المشروع. نحرس من الحلقة بتفادي إعادة التوجيه إن كنّا فيها.
    final onUnifiedLogin = Get.currentRoute.contains(RouteHelper.unifiedLoginScreen);

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

      showCustomSnackBarHelper(response.body != null
          ? response.body['message'] ?? ErrorResponseModel.fromJson(response.body).errors?.first.message ?? ''
          : response.statusText, isError: true,
      );

    }else if(response.statusCode == -1 && !onUnifiedLogin){
      Get.find<AuthController>().removeCustomerToken();
      Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
      showCustomSnackBarHelper('you are using vpn', isVpn: true, duration: const Duration(minutes: 10));

    }
    else {
      showCustomSnackBarHelper(response.body != null
          ? response.body['message'] ?? ErrorResponseModel.fromJson(response.body).errors?.first.message ?? ''
          : response.statusText, isError: true);
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
