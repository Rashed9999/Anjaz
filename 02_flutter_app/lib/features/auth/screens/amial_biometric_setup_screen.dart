import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:local_auth/local_auth.dart';
import 'package:amyal_pay/data/api/secure_storage_helper.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-BIO-001 — «تفعيل الدخول السريع» (بصمة الإصبع/الوجه).
///
/// تُعرض بعد أول دخول ناجح بكلمة المرور. عند التفعيل تُحفظ بيانات الدخول
/// مشفّرةً في التخزين الآمن للجهاز (Keystore/Keychain) ويُستخدم التحقق
/// الحيوي بدلاً من كتابة كلمة المرور في كل مرة.
class AmialBiometricSetupScreen extends StatefulWidget {
  const AmialBiometricSetupScreen({
    super.key,
    required this.phone,
    required this.password,
  });

  final String phone;
  final String password;

  /// مفاتيح التخزين الآمن.
  static const kEnabled = 'amial_bio_enabled';
  static const kPhone = 'amial_bio_phone';
  static const kPassword = 'amial_bio_password';

  /// هل الدخول السريع مفعّل؟
  static Future<bool> isEnabled() async =>
      (await SecureStorageHelper.instance.getSecure(kEnabled)) == '1';

  /// بيانات الدخول المحفوظة (بعد تحقق حيوي ناجح فقط).
  static Future<(String, String)?> savedCredentials() async {
    final p = await SecureStorageHelper.instance.getSecure(kPhone);
    final w = await SecureStorageHelper.instance.getSecure(kPassword);
    if (p == null || w == null || p.isEmpty || w.isEmpty) return null;
    return (p, w);
  }

  @override
  State<AmialBiometricSetupScreen> createState() =>
      _AmialBiometricSetupScreenState();
}

class _AmialBiometricSetupScreenState extends State<AmialBiometricSetupScreen> {
  bool _busy = false;

  Future<void> _enable() async {
    setState(() => _busy = true);
    try {
      final auth = LocalAuthentication();
      final supported =
          await auth.canCheckBiometrics || await auth.isDeviceSupported();
      if (!supported) {
        _snack('جهازك لا يدعم البصمة أو التعرف على الوجه');
        return;
      }
      final ok = await auth.authenticate(
        localizedReason: 'فعّل الدخول السريع إلى أميال باي',
        biometricOnly: true,
        persistAcrossBackgrounding: true,
      );
      if (!ok) return;

      final s = SecureStorageHelper.instance;
      await s.setSecure(AmialBiometricSetupScreen.kPhone, widget.phone);
      await s.setSecure(AmialBiometricSetupScreen.kPassword, widget.password);
      await s.setSecure(AmialBiometricSetupScreen.kEnabled, '1');
      if (mounted) Get.back(result: true);
    } catch (_) {
      _snack('تعذّر التحقق الحيوي — حاول مجدداً');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF2F5F3),
      appBar: AppBar(
        backgroundColor: Colors.white,
        foregroundColor: AmyalColors.primary,
        elevation: 0,
        title: const Text('Amyal Pay',
            style: TextStyle(fontWeight: FontWeight.bold)),
        centerTitle: true,
        leading: IconButton(
          icon: const Icon(Icons.arrow_forward),
          onPressed: () => Get.back(result: false),
        ),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(children: [
          const SizedBox(height: 12),
          // ====== بطاقة البصمة ======
          Container(
            height: 150,
            width: 150,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(32),
              boxShadow: [
                BoxShadow(
                    color: Colors.black.withValues(alpha: 0.08),
                    blurRadius: 22,
                    offset: const Offset(0, 8)),
              ],
            ),
            child: const Icon(Icons.fingerprint,
                size: 84, color: AmyalColors.primary),
          ),
          const SizedBox(height: 28),
          const Text('تفعيل الدخول السريع',
              style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: AmyalColors.primary)),
          const SizedBox(height: 12),
          const Text(
            'استمتع بوصول آمن وفوري إلى حسابك باستخدام بصمة الإصبع أو بصمة '
            'الوجه. لا حاجة لإدخال كلمة المرور في كل مرة.',
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 14, color: AmyalColors.textSecondary, height: 1.7),
          ),
          const SizedBox(height: 28),

          // ====== المزايا ======
          Row(children: [
            Expanded(child: _benefit(Icons.speed_rounded, 'سرعة فائقة')),
            const SizedBox(width: 12),
            Expanded(child: _benefit(Icons.shield_outlined, 'أمان عالي')),
          ]),
          const SizedBox(height: 48),

          // ====== تفعيل الآن ======
          FilledButton.icon(
            onPressed: _busy ? null : _enable,
            icon: _busy
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.fingerprint),
            label: const Text('تفعيل الآن',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(56),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(18)),
            ),
          ),
          const SizedBox(height: 12),
          TextButton(
            onPressed: () => Get.back(result: false),
            child: const Text('تخطى الآن',
                style: TextStyle(
                    color: AmyalColors.primary,
                    fontSize: 15,
                    fontWeight: FontWeight.w600)),
          ),
          const SizedBox(height: 16),
          const Text(
            'بياناتك الحيوية مشفرة بالكامل على جهازك، ولا تتم مشاركتها مع أي جهة خارجية.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
          ),
        ]),
      ),
    );
  }

  Widget _benefit(IconData icon, String label) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(children: [
        Icon(icon, color: AmyalColors.primary, size: 26),
        const SizedBox(height: 8),
        Text(label,
            style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w600,
                color: AmyalColors.primary)),
      ]),
    );
  }
}
