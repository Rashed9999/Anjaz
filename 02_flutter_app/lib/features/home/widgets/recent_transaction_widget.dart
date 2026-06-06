import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/history/domain/models/transaction_model.dart';
import 'package:amyal_pay/features/history/widgets/transaction_list_widget.dart';
import 'package:amyal_pay/features/home/controllers/menu_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class RecentTransactionWidget extends StatelessWidget {
  const RecentTransactionWidget({super.key});

  @override
  Widget build(BuildContext context) {
    final ScrollController scrollController = ScrollController();
    return GetBuilder<TransactionHistoryController>(builder: (transactionHistoryController){
      final List<Transactions> transactionList = transactionHistoryController.recentTransactionList ?? [];

      return Container(
        color: Theme.of(context).cardColor,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

          const SizedBox(height: Dimensions.paddingSizeDefault),
          Container( padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
            child: Text( 'recent_transactions'.tr, style: rubikMedium.copyWith(
              fontSize: Dimensions.fontSizeLarge,
              color: Theme.of(context).textTheme.titleLarge!.color,
            ),
            ),
          ),

          const SizedBox(height: Dimensions.paddingSizeExtraSmall),

          Divider(thickness: 0.4, color: Theme.of(context).hintColor.withValues(alpha:0.3)),

          TransactionListWidget(scrollController: scrollController, isHome: true),

          if(transactionList.length > 5) Center(
            child: GestureDetector(
              onTap: (){
                Get.find<MenuItemController>().selectHistoryPage();
                Get.find<TransactionHistoryController>().setIndex(0);
              },
              child: Padding(
                padding: const EdgeInsets.only(top: Dimensions.paddingSizeSmall, bottom: Dimensions.paddingSizeOverLarge),
                child: Text('view_all'.tr,
                  style: rubikMedium.copyWith(
                    color: Theme.of(context).primaryColor,
                    decoration: TextDecoration.underline,
                  ),
                ),
              ),
            ),
          ),
        ]),
      );
    });
  }
}
