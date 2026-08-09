import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/common/widgets/amial_quick_amounts.dart';
import 'package:amial_pay/features/requested_money/controllers/requested_money_controller.dart';
import 'package:amial_pay/features/requested_money/screens/requested_money_list_screen.dart';

/// AMIAL-REQ-DIRECT-001 — **اطلب مالاً من شخص**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمن الذي دُفع:** زرُّ «طلب مال» كان يقود إلى إنشاء **رابطٍ ورمزِ
/// QR ورمزٍ قصير**. وهذا مسارُ تاجرٍ يعرض رمزاً على طاولته، لا مسارُ شخصٍ
/// يطلب من صديقه عشرين ألفاً.
///
/// فمن يريد أن يطلب من أخيه كان عليه: يُنشئ طلباً، ثمّ ينسخ رابطاً، ثمّ
/// يرسله في واتساب، ثمّ ينتظر أن يفتحه أخوه ويعود إلى التطبيق. **أربعُ
/// خطواتٍ خارج التطبيق لأمرٍ يقع داخله.**
///
/// وكان في النظام مسارٌ أبسط **حيٌّ ومبنيٌّ ولا ينادي عليه أحد**:
/// `POST /api/v1/customer/request-money` — هاتفٌ ومبلغ، فيصل الطلبُ إلى
/// المطلوب منه مع إشعار، فيوافق أو يرفض برمزه.
///
/// (وهذا نمطُ العطل الأكثر تكراراً هنا: **مبنيٌّ ولا يُوصَل إليه**.)
class RequestFromPersonScreen extends StatefulWidget {
  const RequestFromPersonScreen({super.key});

  @override
  State<RequestFromPersonScreen> createState() => _RequestFromPersonScreenState();
}

class _RequestFromPersonScreenState extends State<RequestFromPersonScreen> {
  final _phone = TextEditingController();
  final _amount = TextEditingController();
  final _note = TextEditingController();

  @override
  void dispose() {
    _phone.dispose();
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  String? _validate() {
    final digits = _phone.text.replaceAll(RegExp(r'\D'), '');

    if (digits.length < 9) return 'أدخل رقم هاتف صحيحاً (٩ أرقام على الأقل)';

    final amount = double.tryParse(_amount.text.replaceAll(',', '').trim()) ?? 0;
    if (amount <= 0) return 'أدخل مبلغاً أكبر من صفر';

    return null;
  }

  Future<void> _submit() async {
    final err = _validate();

    if (err != null) {
      _snack(err, error: true);
      return;
    }

    final c = Get.find<RequestedMoneyController>();

    final ok = await c.requestFromPerson(
      phone: _phone.text.trim(),
      amount: _amount.text.replaceAll(',', '').trim(),
      note: _note.text,
    );

    if (!mounted) return;

    if (!ok) {
      _snack(c.requestError, error: true);
      return;
    }

    // **يُقال ماذا يحدث بعدها** — لا «تم» وحدَها.
    await Get.dialog<void>(AlertDialog(
      icon: Icon(Icons.mark_email_read_rounded,
          size: 44, color: Colors.green.shade700),
      title: const Text('وصل الطلب'),
      content: const Text(
        'أرسلنا الطلب وسيصله إشعار. حين يوافق يُخصم المبلغ من رصيده '
        'ويُضاف إلى رصيدك، وتجد الطلب في «طلباتي» حتى ذلك الحين.',
        textAlign: TextAlign.center,
      ),
      actions: [
        TextButton(
          key: const Key('req-done-close'),
          onPressed: () => Get.back(),
          child: const Text('حسناً'),
        ),
        ElevatedButton(
          key: const Key('req-done-list'),
          onPressed: () {
            Get.back();
            Get.off(() => const RequestedMoneyListScreen(
                requestType: RequestType.sendRequest));
          },
          child: const Text('طلباتي'),
        ),
      ],
    ));

    if (mounted) Get.back();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('طلب مال')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(children: [
                TextField(
                  key: const Key('req-phone'),
                  controller: _phone,
                  autofocus: true,
                  keyboardType: TextInputType.phone,
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]')),
                  ],
                  textDirection: TextDirection.ltr,
                  decoration: const InputDecoration(
                    labelText: 'رقم هاتف من تطلب منه',
                    hintText: '7XXXXXXXX',
                    prefixIcon: Icon(Icons.phone_rounded),
                  ),
                ),
                const SizedBox(height: 16),

                TextField(
                  key: const Key('req-amount'),
                  controller: _amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                  ],
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(
                      fontSize: 22, fontWeight: FontWeight.bold),
                  decoration: const InputDecoration(
                    labelText: 'المبلغ',
                    suffixText: 'ر.ي',
                    prefixIcon: Icon(Icons.payments_rounded),
                  ),
                ),
                const SizedBox(height: 12),

                AmialQuickAmounts(
                  values: const [1000, 5000, 10000, 20000, 50000],
                  onPick: (v) => setState(() => _amount.text = '$v'),
                ),
                const SizedBox(height: 16),

                TextField(
                  key: const Key('req-note'),
                  controller: _note,
                  maxLength: 120,
                  decoration: const InputDecoration(
                    labelText: 'سبب الطلب (اختياري)',
                    hintText: 'مثال: حصتي من الغداء',
                    prefixIcon: Icon(Icons.notes_rounded),
                  ),
                ),
              ]),
            ),
          ),

          const SizedBox(height: 8),

          // **ما سيحدث يُقال قبل الضغط لا بعده.**
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(children: [
              Icon(Icons.info_outline_rounded,
                  size: 16, color: AmialColors.textMuted),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'يصله إشعار بالطلب، ولا يُخصم شيء حتى يوافق برمزه.',
                  style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
                ),
              ),
            ]),
          ),

          const SizedBox(height: 20),

          GetBuilder<RequestedMoneyController>(builder: (c) {
            return AmialButton(
              key: const Key('req-submit'),
              label: c.isRequesting ? 'جارٍ الإرسال…' : 'إرسال الطلب',
              icon: Icons.send_rounded,
              loading: c.isRequesting,
              onPressed: c.isRequesting ? null : _submit,
            );
          }),
        ]),
      ),
    );
  }

  void _snack(String msg, {bool error = false}) {
    if (msg.trim().isEmpty) return;

    Get.snackbar(error ? 'تنبيه' : 'تم', msg,
        backgroundColor: error ? Colors.red.shade50 : Colors.green.shade50,
        colorText: error ? Colors.red.shade900 : Colors.green.shade900,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 4));
  }
}
