import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-DS-002 — نظام النماذج الموحّد.
///
/// مأخوذ من قواعد مرصودة في محافظ يمنية مهنية، وتختلف جوهرياً عمّا كنّا
/// نستعمله (`OutlineInputBorder` + `labelText` عائم):
///
///   • الحقل **معبّأ بلا حدّ**: خلفية رمادية فاتحة، زوايا 14.
///   • **التسمية ثابتة فوق القيمة** بخطّ كحلي عريض صغير — لا تسمية عائمة
///     تقفز عند الكتابة وتختفي عند الامتلاء.
///   • الحقل المُركَّز يقلب إلى **أبيض بحدّ كحلي** — إشارة تركيز واضحة.
///   • الإجراءات المساعدة (مسح QR، دليل الهاتف، إملاء صوتي) **خارج الحقل**
///     في مربّع أبيض مستقلّ، لا كأيقونة مزدحمة داخله.
///   • الحقول القصيرة المترابطة في صفّ واحد (مبلغ + عملة).
///
/// الفائدة الهندسية: شكل واحد لكل حقل في التطبيق، وتغييره لاحقاً في ملف واحد.
class AmialFormField extends StatefulWidget {
  final TextEditingController? controller;
  final String label;
  final String? hint;
  final TextInputType? keyboard;
  final bool obscure;
  final bool enabled;
  final bool ltr;
  final int maxLines;
  final int? maxLength;
  final List<TextInputFormatter>? formatters;
  final String? Function(String?)? validator;
  final ValueChanged<String>? onChanged;
  final FocusNode? focusNode;

  /// نصّ يُعرض بدل حقل الإدخال (للحقول للقراءة فقط مثل «العملة»).
  final String? readOnlyValue;

  const AmialFormField({
    super.key,
    this.controller,
    required this.label,
    this.hint,
    this.keyboard,
    this.obscure = false,
    this.enabled = true,
    this.ltr = false,
    this.maxLines = 1,
    this.maxLength,
    this.formatters,
    this.validator,
    this.onChanged,
    this.focusNode,
    this.readOnlyValue,
  });

  @override
  State<AmialFormField> createState() => _AmialFormFieldState();
}

class _AmialFormFieldState extends State<AmialFormField> {
  late final FocusNode _node = widget.focusNode ?? FocusNode();
  bool _focused = false;

  @override
  void initState() {
    super.initState();
    _node.addListener(_onFocus);
  }

  void _onFocus() {
    if (!mounted) return;
    setState(() => _focused = _node.hasFocus);
  }

  @override
  void dispose() {
    _node.removeListener(_onFocus);
    if (widget.focusNode == null) _node.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FormField<String>(
      validator: (_) => widget.validator?.call(widget.controller?.text),
      builder: (state) {
        final bool hasError = state.hasError;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 150),
              padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
              decoration: BoxDecoration(
                color: _focused ? Colors.white : AmialFormTokens.fieldFill,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: hasError
                      ? AmialColors.red
                      : _focused
                          ? AmialColors.primary
                          : Colors.transparent,
                  width: 1.5,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    widget.label,
                    style: const TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.bold,
                      color: AmialColors.primary,
                    ),
                  ),
                  const SizedBox(height: 2),
                  if (widget.readOnlyValue != null)
                    Text(
                      widget.readOnlyValue!,
                      style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF1A2433)),
                    )
                  else
                    TextField(
                      controller: widget.controller,
                      focusNode: _node,
                      enabled: widget.enabled,
                      obscureText: widget.obscure,
                      keyboardType: widget.keyboard,
                      maxLines: widget.maxLines,
                      maxLength: widget.maxLength,
                      inputFormatters: widget.formatters,
                      textDirection: widget.ltr ? TextDirection.ltr : null,
                      onChanged: (v) {
                        widget.onChanged?.call(v);
                        if (state.hasError) state.validate();
                      },
                      style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF1A2433)),
                      decoration: InputDecoration(
                        isDense: true,
                        counterText: '',
                        border: InputBorder.none,
                        enabledBorder: InputBorder.none,
                        focusedBorder: InputBorder.none,
                        disabledBorder: InputBorder.none,
                        contentPadding: EdgeInsets.zero,
                        hintText: widget.hint ?? widget.label,
                        hintStyle: const TextStyle(
                            fontSize: 13.5,
                            fontWeight: FontWeight.w400,
                            color: AmialColors.textMuted),
                      ),
                    ),
                ],
              ),
            ),
            if (hasError)
              Padding(
                padding: const EdgeInsets.only(top: 5, right: 6),
                child: Text(state.errorText!,
                    style: const TextStyle(
                        fontSize: 11.5, color: AmialColors.red)),
              ),
          ],
        );
      },
    );
  }
}

/// توكِنات النماذج — لون واحد للتعبئة يُستعمل في الحقول والصفوف.
class AmialFormTokens {
  AmialFormTokens._();
  static const Color fieldFill = Color(0xFFEEF1F7);
  static const double radius = 14;
}

/// حقل اختيار بنفس شكل [AmialFormField] — قيمة ثابتة وسهم على الطرف.
class AmialSelectField extends StatelessWidget {
  final String label;
  final String value;
  final VoidCallback? onTap;

  const AmialSelectField({
    super.key,
    required this.label,
    required this.value,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AmialFormTokens.radius),
      child: Container(
        padding: const EdgeInsets.fromLTRB(14, 10, 10, 10),
        decoration: BoxDecoration(
          color: AmialFormTokens.fieldFill,
          borderRadius: BorderRadius.circular(AmialFormTokens.radius),
        ),
        child: Row(
          children: [
            if (onTap != null)
              const Icon(Icons.keyboard_arrow_down_rounded,
                  color: AmialColors.textMuted, size: 22),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(label,
                      style: const TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.bold,
                          color: AmialColors.primary)),
                  const SizedBox(height: 2),
                  Text(value,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF1A2433))),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// زرّ إجراء مساعد بجوار حقل (مسح QR، دليل الهاتف، إملاء صوتي).
/// يُوضع **خارج** الحقل في مربّع أبيض مستقلّ.
class AmialFieldAction extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;
  final String? tooltip;

  const AmialFieldAction(
      {super.key, required this.icon, required this.onTap, this.tooltip});

  @override
  Widget build(BuildContext context) {
    final btn = InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AmialFormTokens.radius),
      child: Container(
        width: 54,
        height: 54,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(AmialFormTokens.radius),
          border: Border.all(color: AmialColors.border),
        ),
        child: Icon(icon, size: 22, color: AmialColors.primary),
      ),
    );
    return tooltip == null ? btn : Tooltip(message: tooltip!, child: btn);
  }
}

/// صفّ يجمع حقلين قصيرين مترابطين (مبلغ + عملة).
class AmialFieldRow extends StatelessWidget {
  final Widget start;
  final Widget end;
  final int startFlex;
  final int endFlex;

  const AmialFieldRow({
    super.key,
    required this.start,
    required this.end,
    this.startFlex = 1,
    this.endFlex = 1,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(flex: startFlex, child: start),
        const SizedBox(width: 10),
        Expanded(flex: endFlex, child: end),
      ],
    );
  }
}

/// ترويسة شاشة موحّدة: رجوع + عنوان في الوسط + أزرار مساعدة على الطرف.
///
/// بديل `AppBar` الأزرق الصلب. الأزرار مربّعات بيضاء بزوايا 16 — لا شريط
/// ملوّن يبتلع أعلى الشاشة.
class AmialScreenHeader extends StatelessWidget {
  final String title;
  final List<Widget> actions;
  final VoidCallback? onBack;

  const AmialScreenHeader({
    super.key,
    required this.title,
    this.actions = const [],
    this.onBack,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
      child: Row(
        children: [
          ...actions,
          const Spacer(),
          Text(title,
              style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433))),
          const Spacer(),
          _SquareButton(
            icon: Icons.arrow_forward_ios_rounded,
            onTap: onBack ?? () => Get.back(),
          ),
        ],
      ),
    );
  }
}

class _SquareButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;
  const _SquareButton({required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        width: 46,
        height: 46,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: 0.05),
                blurRadius: 8,
                offset: const Offset(0, 2)),
          ],
        ),
        child: Icon(icon, size: 18, color: const Color(0xFF1A2433)),
      ),
    );
  }
}

/// زرّ مربّع للترويسة (دعم، مفضّلة…) — يُمرَّر في `actions`.
class AmialHeaderAction extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;
  const AmialHeaderAction({super.key, required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(left: 8),
        child: _SquareButton(icon: icon, onTap: onTap),
      );
}

/// صفّ إجراء داخل قائمة/ورقة سفلية: أيقونة ملوّنة + عنوان + وصف + سهم.
///
/// هنا **يُسمح باللون** لأن العناصر مصفوفة رأسياً لا متجاورة في شبكة —
/// اللون يُميّز ولا ينافس.
class AmialActionRow extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? subtitle;
  final Color color;
  final VoidCallback onTap;

  const AmialActionRow({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.color = AmialColors.primary,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          decoration: BoxDecoration(
            color: AmialFormTokens.fieldFill,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Row(
            children: [
              const Icon(Icons.chevron_left_rounded,
                  color: AmialColors.textMuted, size: 22),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(title,
                        style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF1A2433))),
                    if (subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(subtitle!,
                          textAlign: TextAlign.end,
                          style: const TextStyle(
                              fontSize: 11.5,
                              color: AmialColors.textSecondary)),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, color: color, size: 23),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// ورقة سفلية تعرض خيارات فئة (حوالات، مدفوعات…).
class AmialActionSheet {
  AmialActionSheet._();

  static Future<void> open(
    BuildContext context, {
    required String title,
    required List<AmialActionRow> rows,
  }) {
    return showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.white,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: AmialColors.border,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              const SizedBox(height: 14),
              Text(title,
                  style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF1A2433))),
              const SizedBox(height: 14),
              Flexible(
                child: SingleChildScrollView(
                  child: Column(mainAxisSize: MainAxisSize.min, children: rows),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
