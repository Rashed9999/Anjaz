
import 'package:get/get_connect/http/src/response/response.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/util/app_constants.dart';

class ProfileRepo {
  final ApiClient apiClient;
  final SharedPreferences sharedPreferences;
  ProfileRepo( {required this.apiClient, required this.sharedPreferences,});


  Future<Response>  getProfileDataApi() async {
    return await apiClient.getData(AppConstants.customerProfileInfo);
  }

  Future<Response>  getPurposeListApi() async {
    return await apiClient.getData(AppConstants.customerPurposeUrl );
  }

  Future<Response>  pinVerifyApi({required String? pin}) async {
    Map<String, Object?> body = {'pin': pin};
    return await apiClient.postData(AppConstants.customerPinVerify,body);
  }

  Future<Response>  changePinApi({required String oldPin,required String newPin,required String confirmPin}) async {
    Map<String, Object> body = {'old_pin': oldPin, 'new_pin': newPin, 'confirm_pin':confirmPin};
    return await apiClient.postData(AppConstants.customerChangePin,body);
  }

  Future<Response>  updateTwoFactorApi() async {
    Map<String, Object> body = {};
    return await apiClient.postData(AppConstants.customerUpdateTwoFactor,body);
  }

  Future<void> toggleUserBalanceShowingStatus(bool isHide) async {
    sharedPreferences.setBool(AppConstants.hideUserBalance, isHide);
  }

  bool isUserBalanceHide() {
    return sharedPreferences.getBool(AppConstants.hideUserBalance) ?? true;
  }





}