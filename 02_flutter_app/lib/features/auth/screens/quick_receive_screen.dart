import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:flutter/material.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/features/auth/domain/quick_receive_preferences.dart';
import 'package:amial_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/app_direction.dart';

/// AMIAL-QUICK-RECEIVE-002
///
/// سطح «استلام فقط» يمكن فتحه قبل تسجيل الدخول، لكن فقط بعد تفعيل المالك
/// الميزة صراحةً على الجهاز. لا يعرض الرصيد أو الحركات أو الإرسال أو السحب،
/// ولا يستخدم رقم الهاتف كحمولة QR.
class QuickReceiveScreen extends StatelessWidget {
  const QuickReceiveScreen({
    super.key,
    required this.displayName,
    required this.paymentAddress,
  });

  /// يُبقيان في العقد مؤقتاً للتوافق مع مسار فتح الشاشة القائم. المصدر
  /// الموثوق للعرض هو QuickReceivePreferences؛ لذلك لا نستعمل phone fallback.
  final String displayName;
  final String paymentAddress;

  String _maskedAddress(String value) {
    final address = value.trim();
    if (address.length <= 6) return '••••••';
    return '••••••${address.substring(address.length - 6)}';
  }

  String _privateName(String value) {
    final parts = value
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList();
    if (parts.isEmpty) return 'حساب أميال';
    if (parts.length == 1) return parts.first;
    final initial = parts[1].substring(0, 1);
    return '${parts.first} $initial.';
  }

  ({String displayName, String receiveAddress, String ownerPhone})?
      _trustedData() {
    final saved = QuickReceivePreferences.read();
    if (saved == null) return null;

    final last = UnifiedAuthController.readLastUser();
    if (last == null || last.kind != 'customer') return null;

    // AMIAL-QUICK-RECEIVE-004 — **ولا تُقارَن الأرقامُ حرفيّاً ها هنا.**
    //
    // المخزَّنُ يأتي من الملفّ الشخصيّ (`967777100001`)، والأخيرُ يأتي
    // **كما كتبه صاحبُه في الدخول** (`777100001`) — فالمقارنةُ الحرفيّةُ
    // تقول «مختلفان» في كلّ مرّة، فتردّ هذه الدالّةُ `null` وتُعرَض
    // حالةُ «مُعطَّل» **والميزةُ مفعَّلة**. وهو ما شُكي منه: «لا تعمل».
    //
    // والمقارنةُ الصحيحةُ كانت مكتوبةً في `disableIfOwnedByAnother` منذ
    // AMIAL-QUICK-RECEIVE-003 — **وبابٌ ثانٍ تُرك على الحرفيّة**.
    // (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها.)
    if (!QuickReceivePreferences.isSameOwner(
      storedOwner: saved.ownerPhone,
      currentPhone: last.phone,
    )) {
      return null;
    }
    return saved;
  }

  @override
  Widget build(BuildContext context) {
    final data = _trustedData();

    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('الاستلام السريع'),
      ),
      body: SafeArea(
        child: Directionality(
          textDirection: appTextDirection(),
          child: data == null
              ? _disabledState(context)
              : _enabledState(
                  context,
                  displayName: data.displayName,
                  receiveAddress: data.receiveAddress,
                ),
        ),
      ),
    );
  }

  Widget _disabledState(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AmialSpacing.screen),
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
                width: AmialSpacing.xxl * 2,
                height: AmialSpacing.xxl * 2,
                decoration: BoxDecoration(
                  color: AmialColors.warningSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                ),
                child: const Icon(
                  Icons.qr_code_2_rounded,
                  color: AmialColors.warning,
                  size: AmialSpacing.xxl,
                ),
              ),
              const SizedBox(height: AmialSpacing.lg),
              Text(
                'الاستلام السريع غير مفعّل على هذا الجهاز',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      color: AmialColors.textPrimary,
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: AmialSpacing.xs),
              Text(
                'ادخل إلى حساب العميل مرة واحدة، افتح رمز الاستلام من ملفك، '
                'ثم فعّل «الاستلام السريع على هذا الجهاز».',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: AmialColors.textSecondary,
                      height: 1.6,
                    ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _enabledState(
    BuildContext context, {
    required String displayName,
    required String receiveAddress,
  }) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(AmialSpacing.screen),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: AmialSpacing.xxl * 2.25 * AmialBrandLogo.lockupAspect,
              height: AmialSpacing.xxl * 2.25,
              padding: const EdgeInsets.all(AmialSpacing.xs),
              decoration: BoxDecoration(
                color: AmialColors.yellow,
                borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                boxShadow: AmialSpacing.cardShadow,
              ),
              child: const AmialBrandLogo(fit: BoxFit.contain),
            ),
          ),
          const SizedBox(height: AmialSpacing.lg),
          Text(
            _privateName(displayName),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  color: AmialColors.textPrimary,
                  fontWeight: FontWeight.w900,
                ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            'استلام الأموال فقط — دون فتح المحفظة',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AmialColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
          const SizedBox(height: AmialSpacing.xl),
          Container(
            padding: const EdgeInsets.all(AmialSpacing.xl),
            decoration: BoxDecoration(
              color: AmialColors.cardSurface,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
              border: Border.all(color: AmialColors.border),
              boxShadow: AmialSpacing.cardShadow,
            ),
            child: Column(
              children: [
                QrDisplayWidget(
                  data: receiveAddress,
                  size: AmialSpacing.xxl * 7,
                ),
                const SizedBox(height: AmialSpacing.md),
                Text(
                  'اطلب من المرسل مسح الرمز',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        color: AmialColors.primary,
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xs),
                Text(
                  'معرّف الاستلام: ${_maskedAddress(receiveAddress)}',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AmialColors.textMuted,
                      ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AmialSpacing.lg),
          Container(
            padding: const EdgeInsets.all(AmialSpacing.md),
            decoration: BoxDecoration(
              color: AmialColors.successSurface,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
              border: Border.all(
                color: AmialColors.success.withValues(alpha: 0.24),
              ),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(
                  Icons.verified_user_outlined,
                  color: AmialColors.success,
                ),
                const SizedBox(width: AmialSpacing.sm),
                Expanded(
                  child: Text(
                    'هذا السطح لا يفتح الحساب ولا يسمح بأي خصم مالي. '
                    'التحويل يُنفَّذ على خادم أميال، وقد يتأخر إشعار الهاتف '
                    'فقط حتى عودة الاتصال.',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AmialColors.textSecondary,
                          height: 1.6,
                        ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
