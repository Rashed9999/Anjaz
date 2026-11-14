import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/models/config_model.dart';
import 'package:amial_pay/features/setting/domain/models/profile_model.dart';
import 'package:amial_pay/helper/price_converter_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/common/widgets/no_data_widget.dart';

// AMIAL-LIMITS-DESIGN-001 — كانت هذه الشاشة ورِثت تصميمَها حرفياً من قالب 6amTech
// (6cash): ألوانٌ خامٌ (`Colors.black`)، ومقاساتٌ من `Dimensions` القديمة بدل
// `AmialSpacing`، وخطٌّ `rubikMedium/rubikRegular` بدل خط المشروع وألوانه. أُعيد
// بناؤها بتوكينزِ الهويّة البصريّة الفعليّة (`AmialColors` · `AmialSpacing`)
// لتبدو جزءاً من أميال باي لا صفحةً من تطبيقٍ آخر — دون تغيير أيّ منطقٍ أو عقدٍ
// مع الخادم (النماذج `TransactionTableModel`/`CustomerLimit`/`Transaction` كما هي).

class TransactionLimitScreen extends StatefulWidget {
  final List<TransactionTableModel> transactionTableModelList;
  const TransactionLimitScreen({super.key, required this.transactionTableModelList});

  @override
  State<TransactionLimitScreen> createState() => _TransactionLimitScreenState();
}

class _TransactionLimitScreenState extends State<TransactionLimitScreen> with TickerProviderStateMixin {
  TabController? tabController;
  final List<String> tabItem = ['daily_limit', 'monthly_limit'];

  @override
  void initState() {
    super.initState();
    tabController = TabController(length: tabItem.length, vsync: this);
  }

  @override
  void dispose() {
    tabController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.cardSurface,
        foregroundColor: AmialColors.textPrimary,
        elevation: 0,
        title: Text('transaction_limit'.tr,
            style: const TextStyle(fontWeight: FontWeight.w800)),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(48),
          child: Container(
            color: AmialColors.cardSurface,
            padding: const EdgeInsets.symmetric(horizontal: AmialSpacing.screen),
            child: TabBar(
              controller: tabController,
              indicatorSize: TabBarIndicatorSize.tab,
              dividerColor: Colors.transparent,
              labelColor: AmialColors.primary,
              unselectedLabelColor: AmialColors.textMuted,
              indicatorColor: AmialColors.primary,
              labelStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
              tabs: tabItem.map((e) => Tab(text: e.tr)).toList(),
            ),
          ),
        ),
      ),
      body: widget.transactionTableModelList.isNotEmpty
          ? Padding(
              padding: const EdgeInsets.all(AmialSpacing.screen),
              child: TabBarView(
                controller: tabController,
                children: [
                  _limitsList(tabIndex: 0),
                  _limitsList(tabIndex: 1),
                ],
              ),
            )
          : const NoDataFoundWidget(
              icon: Icons.speed_rounded,
              title: 'لم تُضبط حدود بعد',
              subtitle: 'ستظهر هنا حدودك اليومية والشهرية فور اعتمادها من الإدارة.',
            ),
    );
  }

  Widget _limitsList({required int tabIndex}) {
    return ListView.separated(
      itemCount: widget.transactionTableModelList.length,
      separatorBuilder: (_, __) => const SizedBox(height: AmialSpacing.sm),
      itemBuilder: (context, item) => TransactionTableWidget(
        tabIndex: tabIndex,
        tableModel: widget.transactionTableModelList[item],
      ),
    );
  }
}

class TransactionTableWidget extends StatelessWidget {
  final TransactionTableModel tableModel;
  final int tabIndex;

  const TransactionTableWidget({
    super.key,
    required this.tableModel,
    required this.tabIndex,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(AmialSpacing.radiusSm),
              ),
              child: const Icon(Icons.speed_rounded,
                  size: 18, color: AmialColors.primary),
            ),
            const SizedBox(width: AmialSpacing.xs),
            Expanded(
              child: Text(tableModel.title,
                  style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 14,
                      color: AmialColors.textPrimary)),
            ),
          ]),
          const SizedBox(height: AmialSpacing.sm),
          TransactionItemView(
            title: 'transaction'.tr,
            timeCount: tabIndex == 0
                ? tableModel.transaction.dailyCount
                : tableModel.transaction.monthlyCount,
            subTitle: '(${'max'.tr} '
                '${tabIndex == 0 ? tableModel.customerLimit.transactionLimitPerDay : tableModel.customerLimit.transactionLimitPerMonth}'
                ' ${'times'.tr})',
          ),
          const SizedBox(height: AmialSpacing.xs),
          TransactionItemView(
            title: 'total_transaction'.tr,
            amount: tabIndex == 0
                ? tableModel.transaction.dailyAmount
                : tableModel.transaction.monthlyAmount,
            subTitle: '(${'max'.tr} ${PriceConverterHelper.convertPrice(
              tabIndex == 0
                  ? tableModel.customerLimit.totalTransactionAmountPerDay
                  : tableModel.customerLimit.totalTransactionAmountPerMonth,
            )})',
          ),
          if (tabIndex == 0) ...[
            const SizedBox(height: AmialSpacing.xs),
            TransactionItemView(
              title: 'max_amount_per_transaction'.tr,
              amount: tableModel.customerLimit.maxAmountPerTransaction,
            ),
          ],
        ],
      ),
    );
  }
}

class TransactionItemView extends StatelessWidget {
  final String title;
  final int? timeCount;
  final double? amount;
  final String? subTitle;
  const TransactionItemView({
    super.key,
    required this.title,
    this.timeCount,
    this.amount,
    this.subTitle,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
          horizontal: AmialSpacing.sm, vertical: AmialSpacing.xs),
      decoration: BoxDecoration(
        color: AmialColors.background,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusSm),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Flexible(
            child: Text(
              title,
              style: const TextStyle(
                  color: AmialColors.textSecondary, fontSize: 13),
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(width: AmialSpacing.xs),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              if (timeCount != null)
                Text('$timeCount',
                    style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        color: AmialColors.textPrimary)),
              if (amount != null)
                Text(PriceConverterHelper.convertPrice(amount!),
                    style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        color: AmialColors.textPrimary)),
              if (subTitle != null)
                Text(subTitle!,
                    style: TextStyle(
                        fontSize: 11,
                        color: timeCount != null
                            ? AmialColors.info
                            : AmialColors.textMuted)),
            ],
          ),
        ],
      ),
    );
  }
}

class TransactionTableModel {
  final String title;
  final String image;
  final CustomerLimit customerLimit;
  final Transaction transaction;

  TransactionTableModel(this.title, this.image, this.customerLimit, this.transaction);
}
