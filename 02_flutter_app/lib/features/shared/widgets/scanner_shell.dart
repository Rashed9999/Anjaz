import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:permission_handler/permission_handler.dart';

import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SCANNER-SHELL-001 — غلافُ الماسح: الكاميرا تعمل، أو **تقول لماذا لا**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **العطل الذي وُلد منه هذا الملفّ:**
///
/// «كاميرا مسح باركود المنتج في الكاشير لا تعمل.»
///
/// وقِيس فوجد أنّ الخادمَ سليم (`products/lookup` مسجَّلٌ ويردّ)، وأنّ
/// الإذنين معلَنان في `AndroidManifest` و`Info.plist`، وأنّ إعدادَ
/// `MobileScannerController` مطابقٌ للماسحات الأخرى.
///
/// **والعطلُ في مكانٍ آخر تماماً: الشاشةُ لا تملك حالةَ فشل.**
///
/// `MobileScanner(controller: …, onDetect: …)` بلا `errorBuilder`. فحين
/// لا تبدأ الكاميرا — إذنٌ رُفض نهائيّاً، أو كاميرا مشغولةٌ بتطبيقٍ آخر،
/// أو جهازٌ لا يدعمها — **يُرسَم مستطيلٌ أسودُ فارغ**: لا رسالة، ولا رمز
/// خطأ، ولا طريقَ خروج. يفتح الكاشيرُ الشاشةَ فيرى سواداً ويقول «لا تعمل».
///
/// ══════════════════════════════════════════════════════════════════════
/// **وأخطرُ صوره: الرفضُ النهائيّ.**
///
/// من ضغط «رفض» مرّتين على أندرويد يصير الإذنُ `permanentlyDenied`،
/// **فلا يُطلب مرّةً أخرى أبداً**. والكاميرا لن تعمل بعدها مهما أُعيد فتحُ
/// الشاشة أو التطبيق — ولا سبيلَ إلّا إعداداتِ النظام. فبلا زرٍّ يقود
/// إليها **يبقى الجهازُ معطّلاً إلى الأبد** وصاحبُه يظنّ التطبيق معطوباً.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولمَ ملفٌّ واحدٌ لا إصلاحٌ في شاشة الكاشير وحدها:**
///
/// النقصُ نفسُه في `QrScannerScreen` أيضاً — وهي التي يُمسح بها رمزُ
/// التاجر. فإصلاحُ واحدةٍ يترك الأخرى تُظهر السوادَ نفسَه. (المهارة:
/// «Never duplicate UI. Create reusable components.»)
class ScannerShell extends StatefulWidget {
  const ScannerShell({
    super.key,
    required this.controller,
    required this.onDetect,
    this.overlay,
    this.onManualEntry,
    this.manualEntryLabel = 'إدخال الرقم يدويّاً',
  });

  final MobileScannerController controller;
  final void Function(BarcodeCapture) onDetect;

  /// ما يُرسم فوق الكاميرا (إطار التوجيه، شريط الحالة…).
  final Widget? overlay;

  /// **طريقُ الخروج حين تفشل الكاميرا.**
  ///
  /// وهو ليس تحسيناً: إضاءةٌ ضعيفةٌ عند صندوق، أو عدسةٌ مخدوشة، أو باركود
  /// مطبوعٌ بهت — كلُّها تقع كلَّ يوم. ومن لا طريقَ له إلّا الكاميرا يقف
  /// عاجزاً والزبونُ ينتظر.
  final Future<void> Function()? onManualEntry;
  final String manualEntryLabel;

  @override
  State<ScannerShell> createState() => _ScannerShellState();
}

enum _CamState { checking, granted, denied, permanentlyDenied, failed }

class _ScannerShellState extends State<ScannerShell> with WidgetsBindingObserver {
  _CamState _state = _CamState.checking;
  String _failure = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _ask();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// **ومن عاد من إعدادات النظام يجد الكاميرا تعمل — بلا إعادة فتح.**
  ///
  /// فلولا ذلك لمنح الإذنَ ثمّ رجع إلى شاشةٍ سوداء، فظنّ أنّ منحَه لم
  /// ينفع. (وقياسُ ما بعد الضغطة ليس «هل ظهر خطأ» بل «ماذا تغيّر».)
  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.resumed && _state != _CamState.granted) {
      _ask();
    }
  }

  Future<void> _ask() async {
    if (!mounted) return;
    setState(() => _state = _CamState.checking);

    try {
      final status = await Permission.camera.request();

      if (!mounted) return;

      if (status.isGranted || status.isLimited) {
        setState(() => _state = _CamState.granted);
      } else if (status.isPermanentlyDenied || status.isRestricted) {
        setState(() => _state = _CamState.permanentlyDenied);
      } else {
        setState(() => _state = _CamState.denied);
      }
    } catch (e) {
      // فشلُ سؤالِ الإذن نفسِه (منصّةٌ لا تدعمه) لا يُقفل الشاشة: تُجرَّب
      // الكاميرا، وإن فشلت قال `errorBuilder` سببَه.
      if (!mounted) return;
      setState(() => _state = _CamState.granted);
    }
  }

  @override
  Widget build(BuildContext context) {
    switch (_state) {
      case _CamState.checking:
        return const Center(child: CircularProgressIndicator());

      case _CamState.denied:
        return _message(
          icon: Icons.no_photography_outlined,
          title: 'إذن الكاميرا مرفوض',
          body: 'يحتاج المسحُ إلى الكاميرا. اسمح بالإذن لتعمل.',
          actionLabel: 'اسمح بالإذن',
          onAction: _ask,
        );

      case _CamState.permanentlyDenied:
        return _message(
          icon: Icons.settings_outlined,
          title: 'الإذن مرفوض نهائيّاً',
          body: 'لن يُطلب الإذنُ مرّةً أخرى من داخل التطبيق. افتح إعدادات '
              'النظام وفعّل الكاميرا، ثمّ ارجع — تعمل فوراً.',
          actionLabel: 'افتح الإعدادات',
          onAction: () async {
            await openAppSettings();
          },
        );

      case _CamState.failed:
        return _message(
          icon: Icons.videocam_off_outlined,
          title: 'تعذّر تشغيل الكاميرا',
          body: _failure.isEmpty ? 'سببٌ غير معروف من النظام.' : _failure,
          actionLabel: 'إعادة المحاولة',
          onAction: _ask,
        );

      case _CamState.granted:
        return Stack(children: [
          MobileScanner(
            controller: widget.controller,
            onDetect: widget.onDetect,

            // **وهذا هو الغائبُ الذي صنع العطل.**
            // بلا هذا البنّاء يُرسم سوادٌ صامتٌ عند أيّ فشل.
            errorBuilder: (context, error, child) {
              final code = error.errorCode;

              // خطأُ الإذن يُعالَج بشاشة الإذن لا برسالةِ خطأٍ عامّة.
              if (code == MobileScannerErrorCode.permissionDenied) {
                WidgetsBinding.instance.addPostFrameCallback((_) {
                  if (mounted) _ask();
                });
                return const Center(child: CircularProgressIndicator());
              }

              return _message(
                icon: Icons.videocam_off_outlined,
                title: 'تعذّر تشغيل الكاميرا',
                body: _describe(error),
                actionLabel: 'إعادة المحاولة',
                onAction: _ask,
              );
            },
          ),
          if (widget.overlay != null) widget.overlay!,
        ]);
    }
  }

  /// **ولا تُعرض رسالةُ النظام الخام** — تُترجم إلى ما يدلّ على فعل.
  String _describe(MobileScannerException e) {
    switch (e.errorCode) {
      case MobileScannerErrorCode.controllerUninitialized:
        return 'الماسح لم يبدأ بعد. أعد المحاولة.';
      case MobileScannerErrorCode.permissionDenied:
        return 'إذن الكاميرا مرفوض.';
      case MobileScannerErrorCode.unsupported:
        return 'هذا الجهاز لا يدعم المسح. استعمل الإدخال اليدويّ.';
      default:
        // قد تكون الكاميرا مشغولةً بتطبيقٍ آخر — وهو أشيعُ ما يقع.
        return 'قد تكون الكاميرا مشغولةً بتطبيقٍ آخر. أغلقه ثمّ أعد المحاولة.'
            '${e.errorDetails?.message != null ? '\n(${e.errorDetails!.message})' : ''}';
    }
  }

  Widget _message({
    required IconData icon,
    required String title,
    required String body,
    required String actionLabel,
    required Future<void> Function() onAction,
  }) {
    return Container(
      color: Colors.black,
      padding: const EdgeInsets.all(32),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: AmialColors.yellow),
            const SizedBox(height: 16),
            Text(title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text(body,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white70, fontSize: 13, height: 1.6)),
            const SizedBox(height: 24),
            FilledButton(onPressed: () => onAction(), child: Text(actionLabel)),

            // **وطريقُ الخروج يظهر مع كلّ فشل** — لا في القائمة العلويّة
            // وحدها حيث لا يبحث عنه من يرى شاشةً سوداء.
            if (widget.onManualEntry != null) ...[
              const SizedBox(height: 8),
              TextButton.icon(
                icon: const Icon(Icons.keyboard, color: Colors.white70, size: 18),
                label: Text(widget.manualEntryLabel,
                    style: const TextStyle(color: Colors.white70)),
                onPressed: () => widget.onManualEntry!(),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
