import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class TransactionInfoWidget extends StatelessWidget {
  final String? label;
  final String? info;
  final String? transactionId;
  const TransactionInfoWidget({super.key, this.label, this.info, this.transactionId});

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      Text('$label : ', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault, color: Theme.of(context).hintColor)),

      Flexible(child: Text(info ?? '', style: rubikRegular.copyWith(
        fontSize: Dimensions.fontSizeDefault,
        color: Theme.of(context).primaryColor,
      ), overflow: TextOverflow.ellipsis, maxLines: 1)),
      const SizedBox(width: Dimensions.paddingSizeSmall),

      if(transactionId != null)
        InkWell(
          onTap: (){
            Clipboard.setData(ClipboardData(text: transactionId!));
            showCustomSnackBarHelper('transaction_id_copied'.tr,isError: false);
          },
          child: Icon(Icons.copy_rounded, size: Dimensions.paddingSizeDefault, color: Theme.of(context).hintColor),
        )
    ]);
  }
}
