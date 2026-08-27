import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/entitlements/controllers/entitlements_controller.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';

/// AMIAL-WHOLESALE-SCOPE-001
///
/// هذه الأسطح تخص تاجر الجملة فقط. ولا نربط الشاشة باسم باقة ثابت في Flutter؛
/// الشاشة تطلب capability واحدة، وManifest الاستحقاقات من الخادم يقرر هل هي
/// متاحة، مقفلة بالباقة، مقفلة بالصلاحية، أو وصلت إلى حد الاستخدام.
///
/// بهذه الطريقة لو تغيّر تسعير أو نُقلت ميزة من STARTER إلى BUSINESS لا نحتاج
/// إصدار تطبيق جديد فقط لتغيير نص/شرط محلي.
enum WholesaleSurface {
  dashboard,
  products,
  customers,
  invoices,
  aging,
  refunds,
  lowStockAlerts,
  expiryAlerts,
  multiPricing,
  excelExport,
  suppliers,
  purchases,
}

extension WholesaleSurfacePolicy on WholesaleSurface {
  String? get capability => switch (this) {
        WholesaleSurface.dashboard => null,
        WholesaleSurface.products => 'products',
        WholesaleSurface.customers => 'customers',
        WholesaleSurface.invoices => 'wholesale_invoices',
        WholesaleSurface.aging => 'advanced_reports',
        WholesaleSurface.refunds => 'refunds',
        WholesaleSurface.lowStockAlerts => 'low_stock_alerts',
        WholesaleSurface.expiryAlerts => 'inventory',
        WholesaleSurface.multiPricing => 'wholesale_multi_pricing',
        WholesaleSurface.excelExport => 'excel_export',
        WholesaleSurface.suppliers => 'suppliers',
        WholesaleSurface.purchases => 'purchases',
      };

  String get title => switch (this) {
        WholesaleSurface.dashboard => 'مساحة تجارة الجملة',
        WholesaleSurface.products => 'المنتجات',
        WholesaleSurface.customers => 'العملاء',
        WholesaleSurface.invoices => 'الفواتير',
        WholesaleSurface.aging => 'تقادم الديون',
        WholesaleSurface.refunds => 'الاسترجاع',
        WholesaleSurface.lowStockAlerts => 'تنبيهات المخزون',
        WholesaleSurface.expiryAlerts => 'تنبيهات الصلاحية',
        WholesaleSurface.multiPricing => 'أسعار الجملة',
        WholesaleSurface.excelExport => 'تصدير Excel',
        WholesaleSurface.suppliers => 'الموردون',
        WholesaleSurface.purchases => 'المشتريات',
      };
}

class WholesaleEntitlementGate extends StatelessWidget {
  const WholesaleEntitlementGate({
    super.key,
    required this.surface,
    required this.child,
  });

  final WholesaleSurface surface;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final access = _accessController();
    if (access == null) {
      return const _WholesaleBlockedSurface(
        icon: Icons.sync_problem_rounded,
        title: 'تعذر التحقق من الحساب',
        message: 'خدمة الوصول غير جاهزة في هذه الجلسة. أعد فتح الحساب.',
      );
    }

    return Obx(() {
      // Deep-link بارد: لا نحكم من القيم الافتراضية قبل وصول /me/access.
      if (!access.isLoaded.value) {
        _scheduleAccessLoad(access);
        return const Scaffold(
          backgroundColor: AmialColors.background,
          body: Center(child: CircularProgressIndicator()),
        );
      }

      // أول حارس: هذه الواجهات لا تُستعمل كقالب عام لكل التجار.
      if (!access.isMerchant || !access.isWholesale) {
        return const _WholesaleBlockedSurface(
          icon: Icons.warehouse_outlined,
          title: 'واجهة خاصة بتاجر الجملة',
          message:
              'هذه الشاشة جزء من مساحة تجارة الجملة ولا تُعرض للتجزئة أو الصيدلية أو الوقود أو المطعم أو البيع السريع.',
        );
      }

      final capability = surface.capability;
      if (capability == null) return child;

      final entitlements = _entitlementsController();
      if (entitlements != null && entitlements.manifest.value == null) {
        _scheduleEntitlementsLoad(entitlements);

        // AccessController يحمل قائمة الميزات الفعلية أيضاً. نستعملها فقط
        // للسماح المؤقت إن كانت القدرة موجودة صراحةً؛ أما الغياب فلا نفسره
        // كقفل قبل وصول manifest لأنه قد يكون قفل باقة أو دور أو حد.
        if (access.has(capability)) return child;

        return const Scaffold(
          backgroundColor: AmialColors.background,
          body: Center(child: CircularProgressIndicator()),
        );
      }

      final row = entitlements == null ? null : _entitlementRow(entitlements, capability);
      final state = row?['state']?.toString();

      if (state == EntitlementsController.stAvailable ||
          (row == null && access.has(capability))) {
        return child;
      }

      if (state == EntitlementsController.stLockedByPlan) {
        final unlock = row?['unlock'] is Map
            ? Map<String, dynamic>.from(row!['unlock'] as Map)
            : const <String, dynamic>{};
        final planName =
            '${unlock['plan_name'] ?? unlock['plan_label'] ?? unlock['name'] ?? 'باقة أعلى'}';
        final suggested =
            '${unlock['plan_code'] ?? unlock['plan'] ?? unlock['code'] ?? ''}'.trim();

        return _WholesaleBlockedSurface(
          icon: Icons.workspace_premium_outlined,
          title: '${surface.title} متاحة في $planName',
          message:
              'نشاطك ما زال تجارة جملة، لكن عمق هذه الميزة يتبع الباقة الحالية. لن نعرض بيانات أو أزراراً توحي بأنها متاحة قبل استحقاقها.',
          actionLabel: 'مقارنة الباقات',
          onAction: () => Get.to(() => PlansCatalogScreen(
                suggestedPlan: suggested.isEmpty ? null : suggested,
              )),
        );
      }

      if (state == EntitlementsController.stLockedByRole) {
        return const _WholesaleBlockedSurface(
          icon: Icons.admin_panel_settings_outlined,
          title: 'تحتاج صلاحية',
          message:
              'الباقة تسمح بالميزة، لكن حساب الموظف الحالي لا يملك الصلاحية المطلوبة. يغيّرها مالك المنشأة أو مدير مخوّل.',
        );
      }

      if (state == EntitlementsController.stLimitReached) {
        final usage = row?['usage'] is Map
            ? Map<String, dynamic>.from(row!['usage'] as Map)
            : const <String, dynamic>{};
        return _WholesaleBlockedSurface(
          icon: Icons.data_usage_rounded,
          title: 'بلغت حد الباقة',
          message:
              'الاستخدام الحالي ${usage['used'] ?? '—'} من ${usage['max'] ?? '—'}. لا نسمح بإنشاء بيانات جديدة بعد الحد، مع بقاء البيانات السابقة محفوظة.',
          actionLabel: 'عرض الباقات',
          onAction: () => Get.to(() => const PlansCatalogScreen()),
        );
      }

      // لا نحوّل «غير معروف» إلى «مسموح» أو «مقفول»؛ كلاهما كذبة.
      return _WholesaleBlockedSurface(
        icon: Icons.help_outline_rounded,
        title: 'تعذر التحقق من ${surface.title}',
        message:
            'لم تصل حالة الاستحقاق من الخادم بعد. حدّث الصفحة أو أعد تسجيل الدخول قبل تنفيذ أي عملية.',
      );
    });
  }

  AccessController? _accessController() {
    try {
      return Get.find<AccessController>();
    } catch (_) {
      return null;
    }
  }

  EntitlementsController? _entitlementsController() {
    try {
      return Get.find<EntitlementsController>();
    } catch (_) {
      return null;
    }
  }

  Map<String, dynamic>? _entitlementRow(
    EntitlementsController controller,
    String capability,
  ) {
    final row = controller.stateOf(capability);
    return row == null ? null : Map<String, dynamic>.from(row);
  }

  void _scheduleAccessLoad(AccessController controller) {
    if (controller.isLoading.value) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!controller.isLoaded.value && !controller.isLoading.value) {
        controller.load();
      }
    });
  }

  void _scheduleEntitlementsLoad(EntitlementsController controller) {
    if (controller.isLoading.value) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (controller.manifest.value == null && !controller.isLoading.value) {
        controller.load();
      }
    });
  }
}

class _WholesaleBlockedSurface extends StatelessWidget {
  const _WholesaleBlockedSurface({
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('تجارة الجملة'),
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AmialSpacing.xl),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.all(AmialSpacing.xl),
            decoration: BoxDecoration(
              color: AmialColors.cardSurface,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
              border: Border.all(color: AmialColors.border),
              boxShadow: AmialSpacing.cardShadow,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 68,
                  height: 68,
                  decoration: BoxDecoration(
                    color: AmialColors.warningSurface,
                    borderRadius:
                        BorderRadius.circular(AmialSpacing.radiusLg),
                  ),
                  child: Icon(icon, color: AmialColors.warning, size: 34),
                ),
                const SizedBox(height: AmialSpacing.md),
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        color: AmialColors.textPrimary,
                        fontWeight: FontWeight.w900,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xs),
                Text(
                  message,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AmialColors.textSecondary,
                        height: 1.6,
                      ),
                ),
                if (actionLabel != null && onAction != null) ...[
                  const SizedBox(height: AmialSpacing.lg),
                  FilledButton.icon(
                    onPressed: onAction,
                    icon: const Icon(Icons.upgrade_rounded),
                    label: Text(actionLabel!),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
