import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_image_widget.dart';
import 'package:amial_pay/common/widgets/show_custom_bottom_sheet.dart';
import 'package:amial_pay/common/widgets/transaction_details_bottom_sheet_widget.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/history/domain/models/transaction_model.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/helper/price_converter_helper.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';

class TransactionHistoryWidget extends StatelessWidget {
  final Transactions? transactions;
  const TransactionHistoryWidget({super.key, this.transactions});



  @override
  Widget build(BuildContext context) {
    String? userPhone;
    String? userName;
    String? userImage;
    final bool isCredit = (transactions?.credit ?? 0) > 0;
    final TransactionAdminInfo? transactionAdminInfo = Get.find<TransactionHistoryController>().transactionModel?.transactionAdminInfo;


    try{

      switch (transactions?.transactionType) {
        case AppConstants.sendMoney:
          userPhone = transactions?.receiver!.phone;
          break;
        case AppConstants.receivedMoney:
        case AppConstants.addMoney:
        case 'add_money_bonus':
        case AppConstants.cashIn:
          userPhone = transactions?.sender!.phone;
          break;
        case AppConstants.withdraw:
        case AppConstants.cashOut:
          userPhone = transactions?.receiver!.phone;
          break;
        case AppConstants.deductDisputedMoney || AppConstants.addDisputedMoney:
          userPhone = transactionAdminInfo?.phone;
          break;

        default:
          userPhone = transactions?.userInfo?.phone;
      }


      switch (transactions?.transactionType) {
        case AppConstants.sendMoney:
          userName = transactions!.receiver!.name;
          break;
        case AppConstants.receivedMoney:
        case AppConstants.addMoney:
        case 'add_money_bonus':
        case AppConstants.cashIn:
          userName = transactions?.sender!.name;
          break;
        case AppConstants.withdraw:
        case AppConstants.cashOut:
          userName = transactions?.receiver!.name;
          break;
        case AppConstants.deductDisputedMoney || AppConstants.addDisputedMoney:
          userName = transactionAdminInfo?.name;
          break;

        default:
          userName = (transactions?.userInfo?.name ?? '');
      }


      switch (transactions?.transactionType) {
        case AppConstants.sendMoney:
          userImage = transactions?.receiver!.image;
          break;
        case AppConstants.receivedMoney:
        case AppConstants.addMoney:
        case 'add_money_bonus':
        case AppConstants.cashIn:
          userImage = transactions?.sender!.image;
          break;
        case AppConstants.withdraw:
        case AppConstants.cashOut:
          userImage = transactions?.receiver!.image;
          break;
        case AppConstants.deductDisputedMoney || AppConstants.addDisputedMoney:
          userImage = transactionAdminInfo?.image;
          break;
        default:
          userImage = transactions?.receiver?.image;
      }

    }catch(e){
     userName = 'no_user'.tr;
    }

    return InkWell(
      onTap: ()=> showCustomBottomSheet(child: TransactionDetailsBottomSheetWidget(transactions: transactions)),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeExtraSmall, horizontal: Dimensions.paddingSizeDefault),
        child: Column(children: [

          Row(children: [
            Row(mainAxisAlignment: MainAxisAlignment.start,children: [
              SizedBox(
                height: 35,width: 35,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(50),
                  child: CustomImageWidget(
                    image: userImage ?? "",
                    placeholder: Images.placeholder,
                  ),
                ),
              ),
              const SizedBox(width: Dimensions.paddingSizeSmall),

              Column(crossAxisAlignment: CrossAxisAlignment.start , children: [
                Text(
                  userName ?? '',
                  maxLines: 1, overflow: TextOverflow.ellipsis,
                  style: rubikRegular.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color),
                ),
                const SizedBox(height: Dimensions.paddingSizeSuperExtraSmall),

                Text(userPhone ?? '', style: rubikLight.copyWith(fontSize: Dimensions.fontSizeDefault)),
              ]),
            ]),
            Spacer(),

            Column(crossAxisAlignment: CrossAxisAlignment.end,children: [
              Text(
                DateConverterHelper.estimatedDate(DateTime.parse(transactions!.createdAt!)),
                style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeSmall,
                  color: Theme.of(context).hintColor,
                ),
              ),
              const SizedBox(height: Dimensions.paddingSizeSuperExtraSmall),

              Text(
                DateConverterHelper.isoStringToLocalTimeOnly(transactions!.createdAt!),
                style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeSmall,
                  color: Theme.of(context).hintColor,
                ),
              ),
            ]),
          ]),
          SizedBox(height: Dimensions.paddingSizeSmall),

          Row(children: [
            Expanded(child: Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                color: Theme.of(context).primaryColor.withValues(alpha:0.05),
              ),
              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: Dimensions.paddingSizeSmall),
              child: Row(mainAxisSize: MainAxisSize.min, mainAxisAlignment: MainAxisAlignment.spaceBetween,children: [
                Column( crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('TrxID:', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall, color: Theme.of(context).hintColor)),

                  Text(
                    '${transactions!.transactionId}',
                    style: rubikLight.copyWith(fontSize: Dimensions.fontSizeSmall, color: Theme.of(context).textTheme.bodyLarge?.color),
                  ),
                ]),
                const SizedBox(width: Dimensions.paddingSizeDefault),

                GestureDetector(
                  onTap: () {
                    Clipboard.setData(ClipboardData(text: "${transactions!.transactionId}"));
                    showCustomSnackBarHelper('transaction_id_copied'.tr,isError: false);
                  },
                  child: Container(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(Dimensions.dividerSizeLarge),
                      color: Theme.of(context).cardColor,
                    ),
                    padding: const EdgeInsets.all(Dimensions.paddingSizeExtraSmall),
                    child: Icon(Icons.copy_rounded, size: 18, color: Theme.of(context).textTheme.bodyLarge?.color),
                  ),
                ),
              ]),
            )),
            SizedBox(width: Dimensions.paddingSizeSmall),

            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end,children: [
              Text(
                transactions?.transactionType?.tr ?? "",
                style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall+2),
              ),

              Text(
                '${isCredit ? '+' : '-'} ${PriceConverterHelper.convertPrice(double.parse(transactions!.amount.toString()))}',
                style: rubikSemiBold.copyWith(
                  fontSize: Dimensions.fontSizeLarge,
                  color: isCredit ? Colors.green : Colors.redAccent,
                ),
              ),
            ])),
          ]),
          SizedBox(height: Dimensions.paddingSizeSmall),

          Divider(thickness: 0.4, color: Theme.of(context).hintColor.withValues(alpha:0.3)),
        ]),
      ),
    );
  }
}

