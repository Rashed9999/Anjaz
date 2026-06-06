import 'package:get/get.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/favorite_number/domain/enums/fav_type.dart';
import 'package:amyal_pay/features/favorite_number/domain/models/favorite_list_model.dart';
import 'package:amyal_pay/features/favorite_number/domain/repositories/fav_number_repo.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';

class FavNumberController extends GetxController implements GetxService{
  final FavNumberRepo favNumberRepo;
  FavNumberController({required this.favNumberRepo});

  FavType _selectedType = FavType.fAndF;
  FavType get selectedType => _selectedType;
  bool _isLoading = false;
  bool get isLoading => _isLoading;

  FavouriteListModel? _favouriteListModel;
  FavouriteListModel? get favouriteListModel => _favouriteListModel;

  FavouriteListModel? _localFavouriteListModel;
  FavouriteListModel? get localFavouriteListModel => _localFavouriteListModel;



  void updateSelectedFavType(FavType favType, {bool isUpdate = true}){
    _selectedType = favType;
    if(isUpdate){
      update();
    }
  }

  Future<bool> addFavoriteNumber({required FavType type, required String phoneNumber, required String name}) async {
    _isLoading = true;
    update();

    final response = await favNumberRepo.add(type: type.toValueString, phoneNumber: phoneNumber, name: name);

    if(response.isOk) {
     await getList();
     Get.find<ContactController>().getContactList(forceReload: true);

    } else {
      ApiChecker.checkApi(response);
    }

    _isLoading = false;
    update();

    return response.isOk;
  }

  Future<bool> updateFavoriteNumber(int? id, {FavType? type, String? phoneNumber, String? name}) async {
    _isLoading = true;
    update();

    final response = await favNumberRepo.update(id, type: type.toValueString, phoneNumber: phoneNumber, name: name);

    if(response.isOk) {
      await getList();
      Get.find<ContactController>().getContactList(forceReload: true);


    } else {
      ApiChecker.checkApi(response);
    }

    _isLoading = false;
    update();

    return response.isOk;
  }

  Future<bool> deleteFavoriteNumber(int? id) async {
    _isLoading = true;
    update();

    final response = await favNumberRepo.delete(id);
    Get.back();

    if(response.isOk) {
      await getList();
      Get.find<ContactController>().getContactList(forceReload: true);


    } else {
      ApiChecker.checkApi(response);
    }

    _isLoading = false;
    update();

    return response.isOk;
  }

  Future<void> getList({bool isUpdate = true, bool isReload = true, String? searchBy}) async {

    if(isReload) {
      _favouriteListModel = null;
    }

    if(isUpdate) {
      update();
    }

    if(_favouriteListModel == null) {
      final response = await favNumberRepo.getList(searchBy: searchBy);

      if(response.isOk) {
        _favouriteListModel = FavouriteListModel.fromJson(response.body);

        _addToLocalStorage(_favouriteListModel);

      } else {
        ApiChecker.checkApi(response);
      }
      update();
    }


  }


  Future<void> _addToLocalStorage(FavouriteListModel? value) async {
     await favNumberRepo.addToLocalStorage(value: value?.toJson());
     getFromLocalStorage();
  }

  void getFromLocalStorage() {
    final Map<String, dynamic>? data = favNumberRepo.getFromLocalStorage();

    if(data != null && data.isNotEmpty) {
      _localFavouriteListModel = FavouriteListModel.fromJson(data);
    }

  }


  void checkoutToTransaction(FavouriteModel? item, String? transactionType){
    String phoneNumber = item?.phone?.trim() ?? '';


    if(transactionType == "cash_out"){
      Get.find<ContactController>().checkAgentNumber(phoneNumber: phoneNumber).then((value) {
        if(value.isOk){
          String? agentName = value.body['data']['name'];
          String? agentImage = value.body['data']['image'];

          Get.to(()=> TransactionBalanceInputScreen(
            transactionType: transactionType,
            contactModel: ContactModel(phoneNumber: phoneNumber,name: agentName,avatarImage: agentImage, isFavourite: true),
          ));
        }
      });
    }else{
      Get.find<ContactController>().checkCustomerNumber(phoneNumber: phoneNumber).then((value) {
        if(value!.isOk){
          String? customerName = value.body['data']['name'];
          String? customerImage = value.body['data']['image'];
          Get.to(()=> TransactionBalanceInputScreen(
            transactionType: transactionType,
            contactModel: ContactModel(phoneNumber: phoneNumber,name: customerName,avatarImage: customerImage, isFavourite: true),
          ));
        }
      });
    }
  }


  bool isFavouriteNumber(String? phoneNumber) {
    final bool ableToCheck = (Get.find<SplashController>().configModel?.isFavoriteNumberActive ?? false) && _favouriteListModel != null && (phoneNumber?.isNotEmpty ?? false);

    if(ableToCheck) {
      final found = _favouriteListModel!.all?.any((fav) =>
      fav.phone?.trim() == phoneNumber?.trim()
      ) ?? false;

      if (found) return true;
    }

    return false;
  }






}