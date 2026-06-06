import 'dart:typed_data';

import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/history/domain/models/transaction_model.dart';
import 'package:amyal_pay/features/history/domain/reposotories/transaction_history_repo.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/helper/date_converter_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';

class TransactionHistoryController extends GetxController implements GetxService{
  final TransactionHistoryRepo transactionHistoryRepo;
  TransactionHistoryController({required this.transactionHistoryRepo});

  int? _pageSize;
  bool _isLoading = false;

  int _transactionTypeIndex = 0;
  int get transactionTypeIndex => _transactionTypeIndex;
  bool _showReportForm = false;
  bool get showReportForm => _showReportForm;

  TransactionModel? _transactionModel;
  TransactionModel? get transactionModel => _transactionModel;

  List<String> transactionType = ['all', 'send_money', 'cash_in', 'add_money', 'received_money', 'cash_out', 'withdraw', 'payment', AppConstants.addDisputedMoney, AppConstants.deductDisputedMoney];

  String? _selectedDateRange;
  String? get selectedDateRange => _selectedDateRange;
  DateTime? _startDate;
  DateTime? get startDate => _startDate;
  DateTime? _endDate;
  DateTime? get endDate => _endDate;
  String? _filterTransactionType;
  String? get filterTransactionType => _filterTransactionType;
  List<Transactions>? _recentTransactionList;
  List<Transactions>? get recentTransactionList => _recentTransactionList;




  int? get pageSize => _pageSize;
  bool get isLoading => _isLoading;

  void showBottomLoader() {
    _isLoading = true;
    update();
  }


  Future<void> getRecentTransactionList({int offset = 1, bool isUpdate = true}) async {

    if(offset == 1) {
      _recentTransactionList = null;

      if(isUpdate) {
        update();
      }
    }

    Response response = await transactionHistoryRepo.getTransactionHistory(offset);

    if(response.statusCode == 200 && response.body != null) {

      _recentTransactionList = TransactionModel.fromJson(response.body).transactions;

    }else {
      ApiChecker.checkApi(response);
    }

    update();
  }


  Future<void> getTransactionData(int offset, {bool reload = false, String? transactionType = "all", String? balanceType, DateTime? startDate, DateTime? endDate}) async{
    if(reload || offset == 1) {
      _transactionModel = null;
      update();
    }

    Response response = await transactionHistoryRepo.getTransactionHistory(
      offset,
      transactionType: transactionType,
      balanceType: balanceType,
      startDate: startDate,
      endDate: endDate,
    );

    if(response.statusCode == 200 && response.body != null){

      if(offset == 1){
        _transactionModel =  TransactionModel.fromJson(response.body);
      }else{
        _transactionModel?.totalSize = TransactionModel.fromJson(response.body).totalSize;
        _transactionModel?.offset = TransactionModel.fromJson(response.body).offset;
        _transactionModel?.balanceType = TransactionModel.fromJson(response.body).balanceType;
        _transactionModel?.startDate = TransactionModel.fromJson(response.body).startDate;
        _transactionModel?.endDate = TransactionModel.fromJson(response.body).endDate;
        _transactionModel?.transactions?.addAll(TransactionModel.fromJson(response.body).transactions ?? []);
      }
    }else{
      ApiChecker.checkApi(response);
    }

    update();
  }

  Future<Uint8List?> downloadTransactionHistory({String transactionType = "all", String? balanceType, DateTime? startDate, DateTime? endDate}) async {

    if((_transactionModel?.totalSize ?? 0) < 1) {
      return null;
    }

    _isLoading = true;
    update();

    Uint8List? pdfBytes;
    Response response = await transactionHistoryRepo.downloadTransactionHistory(transactionType: transactionType, balanceType: balanceType, startDate: startDate, endDate: endDate);

    if(response.statusCode == 200 && response.body != null) {
      pdfBytes = Uint8List.fromList(response.body!.codeUnits);

    }else {
      ApiChecker.checkApi(response);
    }

    if(pdfBytes?.isEmpty ?? true) return null;

    _isLoading = false;
    update();

    return pdfBytes;
  }

  void setIndex(int index, {bool reload = true}) {
    _transactionTypeIndex = index;
    if(reload){
      update();
    }
  }

  void updateShowReportForm(bool value, {bool isUpdate = true}){
    _showReportForm = value;
    if(isUpdate){
      update();
    }
  }

  void setSelectedDate({required DateTime? startDate, required DateTime? endDate, bool isUpdate = true}) {
    _startDate = startDate;
    _endDate = endDate;
    if(isUpdate){
      update();
    }
  }




  void updateDateRange(String? value, {bool isUpdate = true}){
    _selectedDateRange = value;

    if((value?.isNotEmpty ?? false) && value != 'custom'){
      final dateRange = DateConverterHelper.getDateRangeForFilter(value);
      setSelectedDate(startDate: dateRange['startDate'], endDate: dateRange['endDate'], isUpdate: false);
    }else if((value?.isNotEmpty ?? false) && value == 'custom'){
      setSelectedDate(startDate: null, endDate: null, isUpdate: true);
    }

    if(isUpdate){
      update();
    }
  }

  void updateFilterTransactionType(String? value, {bool isUpdate = true}){
    _filterTransactionType = value;
    if(isUpdate){
      update();
    }
  }


  void initFilterData(){
    _filterTransactionType = _transactionModel?.balanceType ?? AppConstants.transactionTypeList.first;
    if(_transactionModel?.startDate != null && _transactionModel?.endDate != null){
      _startDate = _transactionModel?.startDate;
      _endDate = _transactionModel?.endDate;
    } else{
      final dateRange = DateConverterHelper.getDateRangeForFilter(selectedDateRange);
      setSelectedDate(startDate: dateRange['startDate'], endDate: dateRange['endDate'], isUpdate: false);
    }
  }

  void resetFilter() {
    updateFilterTransactionType(null);
    updateDateRange(AppConstants.filterDateRangeList.first);
    setSelectedDate(startDate: null, endDate: null);
  }
  void onClearTransactionModel() {
    _transactionModel = null;
  }

}