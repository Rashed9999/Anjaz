import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-BUILD-STAMP-002 — بصمة البناء: أيّ نسخة يشغّلها هذا الجهاز فعلاً.
///
/// المشكلة التي تحلّها: عند كل بناء يظهر السؤال «أين التعديلات؟ لا شيء تغيّر».
/// السبب في كل مرة كان واحداً — الـ APK بُني من التزام أقدم من العمل المدفوع.
/// وبلا رقم إصدار ظاهر، لا سبيل للتمييز بين «الكود لم يصل» و«الكود وصل ولم
/// يُعجب». هذان تشخيصان مختلفان تماماً ولا يُحلّان بنفس الطريقة.
///
/// `PackageInfo` يقرأ الرقم من `pubspec.yaml` وقت البناء — أي أنه مرتبط
/// حتمياً بنفس الالتزام الذي أنتج الكود. فإن ظهر 1.61.0 فكل ما دون 1.61.0
/// موجود في هذا الـ APK قطعاً.
///
/// موضوعة في شاشتين: الدخول (قبل الدخول) و«حسابي» (بعده) — لأن شاشة الدخول
/// محميّة بـ FLAG_SECURE فلا يمكن تصويرها للمراجعة.
class AmialBuildStamp extends StatefulWidget {
  /// `true` للاستعمال فوق خلفية داكنة (شاشة الدخول).
  final bool onDark;
  const AmialBuildStamp({super.key, this.onDark = false});

  @override
  State<AmialBuildStamp> createState() => _AmialBuildStampState();
}

class _AmialBuildStampState extends State<AmialBuildStamp> {
  String _label = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final info = await PackageInfo.fromPlatform();
      if (!mounted) return;
      setState(() => _label = 'الإصدار ${info.version} (${info.buildNumber})');
    } catch (_) {
      if (mounted) setState(() => _label = '');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_label.isEmpty) return const SizedBox(height: 14);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Text(
        _label,
        textAlign: TextAlign.center,
        textDirection: TextDirection.rtl,
        style: TextStyle(
          color: widget.onDark
              ? Colors.white.withValues(alpha: 0.6)
              : AmyalColors.textMuted,
          fontSize: 11,
          fontWeight: FontWeight.w500,
        ),
      ),
    );
  }
}
