import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:amyal_pay/helper/amial_crash_reporter.dart';
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
      child: GestureDetector(
        // AMIAL-CRASH-004: ضغطة مطوّلة على رقم الإصدار تفتح التشخيص.
        //
        // مخفيّ عمداً بلا أثر بصريّ: العميل لا يجد ما يضغطه بالخطأ، ومن
        // يحتاجه يعرف مكانه. ورقم الإصدار موضعه الطبيعي — فأوّل سؤالين عند
        // أي عطل هما «أي نسخة؟» و«هل يصل التبليغ أصلاً؟».
        onLongPress: () => _showDiagnostics(context),
        behavior: HitTestBehavior.opaque,
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
      ),
    );
  }

  void _showDiagnostics(BuildContext context) {
    final active = AmialCrashReporter.isActive;

    showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (sheetContext) => Directionality(
        textDirection: TextDirection.rtl,
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(_label,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 15, fontWeight: FontWeight.w700)),
                const SizedBox(height: 14),

                // الحالة أوّلاً: لو كان التبليغ معطّلاً فإرسال تقرير لا يصل،
                // ولا فائدة من انتظاره على اللوحة.
                Row(children: [
                  Icon(active ? Icons.check_circle : Icons.cancel,
                      size: 18,
                      color: active
                          ? const Color(0xFF1B8A3A)
                          : const Color(0xFFDC0A0B)),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      active
                          ? 'تقارير الأعطال فعّالة على هذا الجهاز'
                          : 'التقارير غير فعّالة — لن يصل شيء إلى اللوحة',
                      style: const TextStyle(fontSize: 13),
                    ),
                  ),
                ]),
                const SizedBox(height: 18),

                if (active) ...[
                  ElevatedButton(
                    onPressed: () async {
                      await AmialCrashReporter.sendTestReport();
                      if (!sheetContext.mounted) return;
                      Navigator.of(sheetContext).pop();
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text(
                            'أُرسل تقرير اختبار. أغلق التطبيق وافتحه، '
                            'ثم انظر «Non-fatals» في لوحة Crashlytics.',
                            textDirection: TextDirection.rtl,
                          ),
                          duration: Duration(seconds: 6),
                        ),
                      );
                    },
                    child: const Text('إرسال تقرير اختبار'),
                  ),
                  const SizedBox(height: 6),
                  // المسار القاتل يلتقطه مُعالج أصلي غير الذي يرفع ما سبق،
                  // فنجاح أحدهما لا يثبت الآخر. ولهذا زرّان لا زرّ.
                  TextButton(
                    onPressed: () => _confirmCrash(sheetContext),
                    child: const Text('انهيار متعمَّد (يُغلق التطبيق)',
                        style: TextStyle(color: Color(0xFFDC0A0B), fontSize: 12)),
                  ),
                ] else
                  const Text(
                    'السبب غالباً أن Firebase لم يُهيَّأ على هذا الجهاز، '
                    'أو أن هذه نسخة تطوير — التقارير معطّلة فيها عمداً.',
                    style: TextStyle(fontSize: 12, height: 1.5),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  void _confirmCrash(BuildContext sheetContext) {
    showDialog<void>(
      context: sheetContext,
      builder: (dialogContext) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('انهيار متعمَّد'),
          content: const Text(
            'سيُغلق التطبيق فوراً. افتحه بعدها ليُرفع التقرير — '
            'الأعطال القاتلة تُرفع عند التشغيل التالي لا لحظة وقوعها.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('إلغاء'),
            ),
            TextButton(
              onPressed: AmialCrashReporter.forceCrash,
              child: const Text('أغلِق الآن',
                  style: TextStyle(color: Color(0xFFDC0A0B))),
            ),
          ],
        ),
      ),
    );
  }
}
