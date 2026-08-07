import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/history/domain/models/transaction_model.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/common/widgets/no_data_widget.dart';
import 'package:amial_pay/features/history/widgets/history_shimmer_widget.dart';


import 'transaction_history_widget.dart';
class TransactionListWidget extends StatelessWidget {
  final ScrollController? scrollController;
  final bool isHome;
  final String? type;
  const TransactionListWidget({super.key, this.scrollController,  this.isHome = false, this.type});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<TransactionHistoryController>(builder: (transactionHistoryController){
      final List<Transactions>? transactionList = transactionHistoryController.recentTransactionList;

      return  Column(children: [
        transactionList != null ? transactionList.isNotEmpty ? Padding(
        padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraSmall),
        child: ListView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: isHome && transactionList.length > 5 ? 5 : transactionList.length,
          itemBuilder: (ctx,index){

            return TransactionHistoryWidget(transactions: transactionList[index]);
            },
        ),) : NoDataFoundWidget(
          fromHome: isHome,
          icon: Icons.receipt_long_rounded,
          title: 'لا توجد عمليات بعد',
          subtitle: 'ستظهر هنا كل تحويلاتك ومدفوعاتك فور تنفيذها.',
        ) : const HistoryShimmerWidget(),

        transactionHistoryController.isLoading ? Center(child: Padding(
          padding: const EdgeInsets.all(Dimensions.paddingSizeDefault),
          child: CircularProgressIndicator(valueColor: AlwaysStoppedAnimation<Color>(Theme.of(context).primaryColor)),
        )) : const SizedBox.shrink(),
      ],);

    });
  }
}
