import 'dart:typed_data';

import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/features/history/domain/models/transaction_model.dart';
import 'package:amial_pay/features/history/domain/reposotories/transaction_history_repo.dart';
import 'package:get/get.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/util/app_constants.dart';

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

  /// آخرُ سببِ فشلٍ للتنزيل — **يُقرأ ولا يُخمَّن**.
  ///
  /// كانت الدالّة تردّ `null` في ثلاث حالاتٍ مختلفة، فتقرؤها الشاشةُ
  /// كلَّها «لا توجد عمليات».
  String downloadError = '';

  /// AMIAL-STATEMENT-FIX-001 — تنزيلُ كشف الحساب.
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **الثمن الذي دُفع:** شاشةُ التقارير تعرض «١٩٨٬١٠٠ إيرادات · ٢٠ عملية»
  /// ثمّ يظهر شريطٌ أحمر: **«لا توجد عمليات في هذه الفترة»**.
  ///
  /// والسببُ سطرٌ واحد كان هنا:
  ///
  ///     if ((_transactionModel?.totalSize ?? 0) < 1) return null;
  ///
  /// **وهو يسأل متحكّماً آخرَ عن حالةٍ لم تُحمَّل قطّ.** فشاشةُ التقارير
  /// تجلب عمليّاتِها بنفسها ولا تمرّ بـ`getTransactionData`، فيبقى
  /// `_transactionModel` فارغاً — ويُرفض التنزيل قبل أن يُرسَل طلبٌ واحد.
  ///
  /// **ولا خطأ في أيّ سجلّ**: الردُّ `null`، والشاشةُ تُترجمه «لا عمليات»
  /// وأمامها عشرون عمليّة.
  ///
  /// فصار القرارُ من **ردّ الخادم** لا من حالةِ متحكّمٍ آخر، والفشلُ
  /// يُفرَّق عن الفراغ: من عطلت شبكتُه يرى شيئاً غيرَ ما يراه من لا
  /// عمليّات له.
  Future<Uint8List?> downloadTransactionHistory({String transactionType = "all", String? balanceType, DateTime? startDate, DateTime? endDate}) async {
    downloadError = '';
    _isLoading = true;
    update();

    try {
      final Response response = await transactionHistoryRepo.downloadTransactionHistory(
        transactionType: transactionType,
        balanceType: balanceType,
        startDate: startDate,
        endDate: endDate,
      );

      if (response.statusCode != 200 || response.body == null) {
        // **الشبكةُ المقطوعة ليست فراغَ بيانات.**
        downloadError = (response.statusCode == null || response.statusCode == 0)
            ? 'لا اتصال بالخادم — تحقّق من الشبكة'
            : 'تعذّر تنزيل الكشف من الخادم';
        ApiChecker.checkApi(response);
        return null;
      }

      final bytes = Uint8List.fromList(response.body!.codeUnits);

      if (bytes.isEmpty) {
        // ملفٌّ فارغ: الخادمُ ردّ ٢٠٠ بلا محتوى — عطلٌ لا فراغُ بيانات.
        downloadError = 'وصل ملفٌّ فارغ من الخادم — أعد المحاولة';
        return null;
      }

      return bytes;
    } catch (_) {
      downloadError = 'تعذّر تنزيل الكشف — تحقّق من الشبكة';
      return null;
    } finally {
      // **وكان يبقى `true` عند كلّ خروجٍ مبكّر** — فيعلق الزرّ في
      // «جارٍ التنزيل» إلى أن تُغلق الشاشة.
      _isLoading = false;
      update();
    }
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