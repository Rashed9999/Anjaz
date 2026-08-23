import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/entitlements/capability_screens.dart';
import 'package:amial_pay/features/entitlements/controllers/entitlements_controller.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/images.dart';

/// AMIAL-MERCHANT-NAV-001
///
/// مركزٌ بصريٌّ واحد لقوائم النموذج الثالث. لا توجد قائمة قدرات مكتوبة هنا:
/// المحتوى يأتي من manifest الخادم، وهذه الشاشة لا تختار إلا *المجموعات* التي
/// طلبها المدخل (بيع / أصناف+مخزون / ناس / تقارير / قطاع).
///
/// قفل الباقة ≠ قفل الدور ≠ بلوغ الحد ≠ قريباً. الضغط يحافظ على السبب الحقيقي
/// ولا يرسل الموظف إلى الدفع إذا كان نقصه صلاحية.
class MerchantCapabilityHubScreen extends StatefulWidget {
  const MerchantCapabilityHubScreen({
    super.key,
    required this.title,
    required this.subtitle,
    required this.groups,
    required this.icon,
  });

  final String title;
  final String subtitle;
  final List<String> groups;
  final IconData icon;

  @override
  State<MerchantCapabilityHubScreen> createState() =>
      _MerchantCapabilityHubScreenState();
}

class _MerchantCapabilityHubScreenState
    extends State<MerchantCapabilityHubScreen> {
  EntitlementsController get c => Get.find<EntitlementsController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (c.manifest.value == null && !c.isLoading.value) {
        c.load();
      }
    });
  }

  List<Map<String, dynamic>> _rows() => c.items.where((row) {
        final cap = row['capability'];
        if (cap is! Map) return false;
        return widget.groups.contains('${cap['group'] ?? ''}');
      }).toList();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        title: Text(widget.title),
        centerTitle: true,
      ),
      body: RefreshIndicator(
        onRefresh: c.load,
        color: AmialColors.primary,
        child: Obx(() {
          final rows = _rows();
          return VerticalStateView(
            c: c,
            isEmpty: rows.isEmpty,
            emptyTitle: 'لا خدمات متاحة لهذا القسم',
            emptyHint: 'قد لا ينطبق هذا القسم على نوع نشاطك، أو لم تصل بيانات الخدمات بعد.',
            emptyIcon: widget.icon,
            onRetry: c.load,
            grantedBy: 'مالك المنشأة',
            child: ListView(
              padding: const EdgeInsets.fromLTRB(
                AmialSpacing.screen,
                AmialSpacing.sm,
                AmialSpacing.screen,
                AmialSpacing.xxl,
              ),
              children: [
                _hero(context, rows),
                const SizedBox(height: AmialSpacing.lg),
                _summary(context, rows),
                const SizedBox(height: AmialSpacing.lg),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final columns = constraints.maxWidth >= 720 ? 3 : 2;
                    return GridView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: rows.length,
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: columns,
                        crossAxisSpacing: AmialSpacing.sm,
                        mainAxisSpacing: AmialSpacing.sm,
                        childAspectRatio: columns == 3 ? 1.15 : 0.98,
                      ),
                      itemBuilder: (_, index) => _capabilityCard(
                        context,
                        rows[index],
                      ),
                    );
                  },
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _hero(BuildContext context, List<Map<String, dynamic>> rows) {
    final planName = c.planName;
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmialColors.primaryDark, AmialColors.primary],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: AmialSpacing.xxl * 1.75,
                height: AmialSpacing.xxl * 1.75,
                padding: const EdgeInsets.all(AmialSpacing.xs),
                decoration: BoxDecoration(
                  color: AmialColors.cardSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                ),
                child: Image.asset(Images.logo, fit: BoxFit.contain),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: AmialSpacing.sm,
                  vertical: AmialSpacing.xs,
                ),
                decoration: BoxDecoration(
                  color: AmialColors.warningSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(
                      Icons.workspace_premium_outlined,
                      color: AmialColors.warning,
                      size: AmialSpacing.lg,
                    ),
                    const SizedBox(width: AmialSpacing.xxs),
                    Text(
                      planName,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AmialColors.warning,
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AmialSpacing.lg),
          Row(
            children: [
              Icon(widget.icon, color: AmialColors.yellow),
              const SizedBox(width: AmialSpacing.xs),
              Expanded(
                child: Text(
                  widget.title,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                        color: AmialColors.cardSurface,
                        fontWeight: FontWeight.w900,
                      ),
                ),
              ),
            ],
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            widget.subtitle,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AmialColors.cardSurface.withValues(alpha: 0.82),
                  height: 1.55,
                ),
          ),
        ],
      ),
    );
  }

  Widget _summary(BuildContext context, List<Map<String, dynamic>> rows) {
    final available = rows
        .where((r) => r['state'] == EntitlementsController.stAvailable)
        .length;
    final byPlan = rows
        .where((r) => r['state'] == EntitlementsController.stLockedByPlan)
        .length;

    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Row(
        children: [
          Expanded(
            child: _metric(
              context,
              icon: Icons.check_circle_outline,
              value: '$available',
              label: 'ميزة متاحة',
              color: AmialColors.success,
            ),
          ),
          Container(
            width: 1,
            height: AmialSpacing.xxl,
            color: AmialColors.border,
          ),
          Expanded(
            child: _metric(
              context,
              icon: Icons.diamond_outlined,
              value: '$byPlan',
              label: 'ميزة بترقية',
              color: AmialColors.warning,
            ),
          ),
        ],
      ),
    );
  }

  Widget _metric(
    BuildContext context, {
    required IconData icon,
    required String value,
    required String label,
    required Color color,
  }) {
    return Column(
      children: [
        Icon(icon, color: color),
        const SizedBox(height: AmialSpacing.xxs),
        Text(
          value,
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                color: AmialColors.textPrimary,
                fontWeight: FontWeight.w900,
              ),
        ),
        Text(
          label,
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: AmialColors.textSecondary,
              ),
        ),
      ],
    );
  }

  Widget _capabilityCard(BuildContext context, Map<String, dynamic> row) {
    final cap = row['capability'] is Map
        ? Map<String, dynamic>.from(row['capability'] as Map)
        : <String, dynamic>{};
    final state = '${row['state'] ?? ''}';
    final name = '${cap['name'] ?? 'خدمة'}';
    final comingSoon = '${cap['status'] ?? 'available'}' == 'coming_soon';
    final available =
        !comingSoon && state == EntitlementsController.stAvailable;
    final unlock = row['unlock'] is Map
        ? Map<String, dynamic>.from(row['unlock'] as Map)
        : null;

    final statusColor = available
        ? AmialColors.success
        : comingSoon
            ? AmialColors.textMuted
            : state == EntitlementsController.stLockedByPlan
                ? AmialColors.warning
                : state == EntitlementsController.stLimitReached
                    ? AmialColors.danger
                    : AmialColors.info;

    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: () => _handleTap(context, cap, row),
      child: Container(
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(
            color: available
                ? AmialColors.primary.withValues(alpha: 0.20)
                : AmialColors.border,
          ),
          boxShadow: AmialSpacing.cardShadow,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: AmialSpacing.xxl + AmialSpacing.md,
                  height: AmialSpacing.xxl + AmialSpacing.md,
                  decoration: BoxDecoration(
                    color: available
                        ? AmialColors.primary.withValues(alpha: 0.08)
                        : AmialColors.background,
                    borderRadius:
                        BorderRadius.circular(AmialSpacing.radiusMd),
                  ),
                  child: Icon(
                    _iconFor('${cap['icon'] ?? ''}'),
                    color: available
                        ? AmialColors.primary
                        : AmialColors.textMuted,
                  ),
                ),
                const Spacer(),
                Icon(
                  available
                      ? Icons.check_circle
                      : comingSoon
                          ? Icons.hourglass_empty_rounded
                          : Icons.lock_outline_rounded,
                  color: statusColor,
                  size: AmialSpacing.lg,
                ),
              ],
            ),
            const Spacer(),
            Text(
              name,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    color: AmialColors.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(height: AmialSpacing.xs),
            Container(
              padding: const EdgeInsets.symmetric(
                horizontal: AmialSpacing.xs,
                vertical: AmialSpacing.xxs,
              ),
              decoration: BoxDecoration(
                color: _surfaceFor(statusColor),
                borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
              ),
              child: Text(
                _statusLabel(
                  state: state,
                  comingSoon: comingSoon,
                  unlock: unlock,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: statusColor,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Color _surfaceFor(Color statusColor) {
    if (statusColor == AmialColors.success) return AmialColors.successSurface;
    if (statusColor == AmialColors.warning) return AmialColors.warningSurface;
    if (statusColor == AmialColors.danger) return AmialColors.dangerSurface;
    return AmialColors.background;
  }

  String _statusLabel({
    required String state,
    required bool comingSoon,
    required Map<String, dynamic>? unlock,
  }) {
    if (comingSoon) return 'قريباً';
    if (state == EntitlementsController.stAvailable) return 'متاح';
    if (state == EntitlementsController.stLockedByPlan) {
      return 'يتطلب ${unlock?['plan_name'] ?? 'ترقية'}';
    }
    if (state == EntitlementsController.stLockedByRole) {
      return 'تحتاج صلاحية';
    }
    if (state == EntitlementsController.stLimitReached) {
      return 'بلغت الحد';
    }
    return 'غير معروف';
  }

  void _handleTap(
    BuildContext context,
    Map<String, dynamic> cap,
    Map<String, dynamic> row,
  ) {
    final code = '${cap['code'] ?? ''}';
    final state = '${row['state'] ?? ''}';
    final comingSoon = '${cap['status'] ?? 'available'}' == 'coming_soon';

    if (comingSoon) {
      _explain(
        context,
        title: '${cap['name'] ?? 'الخدمة'}',
        message: 'هذه الخدمة معلنة كـ «قريباً» ولا تفتحها ترقية الباقة الآن.',
      );
      return;
    }

    if (state == EntitlementsController.stAvailable) {
      final builder = CapabilityScreens.screenFor(code);
      if (builder == null) {
        _explain(
          context,
          title: '${cap['name'] ?? 'الخدمة'}',
          message: 'الخدمة متاحة في حسابك، لكن لا توجد لها شاشة مستقلة في التطبيق حالياً.',
        );
        return;
      }
      Get.to(builder);
      return;
    }

    final unlock = row['unlock'] is Map
        ? Map<String, dynamic>.from(row['unlock'] as Map)
        : null;
    final usage = row['usage'] is Map
        ? Map<String, dynamic>.from(row['usage'] as Map)
        : null;

    if (state == EntitlementsController.stLockedByPlan) {
      _planSheet(context, cap, unlock);
      return;
    }
    if (state == EntitlementsController.stLockedByRole) {
      _explain(
        context,
        title: '${cap['name'] ?? 'الخدمة'}',
        message:
            'هذه الخدمة ليست مشكلة اشتراك. تحتاج صلاحية من ${unlock?['ask'] ?? 'مالك المنشأة'}.',
      );
      return;
    }
    if (state == EntitlementsController.stLimitReached) {
      _explain(
        context,
        title: '${cap['name'] ?? 'الخدمة'}',
        message:
            'تم بلوغ الحد الحالي: ${usage?['used'] ?? 'غير معروف'} من ${usage?['max'] ?? 'غير معروف'}.'
            '${unlock?['plan_name'] != null ? ' يمكن رفع الحد عبر باقة ${unlock!['plan_name']}.' : ''}',
        actionLabel: unlock?['plan_code'] != null ? 'مقارنة الباقات' : null,
        onAction: unlock?['plan_code'] != null
            ? () => Get.to(() => PlansCatalogScreen(
                  suggestedPlan: '${unlock!['plan_code']}',
                ))
            : null,
      );
      return;
    }

    _explain(
      context,
      title: '${cap['name'] ?? 'الخدمة'}',
      message: 'حالة هذه الخدمة غير معروفة حالياً. أعد تحميل الصفحة قبل اتخاذ قرار.',
    );
  }

  void _planSheet(
    BuildContext context,
    Map<String, dynamic> cap,
    Map<String, dynamic>? unlock,
  ) {
    final suggested = unlock?['plan_code']?.toString();
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(AmialSpacing.radiusXl),
        ),
      ),
      builder: (sheetContext) => Padding(
        padding: const EdgeInsets.all(AmialSpacing.screen),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.diamond_outlined,
              color: AmialColors.warning,
              size: AmialSpacing.xxl,
            ),
            const SizedBox(height: AmialSpacing.sm),
            Text(
              '${cap['name'] ?? 'ميزة إضافية'}',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    color: AmialColors.textPrimary,
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: AmialSpacing.xs),
            Text(
              'متاحة مع باقة ${unlock?['plan_name'] ?? 'أعلى'}. راجع المقارنة قبل اتخاذ قرار الترقية.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AmialColors.textSecondary,
                    height: 1.5,
                  ),
            ),
            const SizedBox(height: AmialSpacing.lg),
            SizedBox(
              width: double.infinity,
              height: AmialSpacing.buttonHeight,
              child: FilledButton(
                onPressed: () {
                  Navigator.of(sheetContext).pop();
                  Get.to(() => PlansCatalogScreen(suggestedPlan: suggested));
                },
                style: FilledButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  foregroundColor: AmialColors.cardSurface,
                ),
                child: const Text('مقارنة الباقات'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _explain(
    BuildContext context, {
    required String title,
    required String message,
    String? actionLabel,
    VoidCallback? onAction,
  }) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(AmialSpacing.radiusXl),
        ),
      ),
      builder: (sheetContext) => Padding(
        padding: const EdgeInsets.all(AmialSpacing.screen),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              title,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    color: AmialColors.textPrimary,
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: AmialSpacing.sm),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AmialColors.textSecondary,
                    height: 1.55,
                  ),
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AmialSpacing.lg),
              SizedBox(
                width: double.infinity,
                height: AmialSpacing.buttonHeight,
                child: FilledButton(
                  onPressed: () {
                    Navigator.of(sheetContext).pop();
                    onAction();
                  },
                  child: Text(actionLabel),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  IconData _iconFor(String name) {
    switch (name) {
      case 'receipt_long':
        return Icons.receipt_long_outlined;
      case 'bolt':
        return Icons.bolt_outlined;
      case 'point_of_sale':
        return Icons.point_of_sale_outlined;
      case 'undo':
        return Icons.undo_outlined;
      case 'account_balance_wallet':
        return Icons.account_balance_wallet_outlined;
      case 'cloud_off':
        return Icons.cloud_off_outlined;
      case 'call_split':
        return Icons.call_split_outlined;
      case 'card_giftcard':
        return Icons.card_giftcard_outlined;
      case 'inventory_2':
        return Icons.inventory_2_outlined;
      case 'qr_code_scanner':
        return Icons.qr_code_scanner_outlined;
      case 'category':
        return Icons.category_outlined;
      case 'style':
        return Icons.style_outlined;
      case 'sell':
        return Icons.sell_outlined;
      case 'local_offer':
        return Icons.local_offer_outlined;
      case 'loyalty':
        return Icons.loyalty_outlined;
      case 'warehouse':
        return Icons.warehouse_outlined;
      case 'warning_amber':
        return Icons.warning_amber_outlined;
      case 'store_mall_directory':
        return Icons.store_mall_directory_outlined;
      case 'swap_horiz':
        return Icons.swap_horiz_outlined;
      case 'rule':
        return Icons.rule_outlined;
      case 'delete_outline':
        return Icons.delete_outline;
      case 'local_shipping':
        return Icons.local_shipping_outlined;
      case 'shopping_cart':
        return Icons.shopping_cart_outlined;
      case 'people':
        return Icons.people_outline;
      case 'badge':
        return Icons.badge_outlined;
      case 'admin_panel_settings':
        return Icons.admin_panel_settings_outlined;
      case 'devices':
        return Icons.devices_outlined;
      case 'schedule':
        return Icons.schedule_outlined;
      case 'today':
        return Icons.today_outlined;
      case 'trending_up':
        return Icons.trending_up_outlined;
      case 'analytics':
        return Icons.analytics_outlined;
      case 'grid_on':
        return Icons.grid_on_outlined;
      case 'payments':
        return Icons.payments_outlined;
      case 'fact_check':
        return Icons.fact_check_outlined;
      case 'backup':
        return Icons.backup_outlined;
      case 'account_tree':
        return Icons.account_tree_outlined;
      case 'leaderboard':
        return Icons.leaderboard_outlined;
      case 'currency_exchange':
        return Icons.currency_exchange_outlined;
      case 'event_repeat':
        return Icons.event_repeat_outlined;
      case 'local_gas_station':
        return Icons.local_gas_station_outlined;
      case 'ev_station':
        return Icons.ev_station_outlined;
      case 'medication':
        return Icons.medication_outlined;
      case 'event_busy':
        return Icons.event_busy_outlined;
      case 'description':
        return Icons.description_outlined;
      case 'request_quote':
        return Icons.request_quote_outlined;
      case 'price_change':
        return Icons.price_change_outlined;
      case 'table_restaurant':
        return Icons.table_restaurant_outlined;
      case 'api':
        return Icons.api_outlined;
      case 'business':
        return Icons.business_outlined;
      case 'credit_score':
        return Icons.credit_score_outlined;
      case 'supervisor_account':
        return Icons.supervisor_account_outlined;
      case 'account_balance':
        return Icons.account_balance_outlined;
      default:
        return Icons.widgets_outlined;
    }
  }
}
