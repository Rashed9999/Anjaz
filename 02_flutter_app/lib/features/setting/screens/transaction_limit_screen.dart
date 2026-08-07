import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amial_pay/common/models/config_model.dart';
import 'package:amial_pay/features/setting/domain/models/profile_model.dart';
import 'package:amial_pay/helper/custom_extension_helper.dart';
import 'package:amial_pay/helper/price_converter_helper.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/common/widgets/no_data_widget.dart';


class TransactionLimitScreen extends StatefulWidget {
  final List<TransactionTableModel> transactionTableModelList;
  const TransactionLimitScreen({super.key, required this.transactionTableModelList});

  @override
  State<TransactionLimitScreen> createState() => _TransactionLimitScreenState();
}

class _TransactionLimitScreenState extends State<TransactionLimitScreen> with TickerProviderStateMixin{

  TabController? tabController;
  final List<String> tabItem = ['daily_limit', 'monthly_limit'];

  @override
  void initState() {
    tabController = TabController(length: tabItem.length, vsync: this);

    super.initState();
  }


  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: tabItem.length,
      child: Scaffold(
        appBar: AppBar(
          title: Text('transaction_limit'.tr),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(46.0),
            child: Container(
              width: double.infinity,
              color: Colors.white,
              child: TabBar(
                indicatorSize: TabBarIndicatorSize.tab,
                dividerColor: Colors.transparent,
                labelColor: AmialColors.primary,
                unselectedLabelColor: AmialColors.textMuted,
                indicatorColor: AmialColors.primary,
                labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                controller: tabController,
                tabs: tabItem.map((e) => Tab(text: e.tr)).toList(),
              ),
            ),
          ),
        ),

        body: widget.transactionTableModelList.isNotEmpty ? Padding(
          padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
          child: TabBarView(
              controller: tabController,
              children: [

                ListView.builder(
                  itemCount: widget.transactionTableModelList.length,
                  itemBuilder: (context, item) => Padding(
                    padding: const EdgeInsets.only(bottom: Dimensions.paddingSizeSmall),
                    child: TransactionTableWidget(
                      tabIndex: 0,
                      tableModel: widget.transactionTableModelList[item],
                    ),
                  ),
                ),

                ListView.builder(
                  itemCount: widget.transactionTableModelList.length,
                  itemBuilder: (context, item) => Padding(
                    padding: const EdgeInsets.only(bottom: Dimensions.paddingSizeSmall),
                    child: TransactionTableWidget(
                      tabIndex: 1,
                      tableModel: widget.transactionTableModelList[item],
                    ),
                  ),
                ),

              ]
          ),
        ) : const NoDataFoundWidget(
          icon: Icons.speed_rounded,
          title: 'لم تُضبط حدود بعد',
          subtitle: 'ستظهر هنا حدودك اليومية والشهرية فور اعتمادها من الإدارة.',
        ),
      ),
    );
  }
}

class TransactionTableWidget extends StatelessWidget {
  final TransactionTableModel tableModel;
  final int tabIndex;

  const TransactionTableWidget({
    super.key,
    required this.tableModel, required this.tabIndex,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(Dimensions.paddingSizeExtraSmall),
        boxShadow: [BoxShadow(
          blurRadius: 7,
          offset: const Offset(0, 1),
          color: Colors.black.withValues(alpha: 0.05),
        )],
      ),
      child: Column(children: [

        Row(mainAxisAlignment: MainAxisAlignment.center,children: [
          CustomAssetImageWidget(tableModel.image, width: Dimensions.radiusSizeExtraLarge, height: Dimensions.radiusSizeExtraLarge),
          const SizedBox(width: Dimensions.paddingSizeSmall),

          Text(tableModel.title, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeDefault, color: Theme.of(context).textTheme.bodyLarge?.color)),
        ]),
        const SizedBox(height: Dimensions.paddingSizeSmall),

        TransactionItemView(
          title: 'transaction'.tr,
          timeCount: tabIndex == 0
              ?  tableModel.transaction.dailyCount
              : tableModel.transaction.monthlyCount,
          subTitle: ' (${'max'.tr} ${tabIndex == 0
              ? tableModel.customerLimit.transactionLimitPerDay
              : tableModel.customerLimit.transactionLimitPerMonth} ${'times'.tr})',
        ),
        const SizedBox(height: Dimensions.paddingSizeSmall),

        TransactionItemView(
          title: 'total_transaction'.tr,
          amount: tabIndex == 0
              ? tableModel.transaction.dailyAmount
              : tableModel.transaction.monthlyAmount,
          subTitle: ' (${'max'.tr} ${PriceConverterHelper.convertPrice(tabIndex == 0
              ? tableModel.customerLimit.totalTransactionAmountPerDay
              : tableModel.customerLimit.totalTransactionAmountPerMonth)})',

        ),
        const SizedBox(height: Dimensions.paddingSizeSmall),

        if(tabIndex == 0) TransactionItemView(
          title: 'max_amount_per_transaction'.tr,
          amount: tabIndex == 0
              ? tableModel.customerLimit.maxAmountPerTransaction
              : tableModel.customerLimit.maxAmountPerTransaction,
        ),
        const SizedBox(height: Dimensions.paddingSizeSmall),
      ]),
    );
  }
}

class TransactionItemView extends StatelessWidget {
  final String title;
  final int? timeCount;
  final double? amount;
  final String? subTitle;
  const TransactionItemView({
    super.key, required this.title, this.timeCount, this.amount, this.subTitle,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
      child: Container(
        width: MediaQuery.of(context).size.width,
        padding: const EdgeInsets.symmetric(horizontal: Dimensions.radiusSizeSmall, vertical:Dimensions.paddingSizeSmall),
        decoration: BoxDecoration(
            color: Theme.of(context).hintColor.withValues(alpha: 0.04),
            borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall)
        ),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [

          Flexible(
            child: Text(title, style: rubikRegular.copyWith(
                color: Theme.of(context).textTheme.bodyLarge?.color?.withValues(alpha:0.7),
              overflow: TextOverflow.ellipsis,
            )),
          ),
          const SizedBox(height: Dimensions.paddingSizeSmall),

          Column(crossAxisAlignment: CrossAxisAlignment.center, children: [
            if(timeCount != null)
              Text('$timeCount', style: rubikMedium.copyWith(color: Theme.of(context).textTheme.bodyLarge!.color)),


            if(amount != null)
              Text( PriceConverterHelper.convertPrice(amount!), style: rubikMedium.copyWith(color: Theme.of(context).textTheme.bodyLarge!.color)),

            if(subTitle != null)
              Text(subTitle ?? '', style: rubikRegular.copyWith(color: timeCount != null ? context.customThemeColors.info : Theme.of(context).colorScheme.error)),
          ]),
        ]),
      ),
    );
  }
}

class TransactionTableModel{
  final String title;
  final String image;
  final CustomerLimit customerLimit;
  final Transaction transaction;

  TransactionTableModel(this.title, this.image, this.customerLimit, this.transaction);

}