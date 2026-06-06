
import 'package:get/get_connect/http/src/response/response.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/util/app_constants.dart';

class ReportDisputeRepo{
  final ApiClient apiClient;
  ReportDisputeRepo({required this.apiClient});

  Future<Response> getReasonList() async => await apiClient.getData(AppConstants.reportReasonList);

  Future<Response> create({required String pin, required String? transactionId, required List<int> reasonIds,  String? comment}) async {

    final Map<String, dynamic> placeBody = {
      'pin': pin,
      'transaction_id' : transactionId ?? '',
    };

    if(comment?.isNotEmpty ?? false) {
      placeBody['comment'] = comment!;
    }

    if(reasonIds.isNotEmpty) {
      placeBody['dispute_reason_id'] = reasonIds;
    }

    return await apiClient.postData(AppConstants.createReportDispute, placeBody);
  }








}