import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/shared/widgets/amial_numpad.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_dots.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/secure_screen.dart';

/// AMIAL-PIN-UI-002 — تغيير رمز المعاملات.
///
/// كانت ثلاثة حقول نصّ فوق بعضها بتباعد حروف 10، تفتح لوحة مفاتيح النظام
/// وتُخفي نصف الشاشة، ويرى المستخدم الثلاثة معاً فلا يدري أيّها المطلوب
/// الآن. صارت ثلاث خطوات، في كل خطوة سؤال واحد ونقاط ولوحة أرقام.
///
/// **تسمية مقصودة:** «رمز المعاملات» لا «الرمز السري». الاسم الأخير كان
/// يُطلق على كلمة مرور الدخول أيضاً، فيظنّ العميل أنه يغيّر ما يدخل به.
class ChangePinScreen extends StatefulWidget {
  const ChangePinScreen({super.key});

  @override
  State<ChangePinScreen> createState() => _ChangePinScreenState();
}

enum _Step { old, fresh, confirm }

class _ChangePinScreenState extends State<ChangePinScreen> {
  final _old = TextEditingController();
  final _fresh = TextEditingController();
  final _confirm = TextEditingController();

  _Step _step = _Step.old;
  String _error = '';

  @override
  void initState() {
    super.initState();
    // AMIAL-SEC-CAPTURE-001: رمز سرّي على الشاشة — لا تصوير ولا تسجيل.
    SecureScreen.enable();
  }

  @override
  void dispose() {
    SecureScreen.disable();
    _old.dispose();
    _fresh.dispose();
    _confirm.dispose();
    super.dispose();
  }

  TextEditingController get _active => switch (_step) {
        _Step.old => _old,
        _Step.fresh => _fresh,
        _Step.confirm => _confirm,
      };

  String get _title => switch (_step) {
        _Step.old => 'رمزك الحالي',
        _Step.fresh => 'الرمز الجديد',
        _Step.confirm => 'أعد إدخال الرمز الجديد',
      };

  String get _hint => switch (_step) {
        _Step.old => 'أدخل رمز المعاملات المستعمل حالياً للتأكّد من هويتك',
        _Step.fresh => 'أربعة أرقام غير متسلسلة ولا متكرّرة — لا 1234 ولا 0000',
        _Step.confirm => 'مرّة أخرى، حتى لا يُحفظ رمز أخطأت في كتابته',
      };

  void _onFilled(String value) {
    if (value.length < 4) return;

    switch (_step) {
      case _Step.old:
        setState(() {
          _step = _Step.fresh;
          _error = '';
        });

      case _Step.fresh:
        // الرمز نفسه ليس تغييراً. قولها الآن أرحم من ردّ الخادم بعد خطوتين.
        if (_fresh.text == _old.text) {
          setState(() {
            _error = 'الرمز الجديد مطابق للحالي — اختر غيره';
            _fresh.clear();
          });
          return;
        }
        setState(() {
          _step = _Step.confirm;
          _error = '';
        });

      case _Step.confirm:
        if (_confirm.text != _fresh.text) {
          setState(() {
            _error = 'الرمزان غير متطابقين — أعد المحاولة';
            _confirm.clear();
            _fresh.clear();
            _step = _Step.fresh;
          });
          return;
        }
        _submit();
    }
  }

  Future<void> _submit() async {
    final ctrl = Get.find<ProfileController>();
    await ctrl.changePin(
      oldPassword: _old.text,
      newPassword: _fresh.text,
      confirmPassword: _confirm.text,
    );

    if (!mounted) return;
    // الفشل يعيدنا إلى البداية: رسالة الخادم ظهرت بالفعل عبر المتحكّم،
    // والحقول تُمسح كي لا يُعاد إرسال رمز مرفوض.
    setState(() {
      _old.clear();
      _fresh.clear();
      _confirm.clear();
      _step = _Step.old;
    });
  }

  void _back() {
    if (_step == _Step.old) {
      Get.back();
      return;
    }
    setState(() {
      _active.clear();
      _step = _step == _Step.confirm ? _Step.fresh : _Step.old;
      _error = '';
    });
  }

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ProfileController>(builder: (controller) {
      return Scaffold(
        backgroundColor: AmialColors.background,
        body: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Align(
                  alignment: Alignment.centerLeft,
                  child: IconButton(
                    icon: const Icon(Icons.arrow_back_rounded,
                        color: AmialColors.textSecondary),
                    onPressed: controller.isLoading ? null : _back,
                  ),
                ),

                // مؤشّر الخطوات: يعرف المستخدم أين هو وكم بقي.
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(3, (i) {
                    final done = i <= _Step.values.indexOf(_step);
                    return Container(
                      width: 34,
                      height: 4,
                      margin: const EdgeInsets.symmetric(horizontal: 3),
                      decoration: BoxDecoration(
                        color: done
                            ? AmialColors.primary
                            : AmialColors.primary.withValues(alpha: 0.18),
                        borderRadius: BorderRadius.circular(2),
                      ),
                    );
                  }),
                ),
                const SizedBox(height: 22),

                Container(
                  height: 76,
                  width: 76,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: AmialColors.primary.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.password_rounded,
                      color: AmialColors.primary, size: 34),
                ),
                const SizedBox(height: 16),

                Text(_title,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 19,
                        fontWeight: FontWeight.bold,
                        color: AmialColors.textPrimary)),
                const SizedBox(height: 6),
                Text(_hint,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 13, height: 1.6,
                        color: AmialColors.textSecondary)),
                const SizedBox(height: 26),

                AmialPinDots(controller: _active, error: _error.isNotEmpty),
                const SizedBox(height: 10),

                SizedBox(
                  height: 34,
                  child: controller.isLoading
                      ? const Center(
                          child: SizedBox(
                            width: 20, height: 20,
                            child: CircularProgressIndicator(
                                strokeWidth: 2, color: AmialColors.primary),
                          ),
                        )
                      : _error.isEmpty
                          ? const SizedBox.shrink()
                          : Center(
                              child: Text(_error,
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(
                                      color: AmialColors.red, fontSize: 13)),
                            ),
                ),
                const SizedBox(height: 8),

                AbsorbPointer(
                  absorbing: controller.isLoading,
                  child: AmialNumpad(
                    controller: _active,
                    maxLength: 4,
                    compact: true,
                    onChanged: (v) {
                      setState(() {});
                      if (v.length == 4) _onFilled(v);
                    },
                  ),
                ),
                const SizedBox(height: 18),

                Container(
                  padding: const EdgeInsets.all(11),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AmialColors.border),
                  ),
                  child: const Row(children: [
                    Icon(Icons.info_outline_rounded,
                        size: 17, color: AmialColors.textMuted),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'رمز المعاملات يؤكّد التحويلات والسحب — وهو غير كلمة '
                        'مرور الدخول. تغييره هنا لا يغيّر كلمة مرورك.',
                        style: TextStyle(
                            fontSize: 11.5, height: 1.6,
                            color: AmialColors.textSecondary),
                      ),
                    ),
                  ]),
                ),
              ],
            ),
          ),
        ),
      );
    });
  }
}
