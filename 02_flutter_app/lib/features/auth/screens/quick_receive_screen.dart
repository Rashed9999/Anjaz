import 'package:flutter/material.dart';
import 'package:amial_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/images.dart';

/// AMIAL-QUICK-RECEIVE-001
///
/// سطح استلام فقط، يعمل قبل تسجيل الدخول على جهاز سبق لصاحب الحساب أن دخل منه.
/// لا يعرض الرصيد أو الحركات أو أي أداة مالية أخرى. الحمولة هي عنوان الاستلام
/// المحلي المحفوظ لهذا المستخدم، وهي نفس آلية fallback الحالية في QR الملف.
class QuickReceiveScreen extends StatelessWidget {
  const QuickReceiveScreen({
    super.key,
    required this.displayName,
    required this.paymentAddress,
  });

  final String displayName;
  final String paymentAddress;

  String get _maskedAddress {
    final value = paymentAddress.trim();
    if (value.length <= 4) return value;
    final tail = value.substring(value.length - 4);
    return '••••$tail';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        title: const Text('الاستلام السريع'),
        centerTitle: true,
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AmialSpacing.screen),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: AmialSpacing.xxl * 2.25,
                  height: AmialSpacing.xxl * 2.25,
                  padding: const EdgeInsets.all(AmialSpacing.xs),
                  decoration: BoxDecoration(
                    color: AmialColors.yellow,
                    borderRadius:
                        BorderRadius.circular(AmialSpacing.radiusLg),
                  ),
                  child: Image.asset(Images.logo, fit: BoxFit.contain),
                ),
              ),
              const SizedBox(height: AmialSpacing.lg),
              Text(
                displayName.trim().isEmpty ? 'حساب أميال' : displayName.trim(),
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      color: AmialColors.textPrimary,
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: AmialSpacing.xs),
              Text(
                'استلام الأموال فقط — لا يلزم فتح المحفظة',
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
                      data: paymentAddress,
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
                      'عنوان الاستلام: $_maskedAddress',
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
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.verified_user_outlined,
                        color: AmialColors.success),
                    const SizedBox(width: AmialSpacing.sm),
                    Expanded(
                      child: Text(
                        'هذه الشاشة لا تسمح بالإرسال أو السحب ولا تكشف رصيدك. '
                        'بعد وصول التحويل سيصل إشعار إلى الجهاز عند توفر الشبكة.',
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
        ),
      ),
    );
  }
}
