import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/common/widgets/rounded_button_widget.dart';
import 'package:amyal_pay/features/language/controllers/localization_controller.dart';
import 'package:amyal_pay/common/models/config_model.dart';
import 'package:amyal_pay/features/setting/domain/models/profile_model.dart';
import 'package:amyal_pay/helper/custom_extension_helper.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amyal_pay/common/widgets/no_data_widget.dart';


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
    final String languageText = AppConstants.languages[Get.find<LocalizationController>().selectedIndex].languageName!;

    return DefaultTabController(
      length: tabItem.length,
      child: Scaffold(
        appBar: AppBar(
          toolbarHeight: 60,
          leadingWidth: 90,
          leading: Container(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraSmall),
            margin: const EdgeInsets.symmetric(vertical: Dimensions.fontSizeDefault, horizontal: Dimensions.paddingSizeLarge),
            child: CustomInkWellWidget(
              onTap: ()=> Get.back(),
              radius: Dimensions.radiusSizeSmall,
              child: Container(
                alignment: Alignment.center,
                height: 40,
                width: 35,
                decoration: BoxDecoration(
                  border: Border.all(color: Theme.of(context).cardColor, width: 1),
                  borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                ),
                child: Icon(Icons.arrow_back_ios_new, size: Dimensions.paddingSizeDefault, color: Theme.of(context).cardColor),
              ),
            ),
          ),
          elevation: 0,
          backgroundColor: Theme.of(context).primaryColor,
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(40.0),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal:Dimensions.paddingSizeLarge),
              width: MediaQuery.of(context).size.width,
              color: Theme.of(context).canvasColor,
              child: TabBar(
                indicatorSize: TabBarIndicatorSize.tab,
                tabAlignment: TabAlignment.start,
                dividerHeight: 0,
                dividerColor: Colors.transparent,
                labelColor: Theme.of(context).textTheme.bodyLarge?.color,
                unselectedLabelColor: Theme.of(context).hintColor.withValues(alpha: 0.6),
                controller: tabController,
                isScrollable: true,
                padding: EdgeInsets.zero,
                labelPadding: const EdgeInsets.symmetric(
                  horizontal: Dimensions.paddingSizeSmall,
                  vertical: Dimensions.paddingSizeSmall,
                ),
                indicatorColor: Theme.of(context).textTheme.bodyLarge?.color,
                tabs: tabItem.map((e) => Tab(text: e.tr, height: 20)).toList(),
              ),
            ),
          ),
          title: Text('transaction_limit'.tr, style: rubikRegular.copyWith(color: Colors.white, fontSize: Dimensions.fontSizeExtraLarge)),
          actions: [
            Padding(
              padding: const EdgeInsets.all(Dimensions.paddingSizeDefault),
              child: RoundedButtonWidget(
                buttonText: languageText,
                isSkip: true,
                onTap: AppConstants.languages.length > 1 ? (){
                  Get.toNamed(RouteHelper.getChoseLanguageRoute());
                } : null,
              ),
            ),
          ],
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
        ) : const NoDataFoundWidget(),
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