import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_dashboard_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/offline_sales_screen.dart';
import 'package:amial_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_sale_screen.dart';
import 'package:amial_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amial_pay/features/setting/screens/profile_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_direction.dart';

/// ══════════════════════════════════════════════════════════════════════
/// AMIAL-POS-HOME-001 — **شاشةُ الكاشير. وقبلها لم تكن هناك شاشةُ كاشير.**
///
/// **الثمن، وقد كُشف بالقياس لا بالشكوى:**
///
/// `RoleRouter` يرسل `'pos'` إلى `HomeDispatcherScreen` كما يرسل
/// `'merchant'`. وكلُّ فرعٍ في المُرسِل يسأل `_access.isMerchant`، وهي
///
///     bool get isMerchant => role.value == 'merchant';
///
/// **ودورُ الكاشير `'pos'`.** فيسقط من الفروع الستّة كلِّها — الوقود
/// والصيدليّة والجملة والبيع السريع والتجزئة والمطعم — ومن `isAgent`
/// و`isAdmin`، إلى آخر سطرٍ في الدالّة:
///
///     return widget.userHomeFallback;      // ← MerchantDashboardScreen
///
/// **فيهبط الكاشيرُ في لوحة المالك، وخارجَ `_merchantShell`**: لا درجَ،
/// ولا مراكزَ قدرات، ولا زرَّ عودة. ويرى فيها «الرصيد المتاح» و«سحب
/// رصيدي» و«موظفو نقاط البيع» و«إعدادات المتجر» — **ثلاثةَ عشرَ رابطاً
/// لا يسأل واحدٌ منها عن صلاحيّة**، لأنّ تلك اللوحة لا تستورد
/// `AccessController` أصلاً.
///
/// ولا خطأَ في أيّ سجلّ: الشاشةُ تُبنى، والأزرارُ تُضغط، والخادمُ يردّ
/// ٤٠٣ حين تصل. **العطلُ في العرض لا في الحماية** — وهو ما يُقرأ
/// عشوائيّةً.
///
/// ══════════════════════════════════════════════════════════════════════
/// **وهذه الشاشةُ لا تخترع صلاحيّةً**: قائمتُها هي بعينها ما يحسبه
/// `FeatureAccessService::restrictToPosPermissions` في الخادم —
/// `$always` (البيعُ وما لا يقوم بدونه) ثمّ ما مُنح من الخمس. فما يُعرض
/// هنا هو ما يُقبل هناك، **والإخفاءُ راحةٌ للعين لا حماية**.
///
/// يظهر في : التطبيق ← أوّلُ شاشةٍ لموظّف نقطة البيع بعد الدخول برمزه.
/// ويُوصل إليه من : `HomeDispatcherScreen` حين يكون `actor == 'pos'`.
/// ══════════════════════════════════════════════════════════════════════
class PosEmployeeHomeScreen extends StatelessWidget {
  const PosEmployeeHomeScreen({super.key});

  AccessController get _access => Get.find<AccessController>();

  /// شاشةُ البيع تتبع صنفَ نشاط **صاحبه** — والكاشيرُ يرثه.
  ///
  /// فكاشيرُ محطّةِ وقودٍ لا يُفتح له كاشيرُ بقالة: `FuelSaleScreen`
  /// هي مسارُ البيع هناك، و`CashierPosScreen` تردّه إليها بحاجزٍ قطاعيّ
  /// في أوّل `build` لها. **ويُقصد الوصولُ من هنا مباشرةً** لا بالارتداد.
  Widget _sellScreen() {
    if (_access.isFuel) return const FuelSaleScreen();
    if (_access.isPharmacy) return const PharmacySaleScreen();
    return const CashierPosScreen();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: appTextDirection(),
      child: Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(
          title: Obx(() => Text(_access.posDisplayName.value ?? 'نقطة البيع')),
          actions: [
            IconButton(
              tooltip: 'إشعاراتي',
              icon: const Icon(Icons.notifications_none_rounded),
              onPressed: () => Get.to(() => const NotificationsCenterScreen()),
            ),
            IconButton(
              tooltip: 'تسجيل الخروج',
              icon: const Icon(Icons.logout_rounded),
              onPressed: () => _confirmLogout(context),
            ),
          ],
        ),
        body: Obx(() {
          final perms = _access.posPermissions;

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _IdentityCard(
                name: _access.posDisplayName.value ?? 'موظّف نقطة بيع',
                code: _access.posNumber.value,
                store: _access.businessName,
                businessLabel: _access.businessTypeLabel.value,
              ),
              const SizedBox(height: 18),

              // ── الإجراءُ الوحيدُ الكبير: البيع ──
              _BigSellButton(onTap: () => Get.to(_sellScreen)),
              const SizedBox(height: 18),

              const _SectionTitle('ما لا يُنزَع عن أيّ موظّف'),
              _Tile(
                icon: Icons.receipt_long_rounded,
                label: 'إيصالاتي',
                subtitle: 'إيصالاتُ ما بِعتَه — للطباعة وإعادة الإرسال',
                onTap: () => Get.to(() => const ReceiptsListScreen()),
              ),
              _Tile(
                icon: Icons.notifications_active_outlined,
                label: 'إشعاراتي',
                subtitle: 'ما وصلك من تنبيهاتٍ على هذا الحساب',
                onTap: () => Get.to(() => const NotificationsCenterScreen()),
              ),
              _Tile(
                icon: Icons.person_outline_rounded,
                label: 'ملفّي',
                subtitle: 'بياناتُك وكلمةُ مرورك ومحفظتُك الشخصيّة',
                onTap: () => Get.to(() => const ProfileScreen()),
              ),

              // ── ما مُنح من صلاحيّات ──
              //
              // **ويُشترط الاثنان معاً**: الصلاحيّةُ من المالك،
              // والميزةُ من باقة المتجر. فموظّفٌ مُنح «التقارير» في متجرٍ
              // على الباقة المجّانيّة لا تُفتح له — والقائمةُ التي يردّها
              // الخادمُ تقاطعُهما أصلاً.
              if (perms.contains('refund') && _access.has('refunds'))
                _Tile(
                  icon: Icons.assignment_return_outlined,
                  label: 'المرتجعات',
                  subtitle: 'استرجاعُ فاتورةٍ أو جزءٍ منها',
                  onTap: () => Get.to(() => const MerchantRefundScreen()),
                  granted: true,
                ),
              if (perms.contains('products') && _access.has('products'))
                _Tile(
                  icon: Icons.sell_outlined,
                  label: 'المنتجات والأسعار',
                  subtitle: 'كتالوجُ المتجر — عرضٌ وتعديلٌ حسب صلاحيّتك',
                  onTap: () => Get.to(() => const CashierProductsScreen()),
                  granted: true,
                ),
              if (perms.contains('reports') && _access.has('daily_reports'))
                _Tile(
                  icon: Icons.today_outlined,
                  label: 'تقرير اليوم',
                  subtitle: 'مبيعاتُ اليوم وعددُ الفواتير',
                  onTap: () => Get.to(() => const CashierReportScreen()),
                  granted: true,
                ),
              if (perms.contains('credit') && _access.has('debts'))
                _Tile(
                  icon: Icons.account_balance_wallet_outlined,
                  label: 'البيع الآجل والديون',
                  subtitle: 'من عليه دَينٌ وكم — وسدادٌ جزئيّ أو كامل',
                  onTap: () => Get.to(() => const CreditDashboardScreen()),
                  granted: true,
                ),

              // ── أدواتُ الورديّة ──
              if (_access.has('shift_close'))
                _Tile(
                  icon: Icons.point_of_sale_outlined,
                  label: 'ورديّتي وإقفال الصندوق',
                  subtitle: 'افتح ورديّتَك وأقفلها بجردِ نقدٍ في آخر اليوم',
                  onTap: () => Get.to(() => const CashierShiftScreen()),
                ),
              if (_access.has('offline_pos'))
                _Tile(
                  icon: Icons.cloud_off_rounded,
                  label: 'مبيعاتٌ دون اتّصال',
                  subtitle: 'ما بِعتَه والشبكةُ مقطوعة — يُرفَع عند عودتها',
                  onTap: () => Get.to(() => const OfflineSalesScreen()),
                ),

              const SizedBox(height: 8),
              const _ScopeNote(),
              const SizedBox(height: 24),
            ],
          );
        }),
      ),
    );
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: appTextDirection(),
        child: AlertDialog(
          title: const Text('تسجيل الخروج'),
          content: const Text('ستحتاج رمزَ الموظّف وكلمةَ المرور للدخول ثانيةً.'),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('إلغاء')),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
              child: const Text('خروج'),
            ),
          ],
        ),
      ),
    );

    if (ok == true && Get.isRegistered<AuthController>()) {
      await Get.find<AuthController>().logout();
    }
  }
}

// ═══════════════════════════════════════════════════════════════════════
// اللبنات
// ═══════════════════════════════════════════════════════════════════════

class _IdentityCard extends StatelessWidget {
  const _IdentityCard({
    required this.name,
    required this.code,
    required this.store,
    required this.businessLabel,
  });

  final String name;
  final String? code;
  final String store;
  final String? businessLabel;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [AmialColors.primaryDark, AmialColors.primary],
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(name,
            style: const TextStyle(
                color: Colors.white, fontSize: 19, fontWeight: FontWeight.bold)),
        const SizedBox(height: 3),
        Text('موظّف نقطة بيع · $store',
            style: const TextStyle(color: Colors.white70, fontSize: 12.5)),
        const SizedBox(height: 10),
        Wrap(spacing: 6, runSpacing: 6, children: [
          if (code != null && code!.isNotEmpty)
            _Chip('رمز الموظّف: $code', solid: true),
          if (businessLabel != null && businessLabel!.isNotEmpty)
            _Chip(businessLabel!),
        ]),
      ]),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip(this.text, {this.solid = false});

  final String text;
  final bool solid;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: solid ? AmialColors.yellow : Colors.white.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(text,
          style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.bold,
              color: solid ? AmialColors.yellowDark : Colors.white)),
    );
  }
}

class _BigSellButton extends StatelessWidget {
  const _BigSellButton({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AmialColors.primary,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Icon(Icons.point_of_sale_rounded,
                  color: Colors.white, size: 24),
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('بيعٌ جديد',
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 17,
                        fontWeight: FontWeight.bold)),
                Text('الكاشير — سلّةٌ ودفع',
                    style: TextStyle(color: Colors.white70, fontSize: 12)),
              ]),
            ),
            const Icon(Icons.chevron_left_rounded, color: Colors.white70),
          ]),
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(2, 4, 2, 8),
      child: Text(text,
          style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.bold,
              color: AmialColors.textMuted)),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.label,
    required this.subtitle,
    required this.onTap,
    this.granted = false,
  });

  final IconData icon;
  final String label;
  final String subtitle;
  final VoidCallback onTap;

  /// شارةُ «ممنوحة» — تُميّز ما منحه المالكُ عمّا هو أصليٌّ للوظيفة،
  /// فيعرف الموظّفُ أنّ زوالَه قرارُ صاحبه لا عطلٌ في التطبيق.
  final bool granted;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: AmialColors.border),
      ),
      child: ListTile(
        onTap: onTap,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        leading: Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(11),
          ),
          child: Icon(icon, color: AmialColors.primary, size: 20),
        ),
        title: Text(label,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: Text(subtitle,
            style: const TextStyle(fontSize: 11.5, color: AmialColors.textMuted)),
        trailing: granted
            ? Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AmialColors.successSurface,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: const Text('ممنوحة',
                    style: TextStyle(
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                        color: AmialColors.success)),
              )
            : const Icon(Icons.chevron_left_rounded,
                color: AmialColors.textMuted),
      ),
    );
  }
}

class _ScopeNote extends StatelessWidget {
  const _ScopeNote();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: const Border(
            right: BorderSide(color: AmialColors.yellowDark, width: 3)),
      ),
      child: const Text(
        'حسابُك للبيع. رصيدُ المتجر وسحبُه وإدارةُ الموظّفين وإعداداتُ '
        'المتجر لصاحبه وحدَه — ولا تُعرَض هنا لأنّها لا تُقبل هناك. '
        'وما تحتاجه ولا تجده يُطلَب من صاحب المتجر: يمنحه من «الموظفون» '
        'فيظهر لك عند أوّل فتحة.',
        style: TextStyle(fontSize: 11.5, height: 1.7, color: AmialColors.textMuted),
      ),
    );
  }
}
