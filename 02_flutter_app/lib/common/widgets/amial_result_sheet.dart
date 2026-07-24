import 'package:flutter/material.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/theme/amial_spacing.dart';
import 'package:amyal_pay/common/widgets/amial_button.dart';

/// AMYAL-DS-001 — ورقة النتيجة الموحّدة (Processing → Success / Failure).
///
/// نمط احترافي واحد لكل العمليات المالية (تحويل/سحب/دفع تاجر/شحن): تظهر ورقة
/// سفلية بيضاء بزوايا علوية ناعمة، تُظهر «جارٍ التنفيذ...» ثم تتحوّل تلقائياً إلى
/// نجاح (علامة خضراء) أو فشل (رسالة واضحة) حسب نتيجة العملية.
///
/// الاستخدام:
/// ```dart
/// final ok = await AmialResultSheet.run<bool>(
///   context,
///   action: () => controller.transfer(...),
///   processingTitle: 'جارٍ تنفيذ التحويل',
///   successTitle: 'تم التحويل بنجاح',
/// );
/// ```
class AmialResultSheet {
  /// يُشغّل [action] داخل ورقة تتحوّل تلقائياً. يُعيد نتيجة العملية عند النجاح،
  /// أو null عند الفشل/الإلغاء.
  static Future<T?> run<T>(
    BuildContext context, {
    required Future<T> Function() action,
    required String processingTitle,
    String processingSubtitle = 'يرجى الانتظار...',
    required String successTitle,
    String successSubtitle = '',
    String successButton = 'تم',
    String failureTitle = 'تعذّر إتمام العملية',
    String Function(Object error)? errorMessage,
  }) {
    return showModalBottomSheet<T>(
      context: context,
      isDismissible: false,
      enableDrag: false,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => PopScope(
        canPop: false,
        child: _ResultSheetBody<T>(
          action: action,
          processingTitle: processingTitle,
          processingSubtitle: processingSubtitle,
          successTitle: successTitle,
          successSubtitle: successSubtitle,
          successButton: successButton,
          failureTitle: failureTitle,
          errorMessage: errorMessage,
        ),
      ),
    );
  }
}

enum _Phase { processing, success, failure }

class _ResultSheetBody<T> extends StatefulWidget {
  final Future<T> Function() action;
  final String processingTitle;
  final String processingSubtitle;
  final String successTitle;
  final String successSubtitle;
  final String successButton;
  final String failureTitle;
  final String Function(Object error)? errorMessage;

  const _ResultSheetBody({
    required this.action,
    required this.processingTitle,
    required this.processingSubtitle,
    required this.successTitle,
    required this.successSubtitle,
    required this.successButton,
    required this.failureTitle,
    required this.errorMessage,
  });

  @override
  State<_ResultSheetBody<T>> createState() => _ResultSheetBodyState<T>();
}

class _ResultSheetBodyState<T> extends State<_ResultSheetBody<T>> {
  _Phase _phase = _Phase.processing;
  T? _result;
  Object? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _run());
  }

  Future<void> _run() async {
    try {
      final r = await widget.action();
      if (!mounted) return;
      setState(() {
        _result = r;
        _phase = _Phase.success;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _phase = _Phase.failure;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.only(
        left: AmialSpacing.xl,
        right: AmialSpacing.xl,
        top: AmialSpacing.sm,
        bottom: AmialSpacing.xl + MediaQuery.of(context).viewInsets.bottom,
      ),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // مقبض السحب
          Container(
            width: 44,
            height: 4,
            margin: const EdgeInsets.only(bottom: AmialSpacing.lg),
            decoration: BoxDecoration(
              color: AmyalColors.border,
              borderRadius: BorderRadius.circular(4),
            ),
          ),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 350),
            child: _buildPhase(context),
          ),
        ],
      ),
    );
  }

  Widget _buildPhase(BuildContext context) {
    switch (_phase) {
      case _Phase.processing:
        return _content(
          key: const ValueKey('processing'),
          icon: const _ProcessingIcon(),
          title: widget.processingTitle,
          subtitle: widget.processingSubtitle,
          titleColor: AmyalColors.textPrimary,
        );
      case _Phase.success:
        return _content(
          key: const ValueKey('success'),
          icon: const _StatusBadge(
              color: Color(0xFF1B9E4B), icon: Icons.check_rounded),
          title: widget.successTitle,
          subtitle: widget.successSubtitle,
          titleColor: AmyalColors.textPrimary,
          button: AmialButton(
            label: widget.successButton,
            kind: AmialButtonKind.dark,
            onPressed: () => Navigator.of(context).pop(_result),
          ),
        );
      case _Phase.failure:
        final msg = _error != null && widget.errorMessage != null
            ? widget.errorMessage!(_error!)
            : (_error?.toString() ?? 'حدث خطأ غير متوقّع');
        return _content(
          key: const ValueKey('failure'),
          icon: const _StatusBadge(
              color: AmyalColors.red, icon: Icons.close_rounded),
          title: widget.failureTitle,
          subtitle: msg,
          titleColor: AmyalColors.red,
          button: AmialButton(
            label: 'حسناً',
            kind: AmialButtonKind.outline,
            onPressed: () => Navigator.of(context).pop(null),
          ),
        );
    }
  }

  Widget _content({
    required Key key,
    required Widget icon,
    required String title,
    required String subtitle,
    required Color titleColor,
    Widget? button,
  }) {
    return Column(
      key: key,
      mainAxisSize: MainAxisSize.min,
      children: [
        const SizedBox(height: AmialSpacing.sm),
        icon,
        const SizedBox(height: AmialSpacing.lg),
        Text(
          title,
          textAlign: TextAlign.center,
          style: TextStyle(
              fontSize: 19, fontWeight: FontWeight.bold, color: titleColor),
        ),
        if (subtitle.isNotEmpty) ...[
          const SizedBox(height: AmialSpacing.xs),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 13.5, color: AmyalColors.textSecondary),
          ),
        ],
        if (button != null) ...[
          const SizedBox(height: AmialSpacing.xl),
          button,
        ],
      ],
    );
  }
}

/// أيقونة «قيد التنفيذ» — حلقة تحميل حول أيقونة إرسال.
class _ProcessingIcon extends StatelessWidget {
  const _ProcessingIcon();

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 72,
      height: 72,
      child: Stack(
        alignment: Alignment.center,
        children: const [
          SizedBox(
            width: 72,
            height: 72,
            child: CircularProgressIndicator(
                strokeWidth: 3, color: AmyalColors.primary),
          ),
          Icon(Icons.send_rounded, size: 30, color: AmyalColors.primary),
        ],
      ),
    );
  }
}

/// شارة حالة دائرية (نجاح/فشل) بحلقة خارجية ناعمة.
class _StatusBadge extends StatelessWidget {
  final Color color;
  final IconData icon;
  const _StatusBadge({required this.color, required this.icon});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 84,
      height: 84,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        shape: BoxShape.circle,
      ),
      child: Center(
        child: Container(
          width: 60,
          height: 60,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          child: Icon(icon, color: Colors.white, size: 34),
        ),
      ),
    );
  }
}
