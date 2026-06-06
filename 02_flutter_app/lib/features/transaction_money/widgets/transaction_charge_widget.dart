import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/models/config_model.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class TransactionChargeWidget extends StatelessWidget {
  final bool isFavorite;
  final String? transactionType;
  final String inputAmountString;
  const TransactionChargeWidget({super.key,
    required this.isFavorite, required this.transactionType, required this.inputAmountString,
  });

  @override
  Widget build(BuildContext context) {
    final ConfigModel? configModel = Get.find<SplashController>().configModel;
    final double inputAmount = PriceConverterHelper.getAmountFromInputFormatter(inputAmountString);

    final double charge = _getChargeAmount(inputAmount, configModel);

    final double discountedCharge = charge - PriceConverterHelper.convertCharge(
      amount: charge,
      charge: transactionType == TransactionType.sendMoney ? configModel?.favSendMoneyChargeDiscount : configModel?.favCashOutChargeDiscount,
    );

    final bool isFavApplicable = isFavorite && configModel?.favCashOutChargeDiscount != 0;

    return  inputAmount > 0 && (transactionType == TransactionType.cashOut || transactionType == TransactionType.sendMoney || transactionType == TransactionType.withdrawRequest) ? Container(
      padding: const EdgeInsets.all(Dimensions.paddingSizeSmall).copyWith(bottom: Dimensions.paddingSizeLarge),
      alignment: Alignment.center,
      width: double.maxFinite,
      color: Theme.of(context).cardColor,
      child: Text.rich(TextSpan(children: [

        TextSpan(text: '${'charge_will_be'.tr} ', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault)),

        TextSpan(text: PriceConverterHelper.convertPrice(charge), style: rubikRegular.copyWith(
          fontSize: Dimensions.fontSizeDefault,
          decoration: isFavApplicable ? TextDecoration.lineThrough : null,
          color: isFavApplicable ? Theme.of(context).hintColor.withValues(alpha: 0.3) : null,
          decorationColor: Theme.of(context).hintColor.withValues(alpha: 0.3),
        )),

        if(isFavApplicable) TextSpan(text: ' ${PriceConverterHelper.convertPrice(discountedCharge)}', style: rubikRegular.copyWith(
          fontSize: Dimensions.fontSizeDefault,
        )),

      ])),
    ) : SizedBox();
  }

  double _getChargeAmount(double inputAmount, ConfigModel? configModel) {
    return transactionType == TransactionType.withdrawRequest ? PriceConverterHelper.convertCharge(amount: inputAmount, charge: configModel?.withdrawChargePercent) :
      transactionType == TransactionType.cashOut
      ? PriceConverterHelper.convertCharge(amount: inputAmount, charge: configModel?.cashOutChargePercent)
      : configModel?.sendMoneyChargeFlat ?? 0;
  }
}
