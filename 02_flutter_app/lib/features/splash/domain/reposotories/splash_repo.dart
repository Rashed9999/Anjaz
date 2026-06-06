import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/util/app_constants.dart';

class SplashRepo {
  ApiClient apiClient;
  final SharedPreferences sharedPreferences;
  SplashRepo({required this.sharedPreferences, required this.apiClient});

  Future<Response> getConfigData() async {
    Response response = await apiClient.getData(AppConstants.configUri);
    return response;
  }

  Future<bool> initSharedData() {
    if(!sharedPreferences.containsKey(AppConstants.theme)) {
      return sharedPreferences.setBool(AppConstants.theme, false);
    }
    if(!sharedPreferences.containsKey(AppConstants.customerCountryCode)) {
      return sharedPreferences.setString(AppConstants.customerCountryCode, AppConstants.languages[0].countryCode!);
    }
    if(!sharedPreferences.containsKey(AppConstants.languageCode)) {
      return sharedPreferences.setString(AppConstants.languageCode, AppConstants.languages[0].languageCode!);
    }
    if(!sharedPreferences.containsKey(AppConstants.sendMoneySuggestList)) {
      return sharedPreferences.setString(AppConstants.sendMoneySuggestList, '');
    }
    if(!sharedPreferences.containsKey(AppConstants.requestMoneySuggestList)) {
      return sharedPreferences.setString(AppConstants.requestMoneySuggestList, '');
    }
    if(!sharedPreferences.containsKey(AppConstants.recentAgentList)) {
      return sharedPreferences.setString(AppConstants.recentAgentList, '');
    }
    if(!sharedPreferences.containsKey(AppConstants.biometricAuth)) {
      sharedPreferences.setBool(AppConstants.biometricAuth, true);
    }
    if(!sharedPreferences.containsKey(AppConstants.hideUserBalance)) {
      sharedPreferences.setBool(AppConstants.hideUserBalance, false);
    }
    if(!sharedPreferences.containsKey(AppConstants.showTourWidget)) {
      sharedPreferences.setBool(AppConstants.showTourWidget, true);
    }

    if(!sharedPreferences.containsKey(AppConstants.showWelcomeBottomSheet)) {
      sharedPreferences.setBool(AppConstants.showWelcomeBottomSheet, true);
    }

    if(!sharedPreferences.containsKey(AppConstants.contactPermission)) {
      sharedPreferences.setString(AppConstants.contactPermission, '');
    }


    return Future.value(true);
  }

  void removeSharedData() {
    String ? contactPermission = sharedPreferences.getString(AppConstants.contactPermission);
    sharedPreferences.clear();
    sharedPreferences.setString(AppConstants.contactPermission, contactPermission ?? "");
  }
}
