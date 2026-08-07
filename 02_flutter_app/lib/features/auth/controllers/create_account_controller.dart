import 'package:country_code_picker/country_code_picker.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:get/get.dart';

class CreateAccountController extends GetxController implements GetxService{
  // AMIAL-FIX: كان يفشل (شاشة رمادية) إن لم تُحمّل الإعدادات من الخادم
  // (configModel = null) لأنه يفكّ null بالقوّة. الآن آمن مع افتراضي اليمن.
  String _countryCode = _resolveInitialCountryCode();

  static String _resolveInitialCountryCode() {
    try {
      final country = Get.find<SplashController>().configModel?.country ?? 'YE';
      return CountryCode.fromCountryCode(country).dialCode ?? '+967';
    } catch (_) {
      return '+967'; // اليمن افتراضياً
    }
  }
  String? _phoneNumber;
  String get countryCode => _countryCode;
  String? get phoneNumber => _phoneNumber;

  void setCountryCode(String dialCode) {
    _countryCode = dialCode;
    update(['countryCode']);
  }

  void setPhoneNumber(String phone) {
    _phoneNumber = phone;
    update();
  }
  void setInitCountryCode(String code) {
    _countryCode = code;
  }
  void sendOtpResponse({required String number}){
    String number0 = number;
    if (number0.isEmpty) {
      showCustomSnackBarHelper('please_give_your_phone_number'.tr, isError: true);
    }
    else if(number0.contains(RegExp(r'[A-Z]'))){
      showCustomSnackBarHelper('phone_number_not_contain_characters'.tr, isError: true);
    }
    else if(number0.contains(RegExp(r'[a-z]'))){
      showCustomSnackBarHelper('phone_number_not_contain_characters'.tr, isError: true);
    }
    else if(number0.contains(RegExp(r'[!@#$%^&*(),.?":{}|<>]'))){
      showCustomSnackBarHelper('phone_number_not_contain_spatial_characters'.tr, isError: true);
    }
    else{
      setPhoneNumber(number);
      Get.find<AuthController>().checkPhone(number);
    }
  }
}