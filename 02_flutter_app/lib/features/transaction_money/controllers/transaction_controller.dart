import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';
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

  /// AMIAL-PILOT-IDEM-001 — **مفتاحُ التفرّد لكلّ نيّةٍ بشريّة.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **الثمنُ الذي كاد يُدفع:** أُرسل الطلبُ نفسُه مرّتين إلى
  /// `customer/send-money` فوصل المستلِمَ ٢٠٠٠ والمبلغُ ١٠٠٠. وكان
  /// الخادمُ بلا وسيطِ تفرّدٍ على هذه المسارات، **والتطبيقُ لا يُرسل
  /// مفتاحاً أصلاً** — فالثغرةُ كانت في الطرفين معاً، وسدُّ أحدهما وحده
  /// لا يسدّ شيئاً.
  ///
  /// **ولماذا يُحفظ هنا لا يُولَّد عند كلّ نداء:**
  /// انقطاعُ الشبكة في اليمن ليس حالةً نادرة. ومن ضغط «إرسال» فانقطع
  /// الاتّصالُ **بعد** وصول الطلب وقبل وصول الردّ يرى فشلاً فيضغط ثانية.
  /// فلو وُلِّد مفتاحٌ جديدٌ لصار تحويلين. فيُولَّد مرّةً ويبقى حتّى
  /// **تنجح** العمليّة، ثمّ يُتلَف لتبدأ نيّةٌ جديدة بمفتاحها.
  /// ══════════════════════════════════════════════════════════════════
  final Map<String, String> _operationKeys = {};

  String _keyFor(String action) =>
      _operationKeys[action] ??= IdempotencyKeyGenerator.forFinancialAction(action);

  /// **ويُتلَف متى أجاب الخادم — لا عند النجاح وحده.**
  ///
  /// وهذا تصحيحٌ لصيغةٍ أولى كتبتُها تُبقي المفتاحَ على كلّ فشل. وكانت
  /// **تكسر التطبيق**: `EnforceIdempotency` يُسجّل المفتاحَ `failed` عند
  /// أيّ ٤xx ثمّ يردّ على إعادته `409 IDEMPOTENCY_FAILED_PREVIOUSLY`. فمن
  /// أخطأ رمزَه السرّيّ مرّةً لا يستطيع إعادةَ المحاولة أبداً بمفتاحه.
  ///
  /// فالتمييزُ ليس بين نجاحٍ وفشل، بل بين **جوابٍ وصمت**:
  ///   · أجاب الخادمُ ولو بالرفض ⇒ النيّةُ حُسمت، والمفتاحُ يُتلَف.
  ///   · لم يصل جوابٌ (`statusCode` ٠ أو ١ أو −١) ⇒ **لا نعلم أوصل الطلبُ
  ///     أم لا**، فيبقى المفتاح لتكون الإعادةُ إعادةً لا تحويلاً ثانياً.
  ///
  /// و«لا نعلم» هنا ليست صفراً (القاعدة السابعة): الجهلُ يُحتاط له، ولا
  /// يُقرأ نجاحاً ولا فشلاً.
  void _settleKey(String action, Response response) {
    final int code = response.statusCode ?? 0;
    if (code > 1) {
      _operationKeys.remove(action);
    }
  }

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
   Response response = await transactionRepo.sendMoneyApi(phoneNumber: contactModel.phoneNumber, amount: amount, purpose: purpose, pin: pinCode, idempotencyKey: _keyFor('send_money'));
   _settleKey('send_money', response);
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
    Response response = await transactionRepo.requestMoneyApi(phoneNumber: contactModel.phoneNumber, amount: amount, idempotencyKey: _keyFor('request_money'));
    _settleKey('request_money', response);
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
    Response response = await transactionRepo.cashOutApi(phoneNumber: contactModel.phoneNumber, amount: amount,pin: pinCode, idempotencyKey: _keyFor('cash_out'));
    _settleKey('cash_out', response);
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

    Response response = await transactionRepo.withdrawRequest(placeBody: placeBody, idempotencyKey: _keyFor('withdraw_request'));

    _settleKey('withdraw_request', response);

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