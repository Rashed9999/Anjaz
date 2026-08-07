
import 'package:get/get_connect/http/src/response/response.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/util/app_constants.dart';

class TransactionHistoryRepo{
  final ApiClient apiClient;

  TransactionHistoryRepo({required this.apiClient});

  Future<Response> getTransactionHistory(int offset, {String? transactionType, String? balanceType, DateTime? startDate, DateTime? endDate}) async {
    final Map<String, dynamic> queryParams = {
      'offset': '$offset',
      'limit': '10',
      if (transactionType != null && transactionType.isNotEmpty) 'transaction_type': transactionType,
      if (balanceType != null && balanceType.isNotEmpty) 'balance_type': balanceType,
      if (startDate != null) 'start_date': DateConverterHelper.formatDate(startDate),
      if (endDate != null) 'end_date': DateConverterHelper.formatDate(endDate),
    };

    final queryString = Uri(queryParameters: queryParams).query;
    if(queryString.isNotEmpty){
      return await apiClient.getData('${AppConstants.customerTransactionHistory}?$queryString');
    }

    return await apiClient.getData(AppConstants.customerTransactionHistory);
  }



  Future<Response> downloadTransactionHistory({String? transactionType, String? search, String? balanceType, DateTime? startDate, DateTime? endDate}) async {
    final Map<String, dynamic> queryParams = {
      if (transactionType != null && transactionType.isNotEmpty) 'transaction_type': transactionType,
      if (search != null && search.isNotEmpty) 'search': search,
      if (balanceType != null && balanceType.isNotEmpty) 'balance_type': balanceType,
      if (startDate != null) 'start_date': DateConverterHelper.formatDate(startDate),
      if (endDate != null) 'end_date': DateConverterHelper.formatDate(endDate),
    };

    final queryString = Uri(queryParameters: queryParams).query;
    if(queryString.isNotEmpty){
      return await apiClient.getData('${AppConstants.customerTransactionHistoryDownload}?$queryString');
    }

    return await apiClient.getData(AppConstants.customerTransactionHistoryDownload);
  }
}