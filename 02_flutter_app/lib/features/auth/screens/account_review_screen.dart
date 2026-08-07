import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/screens/unified_login_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-VERIFY-GATE — شاشة حالة الحساب غير المعتمد.
///
/// تُعرض بعد دخول ناجح لحساب لم تعتمده لوحة «التحقق» بعد (قيد المراجعة)
/// أو رُفض — بدل فتح شاشة رئيسية ناقصة صامتة. صريحة ومطمئنة، وتشرح
/// للمستخدم أين هو في المسار وما الخطوة التالية.
class AccountReviewScreen extends StatelessWidget {
  /// 'pending_review' أو 'rejected'
  final String state;
  final String userName;

  const AccountReviewScreen({
    super.key,
    required this.state,
    this.userName = '',
  });

  bool get _rejected => state == 'rejected';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  height: 120,
                  width: 120,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: _rejected
                        ? AmialColors.red.withValues(alpha: 0.12)
                        : AmialColors.yellow.withValues(alpha: 0.25),
                    borderRadius: BorderRadius.circular(30),
                  ),
                  child: Icon(
                    _rejected
                        ? Icons.gpp_bad_outlined
                        : Icons.hourglass_top_rounded,
                    size: 60,
                    color: _rejected ? AmialColors.red : AmialColors.primary,
                  ),
                ),
                const SizedBox(height: 24),
                Text(
                  _rejected ? 'تعذّر اعتماد حسابك' : 'حسابك قيد المراجعة',
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    color: AmialColors.primary,
                  ),
                  textAlign: TextAlign.center,
                ),
                if (userName.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Text(
                    'مرحباً $userName',
                    style: const TextStyle(color: AmialColors.textSecondary),
                  ),
                ],
                const SizedBox(height: 16),
                Text(
                  _rejected
                      ? 'راجعنا وثائقك ولم نتمكّن من اعتمادها. يرجى التواصل مع '
                          'الدعم أو إعادة التسجيل بوثائق واضحة وسليمة.'
                      : 'استلمنا بياناتك ووثائقك، وفريق التحقّق يراجعها الآن '
                          'لضمان أمان حسابك. تستغرق المراجعة عادةً بين 24 و48 '
                          'ساعة عمل، وستصلك رسالة فور اعتماد الحساب.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: AmialColors.textSecondary,
                    height: 1.7,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 28),

                // خطوات المسار
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: AmialColors.border),
                  ),
                  child: Column(
                    children: [
                      _step(
                        icon: Icons.person_add_alt_1_rounded,
                        title: 'تسجيل الحساب',
                        done: true,
                      ),
                      _line(),
                      _step(
                        icon: _rejected
                            ? Icons.close_rounded
                            : Icons.search_rounded,
                        title: _rejected
                            ? 'المراجعة (لم تُعتمد)'
                            : 'مراجعة الوثائق',
                        done: false,
                        active: !_rejected,
                        failed: _rejected,
                      ),
                      _line(),
                      _step(
                        icon: Icons.verified_user_outlined,
                        title: 'تفعيل الحساب بالكامل',
                        done: false,
                        dimmed: true,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 28),

                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: () =>
                        Get.offAll(() => const UnifiedLoginScreen()),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmialColors.primary,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 15),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14)),
                    ),
                    child: const Text('العودة لتسجيل الدخول'),
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  'يمكنك تسجيل الدخول لاحقاً للتحقّق من حالة حسابك.',
                  style: TextStyle(
                    fontSize: 12,
                    color: AmialColors.textMuted,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _step({
    required IconData icon,
    required String title,
    required bool done,
    bool active = false,
    bool failed = false,
    bool dimmed = false,
  }) {
    final Color bg;
    final Color fg;
    if (done) {
      bg = Colors.green.withValues(alpha: 0.15);
      fg = Colors.green.shade700;
    } else if (failed) {
      bg = AmialColors.red.withValues(alpha: 0.15);
      fg = AmialColors.red;
    } else if (active) {
      bg = AmialColors.yellow.withValues(alpha: 0.3);
      fg = AmialColors.primary;
    } else {
      bg = const Color(0xFFF0F1F3);
      fg = AmialColors.textMuted;
    }
    return Row(
      children: [
        Container(
          height: 40,
          width: 40,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
          child: Icon(done ? Icons.check_rounded : icon, color: fg, size: 20),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Text(
            title,
            style: TextStyle(
              fontWeight: FontWeight.w600,
              color: dimmed ? AmialColors.textMuted : AmialColors.textPrimary,
            ),
          ),
        ),
      ],
    );
  }

  Widget _line() => Padding(
        padding: const EdgeInsets.only(right: 19),
        child: Container(
          height: 20,
          width: 2,
          color: AmialColors.border,
        ),
      );
}
