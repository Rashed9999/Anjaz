import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_contacts/flutter_contacts.dart';
import 'package:get/get.dart';
import 'package:google_mlkit_barcode_scanning/google_mlkit_barcode_scanning.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/transaction_money/domain/models/contact_tag_model.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';

class QrCodeScannerController extends GetxController implements GetxService{

  bool _isBusy = false;
  bool _isDetect = false;

  String? _name;
  String? _phone;
  String? _type;
  String? _image;
  String? _addFavNumberRouteName;
  ContactTagModel? _scannedContact;


  String? get name => _name;
  String? get phone => _phone;
  String? get type => _type;
  String? get image => _image;
  String? _transactionType;
  String? get transactionType => _transactionType;
  ContactTagModel? get scannedContact => _scannedContact;



  Future<void> processImage(InputImage inputImage,  bool isHome, String? transactionType, {bool fromSearchContact = false, required Function? callBack}) async {
    final BarcodeScanner barcodeScanner = BarcodeScanner();
    if (_isBusy) return;
    _isBusy = true;

    final barcodes = await barcodeScanner.processImage(inputImage);
    if (inputImage.metadata?.size != null &&
        inputImage.metadata?.rotation != null) {
      for (final barcode in barcodes) {
        _name = jsonDecode(barcode.rawValue!)['name'];
        _phone = jsonDecode(barcode.rawValue!)['phone'];
        _type = jsonDecode(barcode.rawValue!)['type'];
        _image = jsonDecode(barcode.rawValue!)['image'];

        if(_name != null && _phone != null && _type != null && _image != null) {

          if(_type == "customer"){
            _transactionType = transactionType;

          }else if(_type == "agent"){
            _transactionType = "cash_out";
          }

          final bool isFavNumber = Get.find<FavNumberController>().isFavouriteNumber(_phone);

          if(isHome && _type != "agent"){
            if(!_isDetect) {
              Get.defaultDialog(
                title: 'select_a_transaction'.tr,
                content: TransactionSelect(contactModel: ContactModel(
                  phoneNumber: _phone,
                  name: _name,
                  avatarImage: _image,
                  isFavourite: isFavNumber,
                )),
                barrierDismissible: false,
                radius: Dimensions.radiusSizeDefault,
              ).then((value) {
                _isDetect = false;
              });
            }
            _isDetect = true;

          } else if(fromSearchContact){
            if(_scannedContact == null) {

              _scannedContact = ContactTagModel(favouriteModel: null, contact: Contact(displayName: _name ?? '', phones: [Phone(_phone ?? '')]), tag: (_name ?? '')[0]);
              Get.until((route) => route.settings.name == _addFavNumberRouteName);
              callBack?.call();

              barcodeScanner.close();
            }

          } else {

            await barcodeScanner.close();
            callBack?.call();

            await barcodeScanner.close();

            if(_type == "customer" &&  _transactionType == TransactionType.cashOut){
              Get.back();
              showCustomSnackBarHelper('receiver_must_be_an_agent'.tr);

            }else if(_type == "agent" && _transactionType != TransactionType.cashOut){

              Get.back();
              showCustomSnackBarHelper('receiver_must_be_a_customer'.tr);

            }else {
              Get.off(()=>  TransactionBalanceInputScreen(
                transactionType: _transactionType,
                contactModel: ContactModel(phoneNumber: _phone, name: _name, avatarImage: _image, isFavourite: isFavNumber),
              ));
            }
          }

        }
      }

    } else {
    }
    _isBusy = false;
  }

  void onInitScanContact(String? currentRoute) {
    _addFavNumberRouteName = currentRoute;
    _scannedContact = null;

  }

}

class TransactionSelect extends StatelessWidget {
  final ContactModel? contactModel;
  const TransactionSelect({super.key, this.contactModel});

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        ListTile(
          title: Text('send_money'.tr),
          minVerticalPadding: 0,
          onTap: () =>  Get.off(()=>  TransactionBalanceInputScreen(transactionType: 'send_money',contactModel: contactModel)),
        ),

        ListTile(
          title: Text('request_money'.tr),
          minVerticalPadding: 0,
          onTap: () =>  Get.off(()=> TransactionBalanceInputScreen(transactionType: 'request_money',contactModel: contactModel)),
        ),
      ],
    );
  }
}
