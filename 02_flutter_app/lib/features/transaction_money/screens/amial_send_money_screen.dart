import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amial_pay/features/transaction_money/domain/amial_transfer_api.dart';
import 'package:amial_pay/features/transaction_money/screens/amial_transfer_holding_screen.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/helper/phone_cheker_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/common/widgets/amial_quick_amounts.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/features/setting/screens/support_screen.dart';
import 'package:amial_pay/features/favorite_number/screens/search_contact_screen.dart';
import 'package:amial_pay/features/transaction_money/domain/models/contact_tag_model.dart';
import 'package:amial_pay/common/models/contact_model.dart';
import 'package:amial_pay/features/transaction_money/domain/enums/suggest_type_enum.dart';

/// AMIAL-SEND-V2 — شاشة تحويل الأموال بتصميم أميال (التصميم 15 من ملف
/// الشاشات): بطاقة الرصيد + رقم المستلِم + المبلغ + ملاحظة + «المحوَّل لهم
/// مؤخراً» في صفحة واحدة، ثم تصبّ في شاشة التأكيد/PIN المجرَّبة نفسها.
class AmialSendMoneyScreen extends StatefulWidget {
  /// AMIAL-QUICK-SEND: رقم يُعبَّأ مسبقاً (من «تحويل سريع» في الرئيسية).
  final String? initialPhone;

  const AmialSendMoneyScreen({super.key, this.initialPhone});

  @override
  State<AmialSendMoneyScreen> createState() => _AmialSendMoneyScreenState();
}

class _AmialSendMoneyScreenState extends State<AmialSendMoneyScreen> {
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  bool _checking = false;

  @override
  void initState() {
    super.initState();
    // AMIAL-QUICK-SEND: تعبئة الرقم القادم من «تحويل سريع» (بلا 967)
    final ip = widget.initialPhone;
    if (ip != null && ip.isNotEmpty) {
      var ph = ip.replaceAll('+', '');
      if (ph.startsWith('967')) ph = ph.substring(3);
      _phoneCtrl.text = ph;
    }
    // الرصيد + «المحوَّل لهم مؤخراً»
    try {
      final p = Get.find<ProfileController>();
      if (p.userInfo == null) p.getProfileData(reload: false);
    } catch (_) {}
    try {
      Get.find<ContactController>().getSuggestList(type: AppConstants.sendMoney);
    } catch (_) {}
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  /// AMIAL-TRANSFER-V2 — المسار الجديد:
  /// 1) التحقق من المستلم (اسم مقنَّع) 2) تأكيد 3) PIN 4) initiate بنافذة تراجع.
  Future<void> _confirm() async {
    final rawPhone = _phoneCtrl.text.trim();
    if (rawPhone.length < 6) {
      showCustomSnackBarHelper('أدخل رقم المستلِم', isError: true);
      return;
    }
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (amount <= 0) {
      showCustomSnackBarHelper('أدخل مبلغاً صحيحاً', isError: true);
      return;
    }

    setState(() => _checking = true);
    try {
      // AMIAL-ACCOUNT-NUMBER-001: يقبل الحقلُ رقمَ هاتف أو رقمَ حساب (8 أرقام).
      // رقم الحساب يُرسَل كما هو؛ كان يُمرَّر دائماً عبر مُنسّق الهاتف (+967)
      // فيتشوّه رقم الحساب ويفشل البحث. نُرسله خاماً إن كان 8 أرقام.
      final compact = rawPhone.replaceAll(RegExp(r'\s'), '');
      final isAccountNumber = RegExp(r'^\d{8}$').hasMatch(compact);
      final phoneNumber = isAccountNumber
          ? compact
          : PhoneNumberHelper.getValidatePhoneNumberWithPhoneParser(
              countryCode: '+967',
              phoneNumber: rawPhone,
            );

      // ── 1) التحقق من المستلم ──
      final vr = await AmialTransferApi.verifyRecipient(phoneNumber);
      if (!mounted) return;
      setState(() => _checking = false);

      if (vr.statusCode != 200 || vr.body is! Map || vr.body['success'] != true) {
        String msg = 'الرقم غير مسجل في النظام';
        try {
          if (vr.body is Map && vr.body['message'] != null) {
            msg = '${vr.body['message']}';
          }
        } catch (_) {}
        showCustomSnackBarHelper(msg, isError: true);
        return;
      }

      final meta = Map<String, dynamic>.from(vr.body['meta'] as Map);
      final token = '${meta['verification_token']}';
      final recipientId = meta['recipient_id'] as int;
      final maskedName = '${meta['masked_name'] ?? 'مستلِم'}';
      final maskedPhone = '${meta['masked_phone'] ?? phoneNumber}';

      // الرسوم (ثابتة من إعدادات الخادم — نفس منطق 6cash)
      double fee = 0;
      try {
        fee = Get.find<SplashController>()
                .configModel
                ?.sendMoneyChargeFlat ??
            0;
      } catch (_) {}

      // ── 2) ورقة «تأكد من المستلم» ──
      final proceed = await _showRecipientConfirmSheet(
        maskedName: maskedName,
        maskedPhone: maskedPhone,
        amount: amount,
        fee: fee,
      );
      if (proceed != true || !mounted) return;

      // ── 3) رمز PIN ──
      final pin = await askAmialPinInput(title: 'تأكيد التحويل');
      if (pin == null || pin.length < 4 || !mounted) return;

      // ── 4) initiate ──
      setState(() => _checking = true);
      final r = await AmialTransferApi.initiate(
        recipientId: recipientId,
        verificationToken: token,
        amount: amount.toStringAsFixed(0),
        pin: pin,
        fee: fee.toStringAsFixed(0),
        note: _noteCtrl.text.trim(),
      );
      if (!mounted) return;
      setState(() => _checking = false);

      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final m = Map<String, dynamic>.from(r.body['meta'] as Map);
        // حدّث الرصيد في الخلفية
        try {
          Get.find<ProfileController>().getProfileData(reload: true);
        } catch (_) {}

        // AMIAL-RECENT-FIX-001: تسجيل المستلِم في «تحويل سريع».
        //
        // كان القسم فارغاً دائماً: addToSuggestContact لا يُستدعى إلا من
        // مسار التحويل القديم (bottom_sheet_with_slider) — أما هذه الشاشة،
        // وهي المسار الفعلي من الرئيسية ومن الخدمات، فلم تكن تسجّل أحداً.
        try {
          Get.find<ContactController>().addToSuggestContact(
            ContactModel(
              phoneNumber: phoneNumber,
              name: maskedName.isNotEmpty ? maskedName : phoneNumber,
              isFavourite: false,
            ),
            type: SuggestType.sendMoney,
          );
        } catch (_) {/* غير حرج — لا يمنع نجاح التحويل */}
        Get.off(() => AmialTransferHoldingScreen(
              transferUlid: '${m['transfer_ulid']}',
              amount: '${m['amount'] ?? amount}',
              fee: fee.toStringAsFixed(0),
              secondsRemaining:
                  int.tryParse('${m['seconds_remaining'] ?? 60}') ?? 60,
              recipientName: maskedName,
              recipientPhone: maskedPhone,
              note: _noteCtrl.text.trim().isNotEmpty
                  ? _noteCtrl.text.trim()
                  : null,
            ));
      } else {
        String msg = 'فشل التحويل';
        try {
          if (r.body is Map && r.body['message'] != null) {
            msg = '${r.body['message']}';
          }
        } catch (_) {}
        showCustomSnackBarHelper(msg, isError: true);
      }
    } catch (_) {
      if (mounted) setState(() => _checking = false);
      showCustomSnackBarHelper('تعذّر الاتصال — حاول مجدداً', isError: true);
    }
  }

  /// ورقة «تأكد من المستلم» — الاسم المقنَّع من الخادم يمنع التحويل بالغلط.
  Future<bool?> _showRecipientConfirmSheet({
    required String maskedName,
    required String maskedPhone,
    required double amount,
    required double fee,
  }) {
    return showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.white,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 44,
            height: 4,
            decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2)),
          ),
          const SizedBox(height: 20),
          CircleAvatar(
            radius: 32,
            backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
            child: Text(maskedName.isNotEmpty ? maskedName[0] : '؟',
                style: const TextStyle(
                    fontSize: 24,
                    fontWeight: FontWeight.bold,
                    color: AmialColors.primary)),
          ),
          const SizedBox(height: 12),
          const Text('تأكد من المستلم قبل الإرسال',
              style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
          const SizedBox(height: 4),
          Text(maskedName,
              style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: AmialColors.primary)),
          Text(maskedPhone,
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                  fontSize: 13, color: AmialColors.textSecondary)),
          const SizedBox(height: 18),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AmialColors.background,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(children: [
              _sheetRow('مبلغ التحويل', AmialMoney.yer(amount)),
              const SizedBox(height: 6),
              _sheetRow('رسوم التحويل', AmialMoney.yer(fee)),
              const Divider(height: 18),
              _sheetRow('الإجمالي', AmialMoney.yer(amount + fee), bold: true),
            ]),
          ),
          const SizedBox(height: 12),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: const [
            Icon(Icons.replay_rounded, size: 14, color: AmialColors.textMuted),
            SizedBox(width: 6),
            Flexible(
              child: Text('يمكنك التراجع عن التحويل خلال دقيقة بعد التنفيذ',
                  style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
            ),
          ]),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary,
              minimumSize: const Size.fromHeight(52),
            ),
            child: const Text('تأكيد التحويل',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء',
                style: TextStyle(color: AmialColors.textSecondary)),
          ),
          SizedBox(height: MediaQuery.of(ctx).viewInsets.bottom),
        ]),
      ),
    );
  }

  Widget _sheetRow(String label, String value, {bool bold = false}) {
    return Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
      Text(value,
          style: TextStyle(
              fontSize: bold ? 16 : 14,
              fontWeight: bold ? FontWeight.bold : FontWeight.w600,
              color: bold ? AmialColors.primary : Colors.black87)),
      Text(label,
          style: const TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    // AMIAL-DS-002: ترويسة خفيفة بدل AppBar أزرق صلب — كما في المرجع.
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(children: [
          AmialScreenHeader(
            title: 'تحويل الأموال',
            actions: [
              AmialHeaderAction(
                  icon: Icons.headset_mic_outlined,
                  onTap: () => Get.to(() => const SupportScreen())),
            ],
          ),
          Expanded(
              child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ── بطاقة الرصيد المتاح ──────────────────────────
            GetBuilder<ProfileController>(builder: (p) {
              final bal = p.userInfo?.balance;
              return Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: [
                    BoxShadow(
                        color: Colors.black.withValues(alpha: 0.04),
                        blurRadius: 8,
                        offset: const Offset(0, 3)),
                  ],
                ),
                child: Row(
                  children: [
                    Container(
                      height: 48,
                      width: 48,
                      decoration: BoxDecoration(
                        color: AmialColors.primary.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(Icons.account_balance_wallet_outlined,
                          color: AmialColors.primary),
                    ),
                    const Spacer(),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        const Text('الرصيد المتاح',
                            style: TextStyle(
                                fontSize: 12, color: Color(0xFF8B97A8))),
                        Text(
                          bal == null ? '...' : AmialMoney.yer(bal),
                          style: const TextStyle(
                              fontSize: 20,
                              fontWeight: FontWeight.bold,
                              color: AmialColors.primary),
                        ),
                      ],
                    ),
                  ],
                ),
              );
            }),
            const SizedBox(height: 20),

            // ── إرسال إلى ────────────────────────────────────
            // AMIAL-DS-002: التسمية ثابتة فوق القيمة، وأزرار المساعدة
            // (دليل الهاتف) خارج الحقل في مربّع مستقلّ — لا أيقونة مزدحمة
            // داخله كما كان.
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                AmialFieldAction(
                  icon: Icons.contacts_outlined,
                  tooltip: 'اختر من جهات الاتصال',
                  onTap: _pickFromContacts,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: AmialFormField(
                    controller: _phoneCtrl,
                    label: 'إرسال إلى',
                    hint: 'رقم الهاتف أو رقم الحساب (8 أرقام)',
                    keyboard: TextInputType.phone,
                    ltr: true,
                    formatters: [FilteringTextInputFormatter.digitsOnly],
                  ),
                ),
              ],
            ),

            // ── المحوَّل لهم مؤخراً ───────────────────────────
            GetBuilder<ContactController>(builder: (c) {
              final recent = c.sendMoneySuggestList;
              if (recent.isEmpty) return const SizedBox(height: 8);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 16),
                  _label('المحوَّل لهم مؤخراً'),
                  SizedBox(
                    height: 88,
                    child: ListView.separated(
                      scrollDirection: Axis.horizontal,
                      itemCount: recent.length > 8 ? 8 : recent.length,
                      separatorBuilder: (_, __) => const SizedBox(width: 14),
                      itemBuilder: (_, i) {
                        final m = recent[i];
                        final name = (m.name ?? '').trim();
                        final initial = name.isNotEmpty ? name[0] : '؟';
                        return InkWell(
                          onTap: () => setState(() {
                            // نملأ الرقم الوطني (بلا 967+) ليمرّ بنفس مسار التحقّق
                            var ph = (m.phoneNumber ?? '').replaceAll('+', '');
                            if (ph.startsWith('967')) ph = ph.substring(3);
                            _phoneCtrl.text = ph;
                          }),
                          child: Column(
                            children: [
                              CircleAvatar(
                                radius: 26,
                                backgroundColor:
                                    AmialColors.primary.withValues(alpha: 0.12),
                                child: Text(initial,
                                    style: const TextStyle(
                                        color: AmialColors.primary,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 18)),
                              ),
                              const SizedBox(height: 6),
                              SizedBox(
                                width: 60,
                                child: Text(
                                  name.isEmpty ? 'مستلِم' : name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(fontSize: 11),
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                  ),
                ],
              );
            }),
            const SizedBox(height: 16),

            // ── المبلغ ───────────────────────────────────────
            _label('المبلغ'),
            Container(
              padding: const EdgeInsets.symmetric(vertical: 18),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      // AMIAL-FIX(CURRENCY): كان «YER» بالإنجليزية وبلون ذهبي
                      // غريب — بينما بقية التطبيق «ر.ي» بلون البراند.
                      const Text('ر.ي',
                          style: TextStyle(
                              color: AmialColors.textSecondary,
                              fontWeight: FontWeight.w700,
                              fontSize: 16)),
                      const SizedBox(width: 12),
                      IntrinsicWidth(
                        child: TextField(
                          controller: _amountCtrl,
                          keyboardType: TextInputType.number,
                          inputFormatters: [
                            FilteringTextInputFormatter.digitsOnly
                          ],
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                              fontSize: 36,
                              fontWeight: FontWeight.bold,
                              color: AmialColors.primary),
                          decoration: const InputDecoration(
                            hintText: '0',
                            border: InputBorder.none,
                            isDense: true,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  const Text('الرسوم تُعرض في شاشة التأكيد',
                      style:
                          TextStyle(fontSize: 11, color: Color(0xFF8B97A8))),
                ],
              ),
            ),
            const SizedBox(height: 10),

            // AMIAL-DS-001: مبالغ سريعة — كانت غائبة عن أكثر شاشة استخداماً.
            AmialQuickAmounts(
              values: const [1000, 2000, 5000, 10000, 20000, 50000],
              onPick: (v) {
                _amountCtrl.text = v.toString();
                setState(() {});
              },
            ),
            const SizedBox(height: 16),

            // ── ملاحظة ───────────────────────────────────────
            AmialFormField(
              controller: _noteCtrl,
              label: 'ملاحظة (اختياري)',
              hint: 'ما هو سبب هذا التحويل؟',
              maxLines: 2,
              maxLength: 100,
            ),
            const SizedBox(height: 24),

            // ── تأكيد وإرسال ─────────────────────────────────
            ElevatedButton.icon(
              onPressed: _checking ? null : _confirm,
              icon: _checking
                  ? const SizedBox(
                      height: 18,
                      width: 18,
                      child: CircularProgressIndicator(
                          color: Colors.white, strokeWidth: 2))
                  : const Icon(Icons.send_rounded),
              label: const Text('تأكيد وإرسال',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              style: ElevatedButton.styleFrom(
                backgroundColor: AmialColors.primary,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 15),
                minimumSize: const Size.fromHeight(54),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14)),
              ),
            ),
          ],
        ),
      )),
        ]),
      ),
    );
  }

  /// AMIAL-DS-002: يفتح دليل الهاتف ويُعبّئ الرقم بالفعل.
  /// `SearchContactScreen` يُرجع `ContactTagModel` عبر `Get.back(result:)` —
  /// فتحه بلا التقاط النتيجة يجعل الزرّ بلا أثر.
  Future<void> _pickFromContacts() async {
    final ContactTagModel? picked =
        await Get.to(() => const SearchContactScreen());
    if (picked == null || !mounted) return;
    final phones = picked.contact?.phones ?? const [];
    if (phones.isEmpty) return;
    var number = phones.first.number.replaceAll(RegExp(r'[\s\-()]'), '');
    final cc = PhoneNumberHelper.getCountryCode(number);
    if (cc != null && cc.isNotEmpty) number = number.replaceFirst(cc, '');
    setState(() => _phoneCtrl.text = number);
  }

  Widget _label(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(t,
            style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 14,
                color: Color(0xFF1A2433))),
      );
}
