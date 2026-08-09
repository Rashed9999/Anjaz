import 'package:get/get_connect/http/src/response/response.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';

class RequestedMoneyRepo{
  final ApiClient apiClient;

  RequestedMoneyRepo({required this.apiClient});

  Future<Response> getRequestedMoneyList() async {
    return await apiClient.getData(AppConstants.requestedMoneyUri);
  }
  Future<Response> getOwnRequestedMoneyList() async {
    return await apiClient.getData(AppConstants.wonRequestedMoney);
  }
  Future<Response> approveRequestedMoney(int? id, String pin) async {
    return await apiClient.postData(AppConstants.acceptedRequestedMoneyUri,{"id": id, "pin" :pin});
  }
  Future<Response> denyRequestedMoney(int? id, String pin) async {
    return await apiClient.postData(AppConstants.deniedRequestedMoneyUri,{"id": id, "pin" :pin});
  }
  /// AMIAL-REQ-DIRECT-001 — **اطلب من شخصٍ بهاتفه**.
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// نقطةُ النهاية `POST /api/v1/customer/request-money` كانت **مبنيّةً
  /// وحيّةً ولا ينادي عليها أحد**: الثابت `customerRequestMoney` مكتوبٌ في
  /// `app_constants.dart` منذ البداية، ولا سطرَ واحدٌ يستعمله.
  ///
  /// وزرُّ «طلب مال» كان يقود إلى إنشاء رابطٍ ورمزِ QR — وهو مسارُ تاجرٍ
  /// لا مسارُ شخصٍ يطلب من صديقه. فصار الطلبُ المباشر هو الأصل، والرابطُ
  /// خياراً ثانياً لمن يريد المشاركة.
  Future<Response> requestFromPerson({
    required String phone,
    required String amount,
    String? note,
  }) async {
    return await apiClient.postData(AppConstants.customerRequestMoney, {
      'phone': phone,
      'amount': amount,
      if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
    });
  }

  Future<Response> getWithdrawRequest() async {
    return await apiClient.getData(AppConstants.getWithdrawalRequest);
  }
}