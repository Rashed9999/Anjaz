import 'package:azlistview/azlistview.dart';
import 'package:flutter/material.dart';
import 'package:flutter_contacts/flutter_contacts.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/common/widgets/custom_dialog_widget.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/favorite_number/domain/models/favorite_list_model.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/transaction_money/domain/enums/suggest_type_enum.dart';
import 'package:amyal_pay/features/transaction_money/domain/models/contact_tag_model.dart';
import 'package:amyal_pay/features/transaction_money/domain/reposotories/contact_repo.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';
import 'package:amyal_pay/features/transaction_money/widgets/contact_permission_allow_instruction_widget.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';

class ContactController extends GetxController implements GetxService {
  final ContactRepo contactRepo;
  ContactController({required this.contactRepo});

  List<ContactTagModel> filteredContacts = [];
  List<ContactTagModel> azItemList = [];
  List<ContactModel> _sendMoneySuggestList = [];
  List<ContactModel> _requestMoneySuggestList = [];
  List<ContactModel> _cashOutSuggestList = [];
  PermissionStatus? permissionStatus;
  ContactModel? _contact;
  bool _isFutureSave = false;
  bool _isLoading = false;
  bool _isContactListLoading = false;

  // Getters
  List<ContactModel> get sendMoneySuggestList => _sendMoneySuggestList;
  List<ContactModel> get requestMoneySuggestList => _requestMoneySuggestList;
  List<ContactModel> get cashOutSuggestList => _cashOutSuggestList;
  bool get isLoading => _isLoading;
  bool get isContactListLoading => _isContactListLoading;
  ContactModel? get contact => _contact;
  bool get isFutureSave => _isFutureSave;
  bool get getContactPermissionStatus => contactRepo.sharedPreferences.getString(AppConstants.contactPermission) != PermissionStatus.granted.name;

  Future<void> getContactList({
    bool forceReload = false,
    Function()? onSuccess,
    Function()? onDenied,
    bool ignoreDeniedStatus = false,
  }) async {
    if (getContactPermissionStatus && !GetPlatform.isIOS) {
      await _showPermissionDialog(onSuccess: onSuccess, onDenied: onDenied, ignoreDeniedStatus: ignoreDeniedStatus);

    } else {
      if (filteredContacts.isEmpty || forceReload) {
        await _loadContactData();
        onSuccess?.call();
      } else {
        if(await _requestContactPermission()) {
          onSuccess?.call();
        }


      }
    }
  }

  Future<void> _showPermissionDialog({
    Function()? onSuccess,
    Function()? onDenied,
    bool ignoreDeniedStatus = false,

  }) async {

    if(_isAllowToShowDialog(ignoreDeniedStatus)) {
      await Future.delayed(const Duration(milliseconds: 100));

      await Get.dialog(
        CustomDialogWidget(
          description: '${AppConstants.appName} ${'use_the_information_you'.tr}',
          descriptionWidget: ContactPermissionAllowInstructionWidget(),
          icon: Icons.question_mark,
          onTapFalse: () {
            _isLoading = false;
            Get.back();
            contactRepo.sharedPreferences.setBool(AppConstants.contactPermissionDeniedStatus, false);

            onDenied?.call();
          },
          onTapTrueText: 'continue'.tr,
          onTapFalseText: 'denied'.tr,
          onTapTrue: () {
            Get.back();
            _onContinue(onSuccess, onDenied);
          },
          title: 'contact_permission'.tr,
        ),
        barrierDismissible: false,
      );
    }


  }

  bool _isAllowToShowDialog(bool ignoreDeniedStatus) => ((contactRepo.sharedPreferences.getBool(AppConstants.contactPermissionDeniedStatus) ?? true) || ignoreDeniedStatus);

  Future<Null> _onContinue(Function()? onSuccess, Function()? onDenied) {
    return _loadContactData().then((_) async {
      if (permissionStatus == PermissionStatus.granted) {
        onSuccess?.call();
      } else {
        if(permissionStatus == PermissionStatus.denied) {
          onDenied?.call();
        }else {
          await openAppSettings();
          if(await _requestContactPermission()) {
            _onContinue(onSuccess, onDenied);
          }
        }
      }
    });
  }

  Future<void> _loadContactData() async {
    _isLoading = true;
    update();

    if (await _requestContactPermission()) {
      List<Contact> contacts = await FlutterContacts.getContacts(withProperties: true, withPhoto: false);
      _processContacts(contacts);
    }

    _isLoading = false;
    update();
  }

  Future<bool> _requestContactPermission() async {
    permissionStatus = await Permission.contacts.request();
    contactRepo.sharedPreferences.setString(
      AppConstants.contactPermission,
      permissionStatus!.name,
    );
    return permissionStatus == PermissionStatus.granted || GetPlatform.isIOS;
  }

  void _processContacts(List<Contact> contacts) {
    azItemList = contacts
        .where((contact) => contact.phones.isNotEmpty && contact.displayName.isNotEmpty)
        .map((contact) => ContactTagModel(
        favouriteModel: _getFavNumber(contact.phones.first.normalizedNumber.replaceAll('-', '').trim()),
        contact: contact,
        tag: contact.displayName[0].toUpperCase()))
        .toList();

    filteredContacts = azItemList;
    SuspensionUtil.setShowSuspensionStatus(azItemList);
    SuspensionUtil.setShowSuspensionStatus(filteredContacts);
  }

  FavouriteModel? _getFavNumber(String number) {
    if(Get.find<FavNumberController>().localFavouriteListModel != null) {
      return Get.find<FavNumberController>().localFavouriteListModel?.all?.firstWhereOrNull(
            (element) => element.phone == number,
      );
    }
    return null;
  }

  void onSearchContact({required String searchTerm}) {
    if (searchTerm.isNotEmpty) {
      filteredContacts = azItemList.where((element) {
        if (element.contact!.phones.isNotEmpty) {
          return element.contact!.displayName.toLowerCase().contains(searchTerm) ||
              element.contact!.phones.first.number.replaceAll('-', '').toLowerCase().contains(searchTerm);
        }
        return false;
      }).toList();
    } else {
      filteredContacts = azItemList;
    }
    update();
  }

  void onTapContact(int index, String? transactionType) {
    String phoneNumber = filteredContacts[index].contact!.phones.first.number.trim();
    if(phoneNumber.contains('-')) {
      phoneNumber = phoneNumber.replaceAll('-', '');
    }
    if(!phoneNumber.contains('+')) {
      phoneNumber = (Get.find<AuthController>().getUserData()!.countryCode! + phoneNumber.substring(1).trim());
    }
    if(phoneNumber.contains(' ')) {
      phoneNumber = phoneNumber.replaceAll(' ', '');
    }

    if(transactionType == "cash_out") {
      checkAgentNumber(phoneNumber: phoneNumber).then((value) {
        if(value.isOk) {
          String? agentName = value.body['data']['name'];
          String? agentImage = value.body['data']['image'];
          bool isFavNumber = value.body['data']['is_favourite'] ?? false;

          Get.to(() => TransactionBalanceInputScreen(
              transactionType: transactionType,
              contactModel: ContactModel(
                  phoneNumber: phoneNumber,
                  name: agentName,
                  avatarImage: agentImage,
                  isFavourite: isFavNumber
              )
          ));
        }
      });
    } else {
      checkCustomerNumber(phoneNumber: phoneNumber).then((value) {
        if(value!.isOk) {
          String? customerName = value.body['data']['name'];
          String? customerImage = value.body['data']['image'];
          bool isFavNumber = value.body['data']['is_favourite'] ?? false;
          Get.to(() => TransactionBalanceInputScreen(
              transactionType: transactionType,
              contactModel: ContactModel(
                  phoneNumber: phoneNumber,
                  name: customerName,
                  avatarImage: customerImage,
                  isFavourite: isFavNumber
              )
          ));
        }
      });
    }
  }

  Future<void> onTapSuggest(int index, String? transactionType) async {
    if(transactionType == 'send_money') {
      await checkCustomerNumber(phoneNumber: _sendMoneySuggestList[index].phoneNumber ?? '').then((value) {
        if(value!.isOk) {
          _contact = ContactModel(
            phoneNumber: _sendMoneySuggestList[index].phoneNumber,
            avatarImage: _sendMoneySuggestList[index].avatarImage,
            name: _sendMoneySuggestList[index].name,
            isFavourite: value.body['data']['is_favourite'] ?? false,
          );
        }
      });
    }
    else if(transactionType == 'request_money') {
      await checkCustomerNumber(phoneNumber: _requestMoneySuggestList[index].phoneNumber ?? '').then((value) {
        if(value!.isOk) {
          _contact = ContactModel(
            phoneNumber: _requestMoneySuggestList[index].phoneNumber,
            avatarImage: _requestMoneySuggestList[index].avatarImage,
            name: _requestMoneySuggestList[index].name,
            isFavourite: value.body['data']['is_favourite'] ?? false,
          );
        }
      });
    }
    else if(transactionType == 'cash_out') {
      await checkAgentNumber(phoneNumber: _cashOutSuggestList[index].phoneNumber ?? '').then((value) {
        if(value.isOk) {
          _contact = ContactModel(
            phoneNumber: _cashOutSuggestList[index].phoneNumber,
            avatarImage: _cashOutSuggestList[index].avatarImage,
            name: _cashOutSuggestList[index].name,
            isFavourite: value.body['data']['is_favourite'] ?? false,
          );
        }
      });
    }

    Get.to(() => TransactionBalanceInputScreen(
        transactionType: transactionType,
        contactModel: _contact,
    ));
  }

  void addToSuggestContact(ContactModel? contact, {required SuggestType type}) {
    if(contact != null) {
      switch(type) {
        case SuggestType.sendMoney:
          _sendMoneySuggestList.removeWhere((element) => element.phoneNumber == contact.phoneNumber);
          _sendMoneySuggestList.add(contact);
          contactRepo.addToSuggestList(_sendMoneySuggestList, type: type);
          break;

        case SuggestType.cashOut:
          _cashOutSuggestList.removeWhere((element) => element.phoneNumber == contact.phoneNumber);
          _cashOutSuggestList.add(contact);
          contactRepo.addToSuggestList(_cashOutSuggestList, type: type);
          break;

        case SuggestType.requestMoney:
          _requestMoneySuggestList.removeWhere((element) => element.phoneNumber == contact.phoneNumber);
          _requestMoneySuggestList.add(contact);
          contactRepo.addToSuggestList(_requestMoneySuggestList, type: type);
          break;
      }
    }
    update();
  }

  void onSaveChange(bool value) {
    _isFutureSave = value;
    update();
  }

  void getSuggestList({required String? type}) async {
    _cashOutSuggestList = [];
    _sendMoneySuggestList = [];
    _requestMoneySuggestList = [];

    try {
      if(type == AppConstants.sendMoney) {
        _sendMoneySuggestList.addAll(contactRepo.getRecentList(type: type)!);
      } else if(type == AppConstants.cashOut) {
        _cashOutSuggestList.addAll(contactRepo.getRecentList(type: type)!);
      } else {
        _requestMoneySuggestList.addAll(contactRepo.getRecentList(type: type)!);
      }
    } catch(error) {
      _cashOutSuggestList = [];
      _sendMoneySuggestList = [];
      _requestMoneySuggestList = [];
    }
  }

  Future<Response?> checkCustomerNumber({required String phoneNumber}) async {
    Response? response0;
    if(phoneNumber == Get.find<ProfileController>().userInfo!.phone) {
      showCustomSnackBarHelper('Please_enter_a_different_customer_number'.tr);
    } else {
      _isContactListLoading = true;
      update();
      Response response = await contactRepo.checkCustomerNumber(phoneNumber: phoneNumber);
      if(response.statusCode == 200) {
        _isContactListLoading = false;
      } else {
        _isContactListLoading = false;
        ApiChecker.checkApi(response);
      }
      update();
      response0 = response;
    }
    return response0;
  }

  Future<Response> checkAgentNumber({required String phoneNumber}) async {
    _isContactListLoading = true;
    update();
    Response response = await contactRepo.checkAgentNumber(phoneNumber: phoneNumber);
    if(response.statusCode == 200) {
      _isContactListLoading = false;
    } else {
      _isContactListLoading = false;
      ApiChecker.checkApi(response);
    }
    update();
    return response;
  }

  // Helper method for cleaner usage
  Future<void> withContactPermission({
    required Function() onSuccess,
    Function()? onDenied,
  }) async {
    await getContactList(
      onSuccess: onSuccess,
      onDenied: onDenied,
      ignoreDeniedStatus: true,
    );
  }
}

