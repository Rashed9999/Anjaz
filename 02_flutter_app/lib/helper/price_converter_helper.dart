import 'package:flutter/material.dart';
import 'package:get/get_core/src/get_main.dart';
import 'package:get/get_instance/src/extension_instance.dart';
import 'package:amial_pay/common/models/config_model.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/util/app_constants.dart';
class PriceConverterHelper {
  static String convertPrice(double price, {double? discount, String? discountType}) {
    if(discount != null && discountType != null){
      if(discountType == 'amount') {
        price = price - discount;
      }else if(discountType == 'percent') {
        price = price - ((discount / 100) * price);
      }
    }
    final ConfigModel? configModel = Get.find<SplashController>().configModel;
    return  '${(Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false)
        ? configModel?.currencySymbol
        : ''}${(price).toStringAsFixed(AppConstants.dynamicDecimalPoint).replaceAllMapped(RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'), (Match m) => '${m[1]},')}${
        (Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false)
            ? '' : configModel?.currencySymbol}';
  }

  static double convertWithDiscount(BuildContext context, double price, double discount, String discountType) {
    if(discountType == 'amount') {
      price = price - discount;
    }else if(discountType == 'percent') {
      price = price - ((discount / 100) * price);
    }
    return price;
  }

  static double? calculation(double? amount, double? discount, String type) {
    double? calculatedAmount = 0;
    if(type == 'amount') {
      calculatedAmount = discount;
    }else if(type == 'percent') {
      calculatedAmount = (discount! / 100) * amount!;

    }
    return calculatedAmount;
  }

  static String percentageCalculation(BuildContext context, String price, String discount, String discountType) {
    return '$discount${discountType == 'percent' ? '%' : '\$'} OFF';
  }


  static double balanceWithCharge({required double? amount, required double? charge})=> (amount ?? 0) + (charge ?? 0);

  static String availableBalance(){
    // AMIAL-FIX(GRAY): كان userInfo!.balance ينهار أثناء البناء قبل تحميل
    // الملف الشخصي (الدخول الموحّد) → شاشة إدخال المبلغ رمادية بالكامل.
    String? currencySymbol = Get.find<SplashController>().configModel?.currencySymbol ?? 'ر.ي';
    final double balance = Get.find<ProfileController>().userInfo?.balance ?? 0.0;
    final String currentBalance = (balance > 0 ? balance : 0).toStringAsFixed(AppConstants.dynamicDecimalPoint);
    return (Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false) ?  '$currencySymbol$currentBalance' : '$currentBalance$currencySymbol';

  }
  static String newBalanceWithDebit({required double inputBalance, required double charge}){
    String? currencySymbol = Get.find<SplashController>().configModel!.currencySymbol;
    String currentBalance = ((Get.find<ProfileController>().userInfo?.balance ?? 0.0)  - (inputBalance+charge)).toStringAsFixed(AppConstants.dynamicDecimalPoint);
    return (Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false) ?  '$currencySymbol$currentBalance' : '$currentBalance$currencySymbol';

  }

  static String newBalanceWithCredit({required double inputBalance}){
    String? currencySymbol = Get.find<SplashController>().configModel!.currencySymbol;
    String currentBalance = ((Get.find<ProfileController>().userInfo?.balance ?? 0.0) + inputBalance).toStringAsFixed(AppConstants.dynamicDecimalPoint);
    return (Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false) ?  '$currencySymbol$currentBalance' : '$currentBalance$currencySymbol';

  }

  static String balanceInputHint(){
    String? currencySymbol = Get.find<SplashController>().configModel!.currencySymbol;
    String balance = '0';
    return (Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false) ?  '$currencySymbol$balance' : '$balance$currencySymbol';

  }
  static String balanceWithSymbol({String? balance}){
    String? currencySymbol = Get.find<SplashController>().configModel!.currencySymbol;
    return (Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false) ?  '$currencySymbol$balance' : '$balance$currencySymbol';


  }

  static double convertCharge({required double? amount, required double? charge, bool isPercent = true}) {
    if (isPercent) {
      return (amount! * charge!) / 100;
    } else {
      return charge!;
    }
  }

  static double getAmountFromInputFormatter(String inputAmountString) {
    final cleanedString = inputAmountString
        .replaceAll('\u202F', '')  // Remove non-breaking space
        .replaceAll(Get.find<SplashController>().configModel?.currencySymbol ?? '', '')  // Remove currency symbol
        .replaceAll(',', '')  // Remove thousands separators
        .trim();

    return double.tryParse(cleanedString) ?? 0.0;
  }
}