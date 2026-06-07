import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/amyal/controllers/amyal_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMYAL-LEGAL-001 (v0.7-D)
///
/// شاشة سياسة الاستخدام الإلزامية.
/// تُعرض:
///   - بعد التسجيل الأول
///   - عند login لو الـ user لم يقبل آخر إصدار
///   - عند تلقي HTTP 403 بـ code TERMS_ACCEPTANCE_REQUIRED من أي endpoint مالي
///
/// **مهم:** زر القبول لا يُفعَّل إلا بعد:
///   1. القراءة (scroll بنسبة 80%+) ← اختياري لكن موصى به قانونياً
///   2. تفعيل checkbox "قرأت ووافقت"
///
/// لا يمكن للمستخدم تخطّي هذه الشاشة بدون قبول.
class TermsAcceptanceScreen extends StatefulWidget {
  /// لو true: يتم force عرض الشاشة ولا يمكن للـ user الـ back
  /// (للحالة الإلزامية بعد login).
  final bool mandatory;

  /// يُستدعى عند نجاح القبول.
  final VoidCallback? onAccepted;

  const TermsAcceptanceScreen({
    super.key,
    this.mandatory = true,
    this.onAccepted,
  });

  @override
  State<TermsAcceptanceScreen> createState() => _TermsAcceptanceScreenState();
}

class _TermsAcceptanceScreenState extends State<TermsAcceptanceScreen> {
  final ScrollController _scrollController = ScrollController();
  bool _scrolledEnough = false;
  bool _checkboxChecked = false;

  @override
  void initState() {
    super.initState();
    // تحميل النص الحالي
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<AmyalController>().loadCurrentTerm();
    });
    _scrollController.addListener(_onScroll);
  }

  void _onScroll() {
    if (!_scrolledEnough &&
        _scrollController.hasClients &&
        _scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent * 0.8) {
      setState(() => _scrolledEnough = true);
    }
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  bool get _canAccept => _scrolledEnough && _checkboxChecked;

  Future<void> _onAcceptPressed() async {
    final ctrl = Get.find<AmyalController>();
    final ok = await ctrl.acceptCurrentTerm();
    if (!mounted) return;

    if (ok) {
      widget.onAccepted?.call();
      if (!widget.mandatory && Navigator.canPop(context)) {
        Navigator.pop(context, true);
      }
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ctrl.lastError.value.isNotEmpty
              ? ctrl.lastError.value
              : 'فشل القبول، حاول مرة أخرى'),
          backgroundColor: AmyalColors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !widget.mandatory,
      child: Scaffold(
        backgroundColor: AmyalColors.background,
        appBar: AppBar(
          backgroundColor: AmyalColors.yellow,
          foregroundColor: AmyalColors.primary,
          elevation: 0,
          automaticallyImplyLeading: !widget.mandatory,
          title: const Text(
            'سياسة الاستخدام',
            style: TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
        body: Obx(() {
          final ctrl = Get.find<AmyalController>();
          if (ctrl.isLoading.value && ctrl.currentTerm.value == null) {
            return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary),
            );
          }

          final term = ctrl.currentTerm.value;
          if (term == null) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.error_outline,
                        size: 64, color: AmyalColors.red),
                    const SizedBox(height: 16),
                    Text(
                      ctrl.lastError.value.isNotEmpty
                          ? ctrl.lastError.value
                          : 'لا يمكن تحميل السياسة',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: () => ctrl.loadCurrentTerm(),
                      child: const Text('إعادة المحاولة'),
                    ),
                  ],
                ),
              ),
            );
          }

          return Column(
            children: [
              // Header مع نسخة الإصدار
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                color: AmyalColors.yellow.withValues(alpha: 0.3),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      term.title,
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                        color: AmyalColors.primary,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'الإصدار ${term.version} — ${term.locale.toUpperCase()}',
                      style: TextStyle(
                        fontSize: 12,
                        color: AmyalColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),

              // نص السياسة (scrollable)
              Expanded(
                child: SingleChildScrollView(
                  controller: _scrollController,
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (term.changelog != null && term.changelog!.isNotEmpty)
                        Container(
                          margin: const EdgeInsets.only(bottom: 16),
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: AmyalColors.yellow.withValues(alpha: 0.15),
                            border: Border.all(color: AmyalColors.yellow),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Icon(Icons.info_outline,
                                  color: AmyalColors.primary, size: 20),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'ما الجديد في هذا الإصدار؟',
                                      style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          color: AmyalColors.primary),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(term.changelog!),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      // نص السياسة الكامل
                      // ملاحظة: للأمان، نعرضه كنص بسيط، لا HTML
                      // لو الـ backend يرسل Markdown، نضيف flutter_markdown لاحقاً
                      Text(
                        term.content,
                        style: const TextStyle(fontSize: 14, height: 1.6),
                      ),
                      const SizedBox(height: 24),
                    ],
                  ),
                ),
              ),

              // مؤشر الـ scroll
              if (!_scrolledEnough)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  color: AmyalColors.background,
                  child: Row(
                    children: [
                      const Icon(Icons.arrow_downward,
                          size: 16, color: AmyalColors.textSecondary),
                      const SizedBox(width: 8),
                      Text(
                        'الرجاء قراءة السياسة كاملة',
                        style: TextStyle(
                            fontSize: 12, color: AmyalColors.textSecondary),
                      ),
                    ],
                  ),
                ),

              // Checkbox + زر القبول
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.05),
                      blurRadius: 8,
                      offset: const Offset(0, -2),
                    ),
                  ],
                ),
                child: SafeArea(
                  top: false,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      CheckboxListTile(
                        controlAffinity: ListTileControlAffinity.leading,
                        value: _checkboxChecked,
                        onChanged: _scrolledEnough
                            ? (v) => setState(() => _checkboxChecked = v ?? false)
                            : null,
                        activeColor: AmyalColors.primary,
                        contentPadding: EdgeInsets.zero,
                        title: const Text(
                          'قرأت ووافقت على شروط الاستخدام',
                          style: TextStyle(fontSize: 14),
                        ),
                      ),
                      const SizedBox(height: 8),
                      ElevatedButton(
                        onPressed:
                            _canAccept && !ctrl.isLoading.value ? _onAcceptPressed : null,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AmyalColors.primary,
                          foregroundColor: Colors.white,
                          disabledBackgroundColor: AmyalColors.textMuted,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(8),
                          ),
                        ),
                        child: ctrl.isLoading.value
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Text(
                                'موافق ومتابعة',
                                style: TextStyle(
                                    fontSize: 16, fontWeight: FontWeight.w600),
                              ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          );
        }),
      ),
    );
  }
}
