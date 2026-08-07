import 'package:get/get.dart';
import 'package:amial_pay/common/models/on_boarding_model.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/images.dart';

class OnBoardingController extends GetxController implements GetxService {
  int _page = 0;

  int get page => _page;

 void updatePageIndex(int a, {bool isUpdate = true}) {
    _page = a;

    if(isUpdate) {
      update();
    }
  }

  List<OnboardModel> onboardList = [];

  void getOnboardList() {
    onboardList = [
      OnboardModel(
        Images.onboardImage1,
        Images.onboardBackground1,
        'on_boarding_title_1'.tr,
        '${'send_money_from'.tr} ${AppConstants.appName} ${'easily_at_anytime'.tr}',
      ),

      OnboardModel(
        Images.onboardImage2, Images.onboardBackground2,
        'on_boarding_title_2'.tr,
        'withdraw_money_is_even_more'.tr,
      ),
      OnboardModel(
        Images.onboardImage3,
        Images.onboardBackground3,
        'on_boarding_title_3'.tr,
        '${'request_for_money_using'.tr} ${AppConstants.appName} ${'account_to_any_friend'.tr}',
      ),
    ];

  }


}