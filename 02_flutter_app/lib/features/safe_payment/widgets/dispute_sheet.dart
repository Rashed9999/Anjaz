import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amial_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SAFEPAY-DISPUTE-001 — فتح النزاع بسبب منظّم.
///
/// كان النزاع نصّاً حرّاً وحده. النصّ الحرّ يُقرأ بالعين البشرية مرّة واحدة
/// ثم يضيع: لا يُصنَّف، ولا يُحصى، ولا يُوجَّه إلى الموظّف المناسب، ولا يكشف
/// أن بائعاً بعينه تتكرّر عليه شكوى «مقلّدة».
///
/// السبب المنظّم يفعل الأربعة. والنصّ يبقى بجانبه — لأن التصنيف لا يغني عن
/// رواية صاحب الشكوى.
class DisputeSheet extends StatefulWidget {
  const DisputeSheet({super.key, required this.ulid});

  final String ulid;

  /// يعيد `true` إن فُتح النزاع فعلاً.
  static Future<bool> open(BuildContext context, {required String ulid}) async {
    // نجلب الأسباب قبل الفتح كي لا تُفتح الورقة على فراغ.
    await Get.find<SafePaymentController>().loadDisputeReasons();

    if (!context.mounted) return false;

    final result = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => DisputeSheet(ulid: ulid),
    );

    return result ?? false;
  }

  @override
  State<DisputeSheet> createState() => _DisputeSheetState();
}

class _DisputeSheetState extends State<DisputeSheet> {
  final _reason = TextEditingController();
  String? _code;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final text = _reason.text.trim();
    if (_code == null) {
      setState(() => _error = 'اختر سبب النزاع');
      return;
    }
    if (text.length < 10) {
      setState(() => _error = 'اشرح المشكلة في 10 أحرف فأكثر — الشرح هو ما تُبنى عليه المراجعة');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final ctrl = Get.find<SafePaymentController>();
    final ok = await ctrl.buyerDispute(widget.ulid, text, reasonCode: _code);

    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pop(true);
      return;
    }

    setState(() {
      _busy = false;
      _error = ctrl.lastError.value.isEmpty ? 'تعذّر فتح النزاع' : ctrl.lastError.value;
    });
  }

  @override
  Widget build(BuildContext context) {
    final reasons = Get.find<SafePaymentController>().disputeReasons;
    final bottom = MediaQuery.viewInsetsOf(context).bottom;

    return Padding(
      padding: EdgeInsets.only(bottom: bottom),
      child: Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 22),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AmialColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 16),

              const Text('فتح نزاع',
                  style: TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.bold,
                      color: AmialColors.textPrimary)),
              const SizedBox(height: 6),
              const Text(
                'المبلغ محجوز ولن يصل البائع حتى تُحسم المراجعة. اختر السبب '
                'الأقرب واشرح ما حدث.',
                style: TextStyle(
                    fontSize: 12.5, height: 1.7, color: AmialColors.textSecondary),
              ),
              const SizedBox(height: 16),

              const Align(
                alignment: Alignment.centerRight,
                child: Text('سبب النزاع',
                    style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(height: 8),

              if (reasons.isEmpty)
                const Text(
                  'تعذّر جلب قائمة الأسباب — اشرح المشكلة نصّاً وسنراجعها.',
                  style: TextStyle(fontSize: 11.5, color: AmialColors.textMuted),
                )
              else
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final AmialDisputeReason r in reasons)
                      ChoiceChip(
                        label: Text(r.label, style: const TextStyle(fontSize: 12)),
                        selected: _code == r.code,
                        onSelected: _busy
                            ? null
                            : (_) => setState(() {
                                  _code = r.code;
                                  _error = null;
                                }),
                        selectedColor: AmialColors.primary.withValues(alpha: 0.12),
                        labelStyle: TextStyle(
                          color: _code == r.code
                              ? AmialColors.primary
                              : AmialColors.textSecondary,
                          fontWeight:
                              _code == r.code ? FontWeight.bold : FontWeight.normal,
                        ),
                      ),
                  ],
                ),

              const SizedBox(height: 16),
              TextField(
                controller: _reason,
                enabled: !_busy,
                maxLines: 4,
                maxLength: 1000,
                decoration: const InputDecoration(
                  labelText: 'ماذا حدث بالضبط؟',
                  hintText: 'مثال: وصلني الصندوق مفتوحاً وناقصاً قطعة الشاحن، '
                      'والبائع لا يردّ منذ يومين.',
                  border: OutlineInputBorder(),
                ),
                onChanged: (_) {
                  if (_error != null) setState(() => _error = null);
                },
              ),

              Container(
                padding: const EdgeInsets.all(11),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF8E1),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Row(children: [
                  Icon(Icons.photo_camera_outlined, size: 18, color: Color(0xFFCFA300)),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'بعد فتح النزاع ستُطلب منك صور الحالة. النزاع بلا صور '
                      'يُحسم غالباً لصالح من قدّمها.',
                      style: TextStyle(fontSize: 11.5, height: 1.6),
                    ),
                  ),
                ]),
              ),

              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(_error!,
                    style: const TextStyle(color: AmialColors.red, fontSize: 12.5)),
              ],

              const SizedBox(height: 14),
              SizedBox(
                height: 50,
                child: ElevatedButton.icon(
                  onPressed: _busy ? null : _submit,
                  icon: _busy
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.gavel_rounded),
                  label: Text(_busy ? 'جارٍ الفتح…' : 'فتح النزاع'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmialColors.red,
                    foregroundColor: Colors.white,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
