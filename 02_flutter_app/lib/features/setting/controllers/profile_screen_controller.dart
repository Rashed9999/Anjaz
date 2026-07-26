import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/setting/controllers/theme_controller.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amyal_pay/features/setting/domain/models/profile_model.dart';
import 'package:amyal_pay/features/setting/domain/reposotories/profile_repo.dart';
import 'package:amyal_pay/features/transaction_money/controllers/bootom_slider_controller.dart';
import 'package:amyal_pay/helper/dialog_helper.dart';
import 'package:amyal_pay/helper/phone_cheker_helper.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/common/widgets/custom_dialog_widget.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';


class ProfileController extends GetxController implements GetxService {
  final ProfileRepo profileRepo;
  ProfileController({required this.profileRepo});
  final BottomSliderController bottomSliderController =
      Get.find<BottomSliderController>();
  ProfileModel? _userInfo;
  bool _isLoading = false;

  ProfileModel? get userInfo => _userInfo;
  bool get isLoading => _isLoading;
  String _gender = 'Male';
  String get gender => _gender;
  // String _occupation = occupationData[1]['title'];
  // String get occupation => _occupation;
  int select = 0;

  bool _showBalanceButtonTapped = false;
  bool get showBalanceButtonTapped => _showBalanceButtonTapped;

  set setUserInfo(ProfileModel value) {
    _userInfo = value;
  }


  Future<void> getProfileData({bool reload = false, bool isUpdate = false}) async {
    if(reload || _userInfo == null) {
      _userInfo = null;
      _isLoading = true;
      if(isUpdate) {
        update();
      }
    }

    if(_userInfo == null) {
      Response response = await profileRepo.getProfileDataApi();
      if (response.statusCode == 200) {
        _userInfo = ProfileModel.fromJson(response.body);

        Get.find<AuthController>().setUserData(UserShortDataModel(
          name: '${_userInfo!.fName} ${_userInfo!.lName}',
          phone: userInfo?.phone?.replaceAll('${PhoneNumberHelper.getCountryCode(userInfo?.phone)}', ''),
          countryCode: PhoneNumberHelper.getCountryCode(userInfo?.phone),
          qrCode: _userInfo?.qrCode,
        ));

      } else {
        ApiChecker.checkApi(response);
      }
      _isLoading = false;
      update();
    }

  }

  Future<void> changePin({required String oldPassword, required String newPassword, required String confirmPassword}) async {
    if ((oldPassword.length < 4) ||
        (newPassword.length < 4) ||
        (confirmPassword.length < 4)) {
      showCustomSnackBarHelper('please_input_4_digit_pin'.tr);
    } else if (newPassword != confirmPassword) {
      showCustomSnackBarHelper('pin_not_matched'.tr);
    } else if(newPassword == oldPassword){
      showCustomSnackBarHelper('please_select_a_new_pin'.tr);
    } else {
      _isLoading = true;
      update();
      Response response = await profileRepo.changePinApi(
        oldPin: oldPassword, newPin: newPassword, confirmPin: confirmPassword,
      );

      if (response.statusCode == 200) {
        // AMIAL-PIN-SEPARATION-001 — كان هنا سطران خاطئان:
        //
        //   await Get.find<AuthController>().updatePin(newPassword);
        //   Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
        //
        // الأول يكتب رمز المعاملات الجديد في خانة كلمة مرور الدخول بالبصمة
        // (biometricPin تُرسَل كـ password عند الدخول الحيوي) — فيفسد الدخول
        // بالبصمة عند أوّل تغيير للرمز.
        //
        // والثاني يُخرج العميل من حسابه ويرميه إلى شاشة الدخول. تغيير رمز
        // المعاملات ليس تغيير كلمة مرور ولا يُبطل الجلسة. اجتماع السطرين هو
        // ما جعل العميل يجد نفسه بعد التغيير أمام شاشة دخول تطلب الرمز
        // الجديد بدل كلمة مروره.
        showCustomSnackBarHelper('تم تغيير رمز المعاملات بنجاح', isError: false);
        Get.back();
      } else {
        ApiChecker.checkApi(response);
      }
      _isLoading = false;
      update();
    }
  }

  Future<Response> pinVerify({required String? getPin, bool isUpdateTwoFactor = true}) async {
    bottomSliderController.setIsLoading = true;
    final Response response = await profileRepo.pinVerifyApi(pin: getPin);

    if (response.statusCode == 200) {
      bottomSliderController.isPinVerified = true;
      bottomSliderController.setIsLoading = false;
      if(isUpdateTwoFactor) {
        updateTwoFactor();
      }
      bottomSliderController.resetPinField();
    } else {
      bottomSliderController.isPinVerified = false;
      bottomSliderController.setIsLoading = false;
      bottomSliderController.resetPinField();
      Get.back();
      ApiChecker.checkApi(response);
    }
    update();
    return response;
  }

  Future<void> updateTwoFactor() async {
    _isLoading = true;
    update();
    Response response = await profileRepo.updateTwoFactorApi();
    await getProfileData(reload: true);
    if (response.statusCode == 200) {
      showCustomSnackBarHelper(response.body['message'], isError: false);
      _isLoading = false;
    } else {
      ApiChecker.checkApi(response);
      _isLoading = false;
    }
    update();
  }



  void routeToTwoFactorAuthScreen(String getPin) {
    pinVerify(getPin: getPin);
  }

  Future twoFactorOnTap() async {
    await pinVerify(getPin: bottomSliderController.pin);
  }

  void twoFactorOnChange() async {
    await updateTwoFactor();
    await getProfileData(reload: true);
  }

  ///Change theme..
  bool _isSwitched = Get.find<ThemeController>().darkTheme;
  var textValue = 'Switch is OFF';

  bool get isSwitched => _isSwitched;

  void onChangeTheme() {
    if (_isSwitched == false) {
      _isSwitched = true;
      textValue = 'Switch Button is ON';
      Get.find<ThemeController>().toggleTheme();
      update();

    } else {
      _isSwitched = false;
      textValue = 'Switch Button is OFF';
      Get.find<ThemeController>().toggleTheme();
      update();
    }
  }

  /// AMIAL-LOGOUT-UX-001 — حوار خروج بسؤال واحد وجوابين.
  ///
  /// كان يعرض زرّين كلاهما يُنفّذ خروجاً: «تسجيل الخروج» و«مسح البيانات
  /// وتسجيل الخروج». والسؤال المعروض «هل أنت متأكد أنك تريد تسجيل الخروج؟»
  /// سؤال نعم/لا — فلا يوجد زرّ «لا» أصلاً، ولا سبيل للتراجع إلا بزرّ الرجوع.
  /// ونصّ الزرّ الثاني كان يفيض فيُقصّ إلى «لبيانات وتسجيل الخروج».
  ///
  /// وخيار «مسح البيانات» كان ينفّذ `sharedPreferences.clear()` — أي مسح
  /// اللغة والسمة وكل التفضيلات، لا بيانات الجلسة فقط. هذا «تصفير تطبيق»
  /// لا خيار خروج، ولا محلّ له في حوار تأكيد.
  ///
  /// الآن: «نعم» تُنفّذ خروجاً آمناً — إبطال الرمز + مسح بيانات البصمة
  /// المحفوظة (تركها بعد الخروج ثغرة: من يمسك الجهاز يدخل ببصمته) —
  /// و«لا» تُغلق الحوار.
  void logOut(BuildContext context) {
    DialogHelper.showAnimatedDialog(context,
        CustomDialogWidget(
          icon: Icons.logout,
          title: 'logout'.tr,
          description: 'are_you_sure_you_want_to_logout'.tr,
          onTapFalseText: 'no'.tr,
          onTapTrueText: 'yes'.tr,
          isFailed: true,
          trueButtonColor: AmyalColors.red,
          falseButtonColor: AmyalColors.textMuted,
          onTapFalse: () => Get.back(),
          onTapTrue: () {
            Get.find<AuthController>().removeBiometricPin().then((_) {
              Get.find<AuthController>().updateToken(isLogOut: true);
              Get.find<AuthController>().logout();
            });
            Navigator.of(context).pop(true);
          },
        ),
        dismissible: true,
        isFlip: true);
  }

  void setGender(String select){
    _gender = select;
    update();
  }

  void updateBalanceButtonTappedStatus({bool shouldUpdate = true}){
    _showBalanceButtonTapped = true;

    if(shouldUpdate){
      update();
    } else{
      _showBalanceButtonTapped = false;
    }

    Future.delayed(const Duration(seconds: AppConstants.balanceHideDurationInSecond),(){
      _showBalanceButtonTapped = false;
      update();
    });
  }


  Future<void> toggleUserBalanceShowingStatus() async {
    bool value  = profileRepo.isUserBalanceHide();
    profileRepo.toggleUserBalanceShowingStatus(!value);
    update(); // This triggers UI rebuild
  }

  bool isUserBalanceHide() {
    return profileRepo.isUserBalanceHide();
  }

}
