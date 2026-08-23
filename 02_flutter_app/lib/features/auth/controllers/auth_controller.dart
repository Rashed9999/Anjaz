import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:app_settings/app_settings.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:local_auth/local_auth.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:phone_numbers_parser/phone_numbers_parser.dart';
import 'package:amial_pay/helper/amial_crash_reporter.dart';
import 'package:amial_pay/features/transaction_money/controllers/bootom_slider_controller.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/camera_verification/controllers/camera_screen_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/features/verification/controllers/verification_controller.dart';
import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/common/models/signup_body_model.dart';
import 'package:amial_pay/common/models/response_model.dart';
import 'package:amial_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amial_pay/features/auth/domain/quick_receive_preferences.dart';
import 'package:amial_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';

class AuthController extends GetxController implements GetxService {
  final AuthRepo authRepo;
  AuthController({required this.authRepo}) {
    _biometric = authRepo.isBiometricEnabled();
    _checkBiometricSupport();
  }

    bool _isLoading = false;
    bool _isVerifying = false;
    bool _biometric = false;
    bool _isBiometricSupported = false;
    List<BiometricType> _bioList = [];
    List<BiometricType> get bioList => _bioList;

    bool get isLoading => _isLoading;
    bool get isVerifying => _isVerifying;
    bool get biometric => _biometric;
    bool get isBiometricSupported => _isBiometricSupported;




    Future<void> _callSetting() async {
      final LocalAuthentication bioAuth = LocalAuthentication();
      _bioList = await bioAuth.getAvailableBiometrics();
      if(_bioList.isEmpty){
        try{
          AppSettings.openAppSettings(type: AppSettingsType.lockAndPassword);
        }catch(e){
          debugPrint('error ===> $e');
        }
      }
    }

    Future<void> updatePin(String pin) async {
      await authRepo.writeSecureData(AppConstants.biometricPin, pin);
    }

    bool setBiometric(bool isActive) {
      _callSetting().then((value) {
        _callSetting();
      });

      final String? pin = Get.find<BottomSliderController>().pin;
      Get.find<ProfileController>().pinVerify(getPin: pin, isUpdateTwoFactor: false).then((response) async {
        if(response.statusCode == 200 && response.body != null) {
          _biometric = isActive;
          authRepo.setBiometric(isActive && _bioList.isNotEmpty);
          try{
            await authRepo.writeSecureData(AppConstants.biometricPin, pin);
          }catch(error) {
            debugPrint('error ===> $error');
          }
          Get.back(closeOverlays: true);
          update();
        }
      });

    return _biometric;
  }


  Future<String> biometricPin() async {
      return await  authRepo.readSecureData(AppConstants.biometricPin);
  }

  Future<void> removeBiometricPin() async {
    return await  authRepo.deleteSecureData(AppConstants.biometricPin);
  }

  void checkBiometricWithPin() async {
    if(_biometric && (await biometricPin() == ''))  {
      authRepo.setBiometric(false).then((value) => _biometric = authRepo.isBiometricEnabled());
    }
  }

  Future<bool> authenticateWithBiometric(String localizedReason) async {
    final LocalAuthentication bioAuth = LocalAuthentication();
    bool didAuthenticate = false;
    _bioList = await bioAuth.getAvailableBiometrics();

    if((await bioAuth.canCheckBiometrics || await bioAuth.isDeviceSupported()) && authRepo.isBiometricEnabled()) {
      if (_bioList.isNotEmpty) {
        try {
           didAuthenticate = await bioAuth.authenticate(
            localizedReason: localizedReason,
            biometricOnly: true,
            persistAcrossBackgrounding: true,
          );
        } catch(e) {
          bioAuth.stopAuthentication();
        }
      }
    }
    return didAuthenticate;
  }


  void bioAthLogin() async {
    final isAbleToLogin = (biometric && bioList.isNotEmpty);

    if(isAbleToLogin && await authenticateWithBiometric('please_authenticate_to_login'.tr)) {
      login(
        code: getUserData()?.countryCode,
        phone: getUserData()?.phone,
        password: await biometricPin(),
      );

    }
  }

  Future<void> bioAthPinSetup(String? pin) async {
    if(await authenticateWithBiometric('please_authenticate_to_easy_access_for_next_time'.tr)) {
      await authRepo.writeSecureData(AppConstants.biometricPin, pin);

    }
  }


  void _checkBiometricSupport() async {
    // فحص توفّر القياسات الحيوية قد يرمي على أجهزة/منصّات بلا دعم — لا يجب أن
    // يُسقط الإقلاع؛ نسقط بأمان إلى "غير مدعوم".
    try {
      final LocalAuthentication bioAuth = LocalAuthentication();
      _bioList = await bioAuth.getAvailableBiometrics();
      _isBiometricSupported = await bioAuth.canCheckBiometrics || await bioAuth.isDeviceSupported();
    } catch (e) {
      _bioList = const [];
      _isBiometricSupported = false;
      if (kDebugMode) debugPrint('[Auth] biometric check unavailable: $e');
    }
  }

  Future<Response> checkPhone(String phone) async{
      _isLoading = true;
      update();
      Response response = await authRepo.checkPhoneNumber(phoneNumber: phone);

      if(response.statusCode == 200){
        if(!Get.find<SplashController>().configModel!.phoneVerification!) {
          requestCameraPermission(fromEditProfile: false);
        }else if(response.body['otp'] == "active"){
         Get.find<VerificationController>().startTimer();
         Get.toNamed(RouteHelper.getVerifyRoute());
        }else{
          showCustomSnackBarHelper(response.body['message']);
        }

      }
      else if(response.statusCode == 403 && response.body['user_type'] == 'customer'){

        PhoneNumber phoneNumber = PhoneNumber.parse(phone);
        String numberWithCountryCode = phoneNumber.international;

        String? countryCode = phoneNumber.countryCode;
        if(!countryCode.contains('+')){
          countryCode = '+$countryCode';
        }
        String? nationalNumber = numberWithCountryCode.replaceAll(countryCode, '');

        String? userName = response.body['user_name'] ?? '';

        await setUserData(UserShortDataModel(
          countryCode: countryCode,
          phone: nationalNumber.replaceAll('+', ''),
          name: userName,
        ));

        authRepo.setBiometric(false);
        Get.offNamed(RouteHelper.getUnifiedLoginRoute());

      }
      else{
        ApiChecker.checkApi(response);
      }
      _isLoading = false;
      update();
      return response;
    }


  Future<void> requestCameraPermission({required bool fromEditProfile}) async {
    var serviceStatus = await Permission.camera.status;

    if(serviceStatus.isGranted && GetPlatform.isAndroid){
      Get.offNamed(RouteHelper.getSelfieRoute(fromEditProfile: fromEditProfile));
    }else{
      if(GetPlatform.isIOS){
        Get.offNamed(RouteHelper.getSelfieRoute(fromEditProfile: fromEditProfile));
      }else{
        final status = await Permission.camera.request();
        if (status == PermissionStatus.granted) {
          Get.offNamed(RouteHelper.getSelfieRoute(fromEditProfile: fromEditProfile));
        } else if (status == PermissionStatus.denied) {
          Get.find<CameraScreenController>().showDeniedDialog(fromEditProfile: fromEditProfile);
        } else if (status == PermissionStatus.permanentlyDenied) {
          Get.find<CameraScreenController>().showPermanentlyDeniedDialog(fromEditProfile: fromEditProfile);
        }
      }

    }
  }

    //Phone Number verification
  Future<ResponseModel> phoneVerify(String phoneNumber,String otp) async{
    _isLoading = true;
    update();
    Response response = await authRepo.verifyPhoneNumber(phoneNumber: phoneNumber, otp: otp);

    ResponseModel responseModel;
    if(response.statusCode == 200){
      responseModel = ResponseModel(true, response.body["message"]);
      Get.find<VerificationController>().cancelTimer();
      showCustomSnackBarHelper(responseModel.message, isError: false);
      requestCameraPermission(fromEditProfile: false);
    }
    else{
      responseModel = ResponseModel(false, response.body['errors'][0]['message']);
      showCustomSnackBarHelper(
          responseModel.message,
          isError: true);
    }
    _isLoading = false;
    update();
    return responseModel;
  }


  // registration ..
  Future<Response> registration(SignUpBodyModel signUpBody,List<MultipartBody> multipartBody) async{
      _isLoading = true;
      update();

      Map<String, String> allCustomerInfo = {
        'f_name': signUpBody.fName ?? '',
        'l_name': signUpBody.lName ?? '',
        'phone': signUpBody.phone!,
        'dial_country_code': signUpBody.dialCountryCode!,
        'password': signUpBody.password!,
        'gender': signUpBody.gender!,
        'occupation': signUpBody.occupation ?? '',
      };
      if(signUpBody.otp != null) {
        allCustomerInfo.addAll({'otp': signUpBody.otp!});
      }
      if(signUpBody.email != '') {
        allCustomerInfo.addAll({'email': signUpBody.email!});
      }

      Response response = await authRepo.registration(allCustomerInfo, multipartBody);

      if (response.statusCode == 200) {
        Get.find<CameraScreenController>().removeImage();

        await setUserData(UserShortDataModel(
          countryCode: signUpBody.dialCountryCode,
          phone: signUpBody.phone,
          name: '${signUpBody.fName} ${signUpBody.lName}'
        ));

        Get.find<AuthController>().bioAthPinSetup(signUpBody.password).then((value) {

          UserShortDataModel? userData = Get.find<AuthController>().getUserData();
          Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
        });

        // Get.offAllNamed(RouteHelper.getWelcomeRoute(
        //   countryCode: signUpBody.dialCountryCode,phoneNumber: signUpBody.phone,
        //   password: signUpBody.password,
        // ));

      } else {
        ApiChecker.checkApi(response);
      }
      _isLoading = false;
      update();
      return response;
  }


  Future<Response> login({String? code, String? phone, String? password}) async {
    _isLoading = true;
    update();

    Response response = await authRepo.login(phone: phone, password: password, dialCode: code);

    if (response.statusCode == 200 && response.body['response_code'] == 'auth_login_200' && response.body['content'] != null) {
       // ══════════════════════════════════════════════════════════════
       // AMIAL-QUICK-RECEIVE-003 — **حارسٌ مبنيٌّ ولا يُنادى.**
       //
       // `QuickReceivePreferences.disableIfOwnedByAnother` كُتبت في
       // التزامٍ عنوانُه «prevent stale-account quick receive exposure»،
       // **وقِيس أنّ لا مُنادِيَ لها في التطبيق كلِّه**. فالحمايةُ
       // المُعلَنةُ لم تكن تقع: جهازٌ فُعّل عليه الاستلامُ السريع لحسابٍ،
       // ثمّ دخله حسابٌ آخر — تبقى شاشةُ ما قبل الدخول تعرض **عنوانَ
       // الحساب الأوّل واسمَه**، ويستقبل عليه من يمسحه.
       //
       // وموضعُها الدخول لا فتحُ الشاشة: من لا يفتح ورقةَ الـQR لا
       // يُنظَّف جهازُه أبداً. (القاعدةُ الثانيةَ عشرة: مبنيٌّ ولا
       // يُوصَل إليه — والالتزامُ يَعِد بالحماية.)
       // ══════════════════════════════════════════════════════════════
       await QuickReceivePreferences.disableIfOwnedByAnother(
         '${code ?? ''}${phone ?? ''}',
       );

       authRepo.saveUserToken(response.body['content']).then((value) async {
         await updateToken();
       });
      if(Get.currentRoute != RouteHelper.navbar) {
        Get.offAllNamed(RouteHelper.getNavBarRoute(), arguments: true);
      }
    }
    else{
      ApiChecker.checkApi(response);
    }
    _isLoading = false;
    update();
    return response;
  }


  Future<void> updateToken({bool? isLogOut}) async {
    await authRepo.updateToken(isLogOut: isLogOut);
  }

  Future removeUser() async {

    _isLoading = true;
    update();
    Get.back();
    Response response = await authRepo.deleteUser();

    if (response.statusCode == 200) {
      Get.find<SplashController>().removeSharedData();
      showCustomSnackBarHelper('your_account_remove_successfully'.tr);
      Get.offAllNamed(RouteHelper.getSplashRoute());
    }else{
      Get.back();
      ApiChecker.checkApi(response);
    }
    _isLoading = false;
    update();
  }


  Future<Response> checkOtp()async{
      _isLoading = true;
      update();
      Response  response = await authRepo.checkOtpApi();
      if(response.statusCode == 200){
        _isLoading = false;
      }else{
        _isLoading = false;
        ApiChecker.checkApi(response);
      }
      update();
      return response;
  }

  Future<Response> verifyOtp(String otp)async{
    _isVerifying = true;
    update();
    Response  response = await authRepo.verifyOtpApi(otp: otp);
    if(response.statusCode == 200){
      _isVerifying = false;
      Get.back();
    }else{
      Get.back();
      ApiChecker.checkApi(response);
      _isVerifying = false;
    }
    _isVerifying = false;
    update();
    return response;
  }


  Future<Response> logout() async {
    _isLoading = true;
    update();
    Response response = await authRepo.logout();
    if (response.statusCode == 200) {
      // AMIAL-CRASH-005: أعطال من يستعمل الجهاز بعده لا تُنسب إلى حسابه.
      await AmialCrashReporter.forgetIdentity();

      Get.offAllNamed(RouteHelper.getSplashRoute());
      _isLoading = false;
    }
    else{
      _isLoading = false;
      ApiChecker.checkApi(response);
    }
    update();
    return response;
  }

  Future<ResponseModel?> otpForForgetPass(String phoneNumber) async{
    _isLoading = true;
    update();
    Response response = await authRepo.forgetPassOtp(phoneNumber: phoneNumber);
    ResponseModel? responseModel;

    if(response.statusCode == 200){
      _isLoading = false;
      Get.toNamed(RouteHelper.getVerifyRoute(phoneNumber: phoneNumber));
    }
    else{
      _isLoading = false;
      ApiChecker.checkApi(response);

    }
    update();
    return responseModel;
  }

  Future<Response> verificationForForgetPass(String? phoneNumber, String otp) async{
    _isLoading = true;
    update();
    Response response = await authRepo.forgetPassVerification(phoneNumber: phoneNumber,otp: otp);

    if(response.statusCode == 200){
      _isLoading = false;
      Get.offNamed(RouteHelper.getFResetPassRoute(phoneNumber: phoneNumber, otp: otp));
    }
    else{
      _isLoading = false;
      ApiChecker.checkApi(response);
    }
    update();
    return response;
  }


  String? getAuthToken() {
    return authRepo.getUserToken();
  }


  bool isLoggedIn() {
    return authRepo.isLoggedIn();
  }

  void removeCustomerToken() {
    authRepo.removeCustomerToken();
  }



  Future setUserData(UserShortDataModel userData) async {
    await authRepo.setUserData(userData);
  }

  UserShortDataModel? getUserData(){
    UserShortDataModel? userData;
    if(authRepo.getUserData() != '') {
      userData = UserShortDataModel.fromJson(jsonDecode(authRepo.getUserData()));
    }
    return userData;
  }

  void removeUserData()=>  authRepo.removeUserData();

  Future<void> setTourWidgetStatus(bool newValue) async {
    authRepo.setTourWidgetStatus(newValue);
  }

  bool getTourWidgetStatus() {
    return authRepo.showTourWidget();
  }

  Future<void> setWelcomeBottomSheetStatus(bool newValue) async {
    authRepo.setWelcomeBottomSheetStatus(newValue);
  }

  bool showWelcomeBottomSheet() {
    return authRepo.showWelcomeBottomSheet();
  }

}
