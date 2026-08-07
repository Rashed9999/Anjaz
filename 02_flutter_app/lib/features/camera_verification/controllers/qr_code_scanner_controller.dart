import 'package:flutter/material.dart';
import 'package:flutter_contacts/flutter_contacts.dart';
import 'package:get/get.dart';
import 'package:google_mlkit_barcode_scanning/google_mlkit_barcode_scanning.dart';
import 'package:amial_pay/common/models/contact_model.dart';
import 'package:amial_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amial_pay/features/transaction_money/domain/models/contact_tag_model.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/helper/transaction_type.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/features/payments/domain/amial_qr_payload.dart';
import 'package:amial_pay/features/merchant/screens/merchant_pay_screen.dart';
import 'package:amial_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';

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



  /// AMIAL-QR-UNIFIED-001 — مسح واحد يفهم كل رموز أميال باي.
  ///
  /// كان يقرأ صيغة الهوية وحدها ويشترط حقولها الأربعة مجتمعة. فرمز الكاشير
  /// ({t:amial_pr, code}) تخرج حقوله الأربعة فارغةً ولا يقع شيء: يقف العميل
  /// عند الصندوق فيضغط الزرّ الأصفر ولا يحدث شيء ولا رسالة.
  ///
  /// وكان `jsonDecode` بلا حماية و`_isBusy = false` خارج finally: فمسح أي
  /// رمز ليس JSON — باركود منتج مثلاً — يرمي ويُبقي القفل مرفوعاً، فيموت
  /// الماسح لبقيّة الجلسة.
  Future<void> processImage(InputImage inputImage, bool isHome, String? transactionType,
      {bool fromSearchContact = false, required Function? callBack}) async {
    if (_isBusy) return;
    _isBusy = true;

    final BarcodeScanner barcodeScanner = BarcodeScanner();
    try {
      final barcodes = await barcodeScanner.processImage(inputImage);
      if (inputImage.metadata?.size == null || inputImage.metadata?.rotation == null) {
        return;
      }

      for (final barcode in barcodes) {
        final payload = AmialQrPayload.parse(barcode.rawValue);

        // ===== رمز دفع بمبلغ حدّده البائع =====
        // العميل يؤكّد ولا يكتب مبلغاً — من يبيع يحدّد الثمن.
        if (payload.isPaymentRequest) {
          if (_isDetect) return;
          _isDetect = true;
          await barcodeScanner.close();
          callBack?.call();
          await Get.off(() => MerchantPayScreen(requestCode: payload.requestCode));
          _isDetect = false;
          return;
        }

        // ===== رمز لا نعرفه =====
        // باركود منتج، رمز موقع، رمز تطبيق آخر. كان يُهمَل بصمت فيظنّ
        // المستخدم أن الكاميرا لم تلتقطه بعدُ ويظلّ يوجّهها. القول أرحم.
        if (payload.kind == AmialQrKind.unknown) {
          if (_isDetect) return;
          _isDetect = true;
          showCustomSnackBarHelper('هذا الرمز ليس رمز دفع في أميال باي');
          // مهلة قصيرة قبل السماح برسالة أخرى: الكاميرا تلتقط عشرات الإطارات
          // في الثانية، وبلا هذه المهلة تنهال الرسائل على الشاشة.
          Future.delayed(const Duration(seconds: 3), () => _isDetect = false);
          return;
        }

        // ===== رموز الهوية: المبلغ يكتبه المستخدم =====
        if (payload.phone == null) continue;

        _name = payload.name;
        _phone = payload.phone;
        _type = switch (payload.kind) {
          AmialQrKind.agent => 'agent',
          AmialQrKind.merchant => 'merchant',
          _ => 'customer',
        };
        _image = payload.image;
        _transactionType = _type == 'agent' ? 'cash_out' : transactionType;

        final bool isFavNumber = Get.find<FavNumberController>().isFavouriteNumber(_phone);
        final contact = ContactModel(
          phoneNumber: _phone, name: _name, avatarImage: _image, isFavourite: isFavNumber);

        if (isHome && _type != 'agent') {
          if (!_isDetect) {
            _isDetect = true;
            Get.defaultDialog(
              title: 'select_a_transaction'.tr,
              content: TransactionSelect(contactModel: contact),
              barrierDismissible: false,
              radius: Dimensions.radiusSizeDefault,
            ).then((_) => _isDetect = false);
          }
          return;
        }

        if (fromSearchContact) {
          if (_scannedContact != null) return;
          _scannedContact = ContactTagModel(
            favouriteModel: null,
            contact: Contact(
              displayName: _name ?? '',
              phones: [Phone(_phone ?? '')],
            ),
            tag: (_name?.isNotEmpty ?? false) ? _name![0] : '#',
          );
          Get.until((route) => route.settings.name == _addFavNumberRouteName);
          callBack?.call();
          return;
        }

        await barcodeScanner.close();
        callBack?.call();

        if (_type == 'customer' && _transactionType == TransactionType.cashOut) {
          Get.back();
          showCustomSnackBarHelper('receiver_must_be_an_agent'.tr);
        } else if (_type == 'agent' && _transactionType != TransactionType.cashOut) {
          Get.back();
          showCustomSnackBarHelper('receiver_must_be_a_customer'.tr);
        } else {
          Get.off(() => TransactionBalanceInputScreen(
                transactionType: _transactionType,
                contactModel: contact,
              ));
        }
        return;
      }
    } catch (e) {
      debugPrint('AMIAL-QR: تعذّرت قراءة الرمز — $e');
    } finally {
      // القفل يُرفع مهما وقع. تركُه مرفوعاً يعني ماسحاً ميتاً لبقيّة الجلسة.
      await barcodeScanner.close();
      _isBusy = false;
    }
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
