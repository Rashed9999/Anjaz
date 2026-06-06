
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/history/domain/models/dispute_reason_model.dart';
import 'package:amyal_pay/features/history/domain/reposotories/report_dispute_repo.dart';

class ReportDisputeController extends GetxController implements GetxService {
  final  ReportDisputeRepo reportDisputeRepo;
  ReportDisputeController({required this.reportDisputeRepo});


  List<DisputeReasonModel>? _disputeReasonList;
  List<DisputeReasonModel>? get disputeReasonList => _disputeReasonList;

  final List<int> _selectedReasonIds = [];
  List<int?> get selectedReasonIds => _selectedReasonIds;

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  String? _disputeComment;
  String? get disputeComment => _disputeComment;


  Future<void> getReasonList() async {
    _disputeReasonList = null;
    update();
    Response response = await reportDisputeRepo.getReasonList();

    if (response.statusCode == 200 && response.body != null) {
      _disputeReasonList = [];
      response.body.forEach((reason) {
        _disputeReasonList?.add(DisputeReasonModel.fromJson(reason));
      });


    } else {
      ApiChecker.checkApi(response);
    }

    update();
  }


  void onUpdateReasonSelect(int? id, {bool isUpdate = true, bool isRemoveAll = false}) {
    if(isRemoveAll){
      _selectedReasonIds.clear();
      return;
    }
    if(_selectedReasonIds.contains(id)){
      _selectedReasonIds.remove(id);

    } else{
      if(id != null) {
        _selectedReasonIds.add(id);

      }
    }

    if(isUpdate){
      update();
    }
  }

  Future<bool> createReportDispute({required String pin, required String? transactionId, String? comment}) async {
    _isLoading = true;
    update();
    Response response = await reportDisputeRepo.create(pin: pin, reasonIds: _selectedReasonIds, comment: comment, transactionId: transactionId);

    if (response.isOk) {
      await Get.find<TransactionHistoryController>().getTransactionData(
        1, transactionType: Get.find<TransactionHistoryController>().transactionModel?.transactionType,
      );

    } else {
      ApiChecker.checkApi(response);
    }

    _isLoading = false;
    update();

    return response.isOk;

  }

  void onUpdateDisputeComment(String? comment, {bool isUpdate = true}) {
    _disputeComment = comment;
    if(isUpdate) {
      update();
    }
  }





}