import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/transaction_money/domain/models/contact_tag_model.dart';
import 'package:amyal_pay/features/transaction_money/domain/models/purpose_model.dart';
import 'package:amyal_pay/features/transaction_money/domain/models/withdraw_model.dart';
import 'package:amyal_pay/features/transaction_money/domain/reposotories/transaction_repo.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_confirmation_screen.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/helper/route_helper.dart';

class TransactionMoneyController extends GetxController implements GetxService {
  final TransactionRepo transactionRepo;
  final AuthRepo authRepo;
  TransactionMoneyController({required this.transactionRepo, required this.authRepo});


  List<ContactTagModel> filteredContacts = [];
  List<ContactTagModel> azItemList = [];
  List<PurposeModel>? _purposeList;
  PurposeModel? _selectedPurpose;
  int _selectItem = 0;
  bool _isLoading = false;
  bool _isNextBottomSheet = false;
  PermissionStatus? permissionStatus;
  final String _searchControllerValue = '';
  double? _inputAmountControllerValue;
  WithdrawModel? _withdrawModel;

  List<PurposeModel>? get purposeList => _purposeList;
  PurposeModel? get selectedPurpose => _selectedPurpose;
  int get selectedItem => _selectItem;
  String get searchControllerValue => _searchControllerValue;
  double? get inputAmountControllerValue => _inputAmountControllerValue;
  bool get isLoading => _isLoading;
  bool get isNextBottomSheet => _isNextBottomSheet;
  String? _pin;
  String? get pin => _pin;
  WithdrawModel? get withdrawModel => _withdrawModel;


  Future<void> getPurposeList(bool reload, {bool isUpdate = true})async{
    if(_purposeList == null || reload){
      _purposeList = null;
      _isLoading = true;
      if(isUpdate){
        update();
      }
    }

    if(_purposeList == null){
      Response response = await transactionRepo.getPurposeListApi();

      if(response.body != null && response.statusCode == 200){
        _purposeList = [];
        var data =  response.body.map((a)=> PurposeModel.fromJson(a)).toList();
        for (var purpose in data) {
          _purposeList?.add( PurposeModel(title: purpose.title,logo: purpose.logo, color: purpose.color));
        }
        _selectedPurpose = _purposeList!.isEmpty ? PurposeModel() : _purposeList![0];
      }else{
        ApiChecker.checkApi(response);
      }
      _isLoading = false;
      update();
    }

  }

  Future<Response> sendMoney({required ContactModel contactModel, required double amount, String? purpose, String? pinCode, required Function onSuggest}) async{

    _isLoading = true;
    _isNextBottomSheet = false;
    update();
   Response response = await transactionRepo.sendMoneyApi(phoneNumber: contactModel.phoneNumber, amount: amount, purpose: purpose, pin: pinCode);
   if(response.statusCode == 200){
     _isLoading = false;
     _isNextBottomSheet = true;

     onSuggest();

   }else{
     _isLoading = false;
     ApiChecker.checkApi(response);
   }
   update();
   return response;
  }


  Future<Response> requestMoney({required ContactModel contactModel, required double amount, required Function onSuggest})async{
    _isLoading = true;
    _isNextBottomSheet = false;
    update();
    Response response = await transactionRepo.requestMoneyApi(phoneNumber: contactModel.phoneNumber, amount: amount);
    if(response.statusCode == 200){
      _isLoading = false;
      _isNextBottomSheet = true;

      onSuggest();

    }else{
      _isLoading = false;
      ApiChecker.checkApi(response);
    }
    update();
    return response;
  }


  Future<Response> cashOutMoney({required ContactModel contactModel, required double amount, String? pinCode, required Function? onSuggest})async{
    _isLoading = true;
    _isNextBottomSheet = false;
    update();
    Response response = await transactionRepo.cashOutApi(phoneNumber: contactModel.phoneNumber, amount: amount,pin: pinCode);
    if(response.statusCode == 200){
      _isLoading = false;
      _isNextBottomSheet = true;

      if(onSuggest != null) {
        onSuggest();
      }


    }else{
      _isLoading = false;
      ApiChecker.checkApi(response);
    }
    update();
    return response;
  }





  void itemSelect({required int index}){
    _selectItem = index;
    _selectedPurpose = purposeList![index];

    update();
  }

  ContactModel? _contact;
  ContactModel? get contact => _contact;

  void balanceConfirmationOnTap({double? amount, String? transactionType, String? purpose, ContactModel? contactModel}) {
    Get.to(()=> TransactionConfirmationScreen(
        inputBalance: amount, transactionType: transactionType, purpose: purpose,contactModel: contactModel));
  }


  void changeTrueFalse(){
    _isNextBottomSheet = false;
  }

  Future<bool> pinVerify({required String pin})async{
    bool isVerify = false;
    _isLoading = true;
     update();
    final Response response =  await authRepo.pinVerifyApi(pin: pin);
    if(response.statusCode == 200){
      isVerify = true;
      _isLoading = false;
    }else{
      ApiChecker.checkApi(response);
    }
    _isLoading = false;
    update();
    return isVerify;
  }


  Future<bool?> getBackScreen()async{
    Get.offAndToNamed(RouteHelper.navbar, arguments: false);
    return null;
  }

  Future<void> getWithdrawMethods({bool isReload = false}) async{
    if(_withdrawModel == null || isReload) {
      Response response = await transactionRepo.getWithdrawMethods();

      if(response.body['response_code'] == 'default_200' && response.body['content'] != null) {
        _withdrawModel = WithdrawModel.fromJson(response.body);
      }else{
        _withdrawModel = WithdrawModel(withdrawalMethods: [],);
        ApiChecker.checkApi(response);
      }
      update();
    }

  }

  Future<void> withDrawRequest({Map<String, String?>? placeBody})async{

    final ProfileController profileController = Get.find<ProfileController>();
    _isLoading = true;

    Response response = await transactionRepo.withdrawRequest(placeBody: placeBody);

    if(response.statusCode == 200 && response.body['response_code'] == 'default_store_200'){

      profileController.getProfileData(reload: true);

      Get.offAllNamed(RouteHelper.getNavBarRoute());
      showCustomSnackBarHelper('request_send_successful'.tr, isError: false);
    }else{

      showCustomSnackBarHelper(response.body['message'] ?? 'error', isError: true);
    }
    _isLoading = false;
    update();

  }


}