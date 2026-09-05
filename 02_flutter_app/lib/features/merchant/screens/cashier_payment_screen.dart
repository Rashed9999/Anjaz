import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/access/widgets/access_gate.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/screens/cashier_receipt_screen.dart';
import 'package:amial_pay/features/payments/screens/amial_qr_collect_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/helper/payment_feedback.dart';
import 'package:amial_pay/features/merchant/widgets/credit_sale_notice.dart';
import 'package:amial_pay/features/merchant/widgets/cash_tendered_field.dart';
import 'package:amial_pay/features/merchant/widgets/customer_balance_badge.dart';
import 'package:amial_pay/features/merchant/widgets/merchant_payment_method_picker.dart';

/// AMIAL-POS-002 — «تأكيد الدفع» (التصميم 37):
/// بطاقة الإجمالي المطلوب سداده + اختيار وسيلة واحدة:
/// نقدي / أميال باي (موصى به) / آجل (قيد على حساب العميل) / مختلط (قريباً)
/// ثم «تأكيد ومعالجة الدفع».
class CashierPaymentScreen extends StatefulWidget {
  const CashierPaymentScreen({
    super.key,
    required this.total,
    this.freeAmount = false,
  });

  final double total;

  /// بيع بمبلغ حرّ (بلا أسطر سلة).
  final bool freeAmount;

  @override
  State<CashierPaymentScreen> createState() => _CashierPaymentScreenState();
}

class _CashierPaymentScreenState extends State<CashierPaymentScreen> {
  CashierController get c => Get.find<CashierController>();
  String _method = 'amial_pay';
  bool _busy = false;

  // AMIAL-PROMOTIONS-001 — خصم مطبَّق (عرض تلقائي أو كوبون)
  double _discount = 0;
  int? _promotionId;
  String? _promoLabel;

  // ═══════════════════════════════════════════════════════════════════
  // AMIAL-LOYALTY-AT-PAYMENT-001 — **النقاطُ تُصرَف حيث يُدفَع المال.**
  //
  // **الملاحظة بنصّها:** «نقاط الولاء ليس لها استخدام أثناء الدفع…
  // يبدو أنّها غير مكتملة الميزة».
  //
  // وقِيس فإذا هي أسوأُ من ناقصة: `redeem` مبنيّةٌ وتعمل، وشاشةُ
  // «برنامج الولاء» تناديها ثمّ تطبع للكاشير **«طبّقه على الفاتورة»** —
  // فالنقاطُ تُحرَق في شاشةٍ والخصمُ يُطبَّق في أخرى بيدٍ بشريّة. وإن
  // نسِي، أو أُلغيت البيعة: **النقاطُ ذهبت والعميلُ دفع كاملاً.**
  //
  // فصار المدخلُ هنا، ويُرسَل `redeem_points` مع البيعة نفسِها.
  // ═══════════════════════════════════════════════════════════════════
  final _loyaltyPhone = TextEditingController();
  bool _loyaltyBusy = false;
  bool _loyaltyLookedUp = false;
  double _loyaltyBalance = 0;
  double _loyaltyValuePerPoint = 0;
  int _loyaltyMinPoints = 0;
  String? _loyaltyCustomerName;
  double _redeemPoints = 0;

  /// AMIAL-CASH-TENDERED-001 — ما استلمه الكاشيرُ نقداً. و`null` تعني
  /// «لم يُدخَل» لا صفراً: كثيرٌ من البيوع يُسلَّم فيها المبلغُ بالضبط.
  double? _received;

  // ═══════════════════════════════════════════════════════════════════
  // AMIAL-MULTI-CURRENCY-003 — **عملةُ البيعة تُختار هنا لا في السلّة.**
  //
  // بُنيت للتاجر أربعُ محافظ، **وقِيس أنّ الدولارَ لا يدخلها من بيعٍ
  // أصلاً**: لا حقلَ عملةٍ في البيعة ولا في هذه الشاشة. فمحافظُ مبنيّةٌ
  // ولا يُوصَل إليها.
  //
  // **وموضعُه شاشةُ الدفع لا السلّة**: العملةُ تُقرّر لحظةَ تسليم المال
  // — يخرج الزبونُ ورقةَ دولارٍ فيختار الكاشيرُ حينها. ووضعُها في السلّة
  // يجعلها قراراً يُتّخذ قبل أن يُعرف.
  //
  // **ولا تُعرَض إن لم يفعّل التاجرُ عملةً غيرَ الأساس** — قائمةٌ بخيارٍ
  // واحدٍ ليست خياراً، وهي ضوضاءُ شاشةٍ على تاجرٍ لا يقبض إلّا بالريال.
  // ═══════════════════════════════════════════════════════════════════
  String _currency = 'YER';
  String _currencySymbol = 'ر.ي';
  List<Map<String, dynamic>> _acceptedCurrencies = [];

  @override
  void initState() {
    super.initState();
    _loadCurrencies();
  }

  Future<void> _loadCurrencies() async {
    try {
      final r = await Get.find<ApiClient>().getData('/api/v1/amial/merchant/wallets');
      // **و٤٠٢ ليست عطلاً** — تاجرٌ بغير باقة المؤسّسة يبيع بالريال، فتبقى
      // القائمةُ فارغةً ولا يظهر شيء. ولا تُعرَض له رسالةُ ترقيةٍ في شاشة
      // دفعٍ والزبونُ واقف.
      if (r.statusCode != 200 || r.body is! Map || r.body['success'] != true) return;

      final all = (((r.body['meta'] ?? {})['wallets'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .where((w) => w['accepts_payments'] == true && w['rate_missing'] != true)
          .toList();

      if (!mounted || all.length < 2) return;   // الأساسُ وحدَه ⇒ لا اختيار

      setState(() {
        _acceptedCurrencies = all;
        final base = all.firstWhere((w) => w['is_base'] == true, orElse: () => all.first);
        _currency = '${base['currency']}';
        _currencySymbol = '${base['symbol']}';
      });
    } catch (_) {
      // شبكةٌ متقطّعة — يبيع بالريال. ولا يُعطَّل البيعُ لأجل قائمةِ عملات.
    }
  }

  /// **السعرُ يُقرأ من الردّ نفسِه** — لا يُحسب في التطبيق ولا يُخزَّن.
  String? get _rateOfSelected {
    if (_currency == 'YER') return null;
    final w = _acceptedCurrencies.firstWhere(
        (x) => x['currency'] == _currency, orElse: () => {});
    return w['rate_to_base']?.toString();
  }

  bool get _isBaseCurrency => _currency == 'YER';

  /// **المبلغُ المرسَل يكون بالعملة المختارة لا بالريال.**
  ///
  /// فالخادمُ يحسب المكافئَ ضرباً في السعر (`base = total × rate`).
  /// وإرسالُ ٢٦٥٠٠ ريالٍ موسومةً «دولاراً» يجعل المكافئَ أربعةَ عشرَ
  /// مليوناً — **بيعةٌ واحدةٌ تبتلع الحدَّ اليوميَّ كلَّه** ولا يُخرج ذلك
  /// خطأً في أيّ سجلّ.
  double get _amountToSend {
    final rate = double.tryParse(_rateOfSelected ?? '') ?? 0;
    if (_isBaseCurrency || rate <= 0) return _net;

    return double.parse((_net / rate).toStringAsFixed(2));
  }

  /// العملةُ التي تُرسَل مع البيعة — و`null` تعني الأساس.
  String? get _currencyToSend => _isBaseCurrency ? null : _currency;

  /// **والخصمُ يُحوَّل معه.**
  ///
  /// فبيعةٌ إجماليُّها بالدولار وخصمُها بالريال سطرٌ لا يُقرأ: يظهر على
  /// الفاتورة «خصم ٥٠٠» بجوار «٢٠٫٠٠ $» فيُفهَم خصماً بالدولار وهو ربعُ
  /// الفاتورة. **رقمان صحيحان بعملتين على ورقةٍ واحدة.**
  double get _discountToSend {
    if (_discount <= 0) return 0;
    final rate = double.tryParse(_rateOfSelected ?? '') ?? 0;
    if (_isBaseCurrency || rate <= 0) return _discount;

    return double.parse((_discount / rate).toStringAsFixed(2));
  }

  /// ــ **ووسائلُ المنصّة لا تقبض عملةً أجنبيّةً بعد** ــــــــــــــــــ
  ///
  /// «أميال باي» و«مختلط» يخصمان من محفظة العميل، **وللعميل محفظةُ ريالٍ
  /// وحدَها**. فالقبضُ الأجنبيُّ اليومَ نقدٌ ورقيٌّ في الدرج (أو آجل).
  ///
  /// **ولا يُترَك ذلك للصمت**: لو بقيت الوسيلةُ معروضةً واختارها الكاشيرُ
  /// لسُجّلت بيعةٌ بالريال بينما هو يعدّ دولاراً — رقمٌ صحيحٌ بعملةٍ
  /// كاذبة، وهو العطلُ الذي كلّف هذا المشروعَ من قبل في تسعيرة الباقات.
  static const _baseOnlyMethods = {'amial_pay', 'mixed', 'corporate'};

  bool _methodAllowedInCurrency(String method) =>
      _isBaseCurrency || !_baseOnlyMethods.contains(method);

  /// قيمةُ النقاط المصروفة بالريال — يحسبها الخادمُ أيضاً، وهذه للعرض.
  double get _loyaltyDiscount =>
      double.parse((_redeemPoints * _loyaltyValuePerPoint).toStringAsFixed(2));

  double get _net => (widget.total - _discount - _loyaltyDiscount)
      .clamp(0, widget.total)
      .toDouble();

  @override
  void dispose() {
    _loyaltyPhone.dispose();
    super.dispose();
  }

  /// يسأل رصيدَ نقاط العميل — **من الشبّاك، وهو موضعُ صرفها.**
  Future<void> _lookupLoyalty() async {
    final phone = _loyaltyPhone.text.trim();
    if (phone.length < 6) { _snack('أدخل رقم العميل'); return; }

    setState(() => _loyaltyBusy = true);
    try {
      final r = await Get.find<ApiClient>()
          .getData('/api/v1/amial/merchant/loyalty/lookup', query: {'phone': phone});

      if (r.statusCode == 402) { _snack('برنامج الولاء متاح في باقة الأعمال فأعلى'); return; }

      if (r.statusCode == 200 && r.body is Map) {
        final m = (r.body['meta'] ?? {}) as Map;
        setState(() {
          _loyaltyLookedUp = true;
          _loyaltyBalance = double.tryParse('${m['points_balance'] ?? 0}') ?? 0;
          _loyaltyValuePerPoint = double.tryParse('${m['redeem_value_per_point'] ?? 0}') ?? 0;
          _loyaltyMinPoints = int.tryParse('${m['min_redeem_points'] ?? 0}') ?? 0;
          _loyaltyCustomerName = m['customer_name']?.toString();
          _redeemPoints = 0;
        });
      } else {
        _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الاستعلام');
      }
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _loyaltyBusy = false);
    }
  }

  /// **الحدُّ الأعلى للنقاط المصروفة على هذه الفاتورة.**
  ///
  /// ولا يُترَك للرصيد وحدَه: صرفُ نقاطٍ قيمتُها أكبرُ من الفاتورة يُنتج
  /// صافياً سالباً — **متجرٌ يدفع للزبون**. فالسقفُ أدنى الاثنين.
  double get _maxRedeemablePoints {
    if (_loyaltyValuePerPoint <= 0) return 0;

    final byInvoice = (widget.total - _discount) / _loyaltyValuePerPoint;

    return (_loyaltyBalance < byInvoice ? _loyaltyBalance : byInvoice)
        .floorToDouble();
  }

  void _applyLoyalty() async {
    final ctrl = TextEditingController(
        text: _maxRedeemablePoints.toStringAsFixed(0));

    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => AlertDialog(
        title: const Text('استبدال نقاط بخصم'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('الرصيد: ${_loyaltyBalance.toStringAsFixed(0)} نقطة  ·  '
              'أقصى ما يُصرف على هذه الفاتورة: '
              '${_maxRedeemablePoints.toStringAsFixed(0)}',
              style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
          const SizedBox(height: 12),
          TextField(
            controller: ctrl,
            keyboardType: TextInputType.number,
            textDirection: TextDirection.ltr,
            decoration: const InputDecoration(
                labelText: 'عدد النقاط', border: OutlineInputBorder()),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('تطبيق')),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final pts = double.tryParse(ctrl.text.trim()) ?? 0;

    // **والرفضُ يقول سببَه بالرقم** — «غير مسموح» تُرسل الكاشيرَ يجرّب.
    if (pts <= 0) { _snack('أدخل عدد نقاط صحيحاً'); return; }
    if (pts < _loyaltyMinPoints) {
      _snack('الحدّ الأدنى للاستبدال $_loyaltyMinPoints نقطة'); return;
    }
    if (pts > _maxRedeemablePoints) {
      _snack('أقصى ما يُصرف على هذه الفاتورة '
          '${_maxRedeemablePoints.toStringAsFixed(0)} نقطة');
      return;
    }

    setState(() => _redeemPoints = pts);
  }

  /// يقيّم خصماً على الفاتورة (تلقائي أو بكوبون) ويُطبّقه.
  Future<void> _applyDiscount() async {
    final codeCtrl = TextEditingController();
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('خصم / كوبون'),
        content: TextField(
          controller: codeCtrl,
          textDirection: TextDirection.ltr,
          decoration: const InputDecoration(
            labelText: 'رمز الكوبون (اتركه فارغاً للعرض التلقائي)',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تطبيق')),
        ],
      ),
    );
    if (go != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final api = Get.find<ApiClient>();
      final r = await api.postData('/api/v1/amial/merchant/promotions/apply', {
        'subtotal': widget.total,
        if (codeCtrl.text.trim().isNotEmpty) 'code': codeCtrl.text.trim(),
      });
      if (r.statusCode == 402) { _snack('العروض متاحة في باقة ستارتر فأعلى'); return; }
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final disc = double.tryParse('${meta['discount'] ?? 0}') ?? 0;
        if (disc <= 0) { _snack('لا يوجد خصم منطبق'); return; }
        setState(() {
          _discount = disc;
          _promotionId = meta['promotion_id'] is int ? meta['promotion_id'] as int : int.tryParse('${meta['promotion_id']}');
          _promoLabel = meta['label']?.toString();
        });
        _snack('طُبّق خصم ${disc.toStringAsFixed(0)} ر.ي', ok: true);
      } else {
        _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر تطبيق الخصم');
      }
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _clearDiscount() => setState(() { _discount = 0; _promotionId = null; _promoLabel = null; });

  Future<void> _confirm() async {
    switch (_method) {
      case 'cash':
        await _recordAndShowReceipt('cash', amountReceived: _received);
      case 'amial_pay':
        await _amialPay();
      case 'credit':
        await _credit();
      case 'corporate':
        await _corporate();
      case 'mixed':
        await _mixed();
    }
  }

  /// مختلط: التاجر يحدّد الجزء المحفظي، والباقي نقد. يُحصَّل المحفظي عبر QR ثم
  /// يُسجَّل البيع بالتقسيم كاملاً (نقد + محفظة = الإجمالي بعد الخصم).
  Future<void> _mixed() async {
    final walletCtrl = TextEditingController(text: (_net / 2).toStringAsFixed(0));
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        final wallet = double.tryParse(walletCtrl.text.trim()) ?? 0;
        final cash = (_net - wallet).clamp(0, _net).toDouble();
        return AlertDialog(
          title: const Text('دفع مختلط'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('الإجمالي: ${AmialMoney.yer(_net)}',
                style: const TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: walletCtrl,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.center,
              onChanged: (_) => setLocal(() {}),
              decoration: const InputDecoration(
                labelText: 'الجزء المدفوع محفظةً (أميال باي)',
                suffixText: 'ر.ي', border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: AmialColors.background, borderRadius: BorderRadius.circular(8)),
              child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text('نقداً: ${AmialMoney.yer(cash)}',
                    style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.success)),
                Text('محفظةً: ${AmialMoney.yer(wallet)}',
                    style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary)),
              ]),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('متابعة')),
          ],
        );
      }),
    );
    if (go != true || !mounted) return;

    final wallet = (double.tryParse(walletCtrl.text.trim()) ?? 0).clamp(0, _net).toDouble();
    final cash = (_net - wallet).clamp(0, _net).toDouble();

    // كله نقد → لا حاجة لـ QR
    if (wallet <= 0) {
      final sale = await c.recordSale(
        total: _net, method: 'mixed', cashAmount: cash, walletAmount: 0,
        discountAmount: _discount, promotionId: _promotionId,
        redeemPoints: _redeemPoints > 0 ? _redeemPoints : null,
      );
      if (!mounted) return;
      if (sale == null) { if (c.lastError.value.isNotEmpty) _snack(c.lastError.value); return; }
      Get.off(() => CashierReceiptScreen(sale: sale, total: _net, method: 'mixed'));
      return;
    }

    // حصّل الجزء المحفظي عبر QR ثم سجّل البيع المختلط كاملاً
    Get.to(() => AmialQrCollectScreen(
          amount: wallet,
          note: 'دفع مختلط — جزء محفظة',
          onPaid: (paidTxId) async {
            final sale = await c.recordSale(
              total: _net, method: 'mixed', paidTransactionId: paidTxId,
              cashAmount: cash, walletAmount: wallet,
              discountAmount: _discount, promotionId: _promotionId,
              redeemPoints: _redeemPoints > 0 ? _redeemPoints : null,
            );
            if (sale == null) return false;
            Get.off(() => CashierReceiptScreen(sale: sale, total: _net, method: 'mixed'));
            return true;
          },
        ));
  }

  /// حساب شركة: يختار التاجر شركة (وعضواً اختيارياً) فيُقيَّد البيع على دَينها.
  Future<void> _corporate() async {
    setState(() => _busy = true);
    final api = Get.find<ApiClient>();
    Map<String, dynamic>? picked;
    try {
      final r = await api.getData('/api/v1/amial/merchant/corporate/accounts');
      if (r.statusCode == 402) { _snack('حسابات الشركات متاحة في الباقة المؤسسية'); return; }
      final list = (r.statusCode == 200 && r.body is Map)
          ? (((r.body['meta'] ?? {})['accounts'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map)).toList()
          : <Map<String, dynamic>>[];
      if (list.isEmpty) { _snack('لا توجد شركات — أضِفها من «حسابات الشركات»'); return; }
      if (!mounted) return;
      picked = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        builder: (ctx) => SafeArea(child: ListView(shrinkWrap: true, children: [
          const Padding(padding: EdgeInsets.all(14),
              child: Text('اختر الشركة', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15))),
          ...list.map((a) => ListTile(
                leading: const Icon(Icons.business, color: AmialColors.primary),
                title: Text('${a['company_name']}'),
                subtitle: Text('المتاح: ${a['available']} ر.ي'),
                onTap: () => Navigator.pop(ctx, a),
              )),
        ])),
      );
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    if (picked == null || !mounted) return;

    setState(() => _busy = true);
    final sale = await c.recordSale(
      total: _net,
      method: 'corporate',
      corporateAccountId: picked['id'] as int,
      discountAmount: _discount,
      promotionId: _promotionId,
    );
    if (!mounted) return;
    setState(() => _busy = false);
    // AMIAL-PAY-SOUND-001 — شبّاكُ البيع لا يمرّ بورقة النتيجة
    // الموحّدة، فتُنادى النغمةُ صراحةً. والكاشيرُ لا ينظر إلى الشاشة
    // وهو يناول الزبونَ ويعدّ النقد — فنتيجةٌ تُعرَض ولا تُسمَع
    // تُقرأ بعد أن يكون قد تصرّف.
    if (sale == null) {
      PaymentFeedback.failure();
      if (c.lastError.value.isNotEmpty) _snack(c.lastError.value);
      return;
    }
    PaymentFeedback.success();
    Get.off(() => CashierReceiptScreen(
          sale: sale,
          total: _net,
          method: 'corporate',
          customerName: '${picked!['company_name']}',
        ));
  }

  /// اختيارُ عملة القبض — **ومعه المبلغُ بها، لا الرمزُ وحدَه.**
  ///
  /// فكاشيرٌ يختار «دولار» على فاتورةٍ مكتوبٍ عليها ٢٦٥٠٠ لا يعرف كم
  /// يطلب من الزبون. والمبلغُ المطلوبُ هو ما يُقال، والمكافئُ بالريال
  /// تحته ليُقرأ ولا يُخمَّن.
  Widget _currencyPicker() {
    final rate = _rateOfSelected;
    final isBase = _currency == 'YER';

    // المطلوبُ بالعملة المختارة: الإجماليُّ بالريال ÷ السعر.
    final asked = isBase || rate == null || (double.tryParse(rate) ?? 0) <= 0
        ? _net
        : _net / double.parse(rate);

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmialColors.primary.withValues(alpha: 0.25)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Text('عملة القبض',
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          children: _acceptedCurrencies.map((w) {
            final code = '${w['currency']}';
            final on = code == _currency;
            return ChoiceChip(
              selected: on,
              label: Text('${w['name']} (${w['symbol']})',
                  style: TextStyle(fontSize: 12,
                      color: on ? Colors.white : AmialColors.textSecondary)),
              selectedColor: AmialColors.primary,
              onSelected: _busy
                  ? null
                  : (_) => setState(() {
                        _currency = code;
                        _currencySymbol = '${w['symbol']}';
                        // **ووسيلةٌ لا تصلح لهذه العملة تُبدَّل الآن لا
                        // عند الضغط** — فزرٌّ معروضٌ يُفترَض أنّه يعمل.
                        if (!_methodAllowedInCurrency(_method)) {
                          _method = 'cash';
                        }
                      }),
            );
          }).toList(),
        ),
        if (!isBase) ...[
          const Divider(height: 20),
          Text('المطلوب: ${asked.toStringAsFixed(2)} $_currencySymbol',
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                  fontSize: 18, fontWeight: FontWeight.bold,
                  color: AmialColors.primary)),
          const SizedBox(height: 4),
          Text('بسعر 1 $_currency = $rate ر.ي  ·  المكافئ ${AmialMoney.yer(_net)}',
              style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
          const SizedBox(height: 8),
          Row(children: [
            const Icon(Icons.info_outline, size: 14, color: AmialColors.textSecondary),
            const SizedBox(width: 6),
            const Expanded(
              child: Text(
                'القبض بعملة أجنبية يكون نقداً أو آجلاً — محفظة العميل بالريال.',
                style: TextStyle(fontSize: 11, color: AmialColors.textSecondary),
              ),
            ),
          ]),
        ],
      ]),
    );
  }

  /// ══════════════════════════════════════════════════════════════════
  /// AMIAL-LOYALTY-AT-PAYMENT-001 — **مدخلُ النقاط حيث يُدفَع المال.**
  ///
  /// وكان لا مدخلَ له هنا إطلاقاً: الاستبدالُ في شاشة «برنامج الولاء»
  /// يحرق النقاطَ ثمّ يطلب من الكاشير تطبيقَ الخصم بيده في شاشةٍ أخرى.
  /// (القاعدة الثانية عشرة: مبنيٌّ ولا يُوصَل إليه.)
  Widget _loyaltyCard() {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.card_giftcard_outlined,
              size: 18, color: AmialColors.primary),
          const SizedBox(width: 6),
          const Expanded(
            child: Text('نقاط الولاء',
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
          ),
          if (_redeemPoints > 0)
            TextButton(
              onPressed: _busy ? null : () => setState(() => _redeemPoints = 0),
              child: const Text('إلغاء', style: TextStyle(color: AmialColors.red)),
            ),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(
            child: TextField(
              controller: _loyaltyPhone,
              keyboardType: TextInputType.phone,
              textAlign: TextAlign.right,
              enabled: !_busy && _redeemPoints == 0,
              decoration: const InputDecoration(
                labelText: 'رقم العميل',
                hintText: '77XXXXXXX',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
          ),
          const SizedBox(width: 8),
          IconButton.filled(
            onPressed: (_busy || _loyaltyBusy || _redeemPoints > 0)
                ? null : _lookupLoyalty,
            icon: _loyaltyBusy
                ? const SizedBox(
                    width: 16, height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.search),
            style: IconButton.styleFrom(backgroundColor: AmialColors.primary),
          ),
        ]),
        if (_loyaltyLookedUp) ...[
          const SizedBox(height: 8),
          // **والرصيدُ يُقال بالنقطة وبالريال معاً** — «١٢٠ نقطة» وحدَها
          // لا يعرف الزبونُ كم توفّر عليه.
          Text(
            _loyaltyCustomerName != null && _loyaltyCustomerName!.isNotEmpty
                ? '${_loyaltyCustomerName!} · '
                    '${_loyaltyBalance.toStringAsFixed(0)} نقطة '
                    '(${AmialMoney.yer(_loyaltyBalance * _loyaltyValuePerPoint)})'
                : '${_loyaltyBalance.toStringAsFixed(0)} نقطة '
                    '(${AmialMoney.yer(_loyaltyBalance * _loyaltyValuePerPoint)})',
            style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary),
          ),
          const SizedBox(height: 8),
          if (_redeemPoints > 0)
            Text(
              'يُصرَف ${_redeemPoints.toStringAsFixed(0)} نقطة  ·  '
              'خصم ${AmialMoney.yer(_loyaltyDiscount)}',
              style: const TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.bold,
                  color: AmialColors.success),
            )
          else if (_maxRedeemablePoints <= 0)
            const Text('لا نقاطَ قابلةً للصرف على هذه الفاتورة',
                style: TextStyle(fontSize: 12, color: AmialColors.textMuted))
          else
            OutlinedButton.icon(
              onPressed: _busy ? null : _applyLoyalty,
              icon: const Icon(Icons.redeem_outlined, size: 18),
              label: const Text('استبدال نقاط بخصم'),
              style: OutlinedButton.styleFrom(
                  foregroundColor: AmialColors.primary,
                  side: const BorderSide(color: AmialColors.primary)),
            ),
        ],
      ]),
    );
  }

  /// **رقمُ صاحب النقاط ورقمُ الفاتورة يجب أن يكونا واحداً.**
  ///
  /// وإلّا صُرفت نقاطُ فلانٍ على فاتورة فلانة — ويُقيَّد الكسبُ لغير من
  /// دفع. ويُقال بالرقمين لا بـ«غير مطابق».
  String? _loyaltyCustomerConflict(Map<String, String>? customer) {
    if (_redeemPoints <= 0) return null;

    final onLoyalty = _loyaltyPhone.text.trim();
    final onInvoice = (customer?['phone'] ?? '').trim();

    if (onInvoice.isEmpty || onInvoice == onLoyalty) return null;

    return 'رقمُ النقاط ($onLoyalty) غير رقم العميل في الفاتورة ($onInvoice)';
  }

  Future<void> _recordAndShowReceipt(String method,
      {Map<String, String>? customer, String? creditDueDate,
      double? amountReceived}) async {
    final conflict = _loyaltyCustomerConflict(customer);
    if (conflict != null) { _snack(conflict); return; }

    // **ومن صرف نقاطاً صار عميلَ الفاتورة** — فبلا رقمٍ لا يُقيَّد الكسبُ
    // ولا تُربط الحركةُ بصاحبها.
    if (_redeemPoints > 0 && (customer?['phone'] ?? '').trim().isEmpty) {
      customer = {
        ...?customer,
        'phone': _loyaltyPhone.text.trim(),
        if (_loyaltyCustomerName != null && _loyaltyCustomerName!.isNotEmpty)
          'name': _loyaltyCustomerName!,
      };
    }

    setState(() => _busy = true);
    final sale = await c.recordSale(
      // AMIAL-MULTI-CURRENCY-003 — **بالعملة المختارة**، والخادمُ يضرب
      // في السعر ليحفظ المكافئ. (‏`_amountToSend` = `_net` حين تكون
      // العملةُ هي الأساس، فمسارُ الريال لم يتغيّر بحرف.)
      total: _amountToSend,
      method: method,
      customer: customer,
      creditDueDate: creditDueDate,
      discountAmount: _discountToSend,
      promotionId: _promotionId,
      currency: _currencyToSend,
      // AMIAL-LOYALTY-AT-PAYMENT-001 — تُستبدَل في معاملة البيعة نفسِها.
      redeemPoints: _redeemPoints > 0 ? _redeemPoints : null,
      amountReceived: amountReceived,
    );
    if (!mounted) return;
    setState(() => _busy = false);
    // AMIAL-PAY-SOUND-001 — شبّاكُ البيع لا يمرّ بورقة النتيجة
    // الموحّدة، فتُنادى النغمةُ صراحةً. والكاشيرُ لا ينظر إلى الشاشة
    // وهو يناول الزبونَ ويعدّ النقد — فنتيجةٌ تُعرَض ولا تُسمَع
    // تُقرأ بعد أن يكون قد تصرّف.
    if (sale == null) {
      PaymentFeedback.failure();
      if (c.lastError.value.isNotEmpty) _snack(c.lastError.value);
      return;
    }
    PaymentFeedback.success();
    Get.off(() => CashierReceiptScreen(
          sale: sale,
          // **الإيصالُ يعرض ما دفعه الزبونُ فعلاً** — بالعملة التي دفع بها.
          total: _amountToSend,
          method: method,
          customerName: customer?['name'],
          currencySymbol: _isBaseCurrency ? null : _currencySymbol,
          baseTotal: _net,
        ));
  }

  /// أميال باي: يعرض QR بمبلغ ثابت يمسحه العميل ويدفع من محفظته، ثم يُسجَّل
  /// البيع مربوطاً بمرجع الدفع وتُفتح الفاتورة تلقائياً (استقبال حقيقي).
  Future<void> _amialPay() async {
    Get.to(() => AmialQrCollectScreen(
          amount: _net,
          note: 'دفع مشتريات',
          onPaid: (paidTxId) async {
            final sale = await c.recordSale(
              total: _net,
              method: 'amial_pay',
              paidTransactionId: paidTxId,
              discountAmount: _discount,
              promotionId: _promotionId,
            );
            if (sale == null) return false;
            Get.off(() => CashierReceiptScreen(
                  sale: sale,
                  total: _net,
                  method: 'amial_pay',
                ));
            return true;
          },
        ));
  }

  /// آجل: بيانات العميل (اسم + رقم) وتاريخ استحقاق اختياري.
  Future<void> _credit() async {
    final name = TextEditingController();
    final phone = TextEditingController();
    DateTime? due;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: EdgeInsets.fromLTRB(
              20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 44,
              height: 4,
              decoration: BoxDecoration(
                  color: Colors.grey.shade300,
                  borderRadius: BorderRadius.circular(2)),
            ),
            const SizedBox(height: 16),
            const Text('بيع آجل — بيانات العميل',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            Text('سيُقيَّد ${AmialMoney.yer(widget.total)} على حساب العميل في دفتر الديون',
                style: const TextStyle(
                    fontSize: 12, color: AmialColors.textSecondary)),
            const SizedBox(height: 16),
            TextField(
              controller: name,
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                  labelText: 'اسم العميل *', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: phone,
              keyboardType: TextInputType.phone,
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                  labelText: 'رقم العميل *',
                  hintText: '77XXXXXXX',
                  border: OutlineInputBorder()),
              // AMIAL-CREDIT-AT-TILL-001 — **يُقرأ الدَّينُ قبل أن يُزاد.**
              // فالبائعُ كان يبيع آجلاً وهو لا يعرف كم على الزبون.
              onChanged: (_) => setLocal(() {}),
            ),
            CustomerBalanceBadge(phone: phone.text),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              icon: const Icon(Icons.calendar_month_outlined, size: 18),
              label: Text(due == null
                  ? 'تاريخ الاستحقاق (اختياري)'
                  : 'الاستحقاق: ${due!.year}/${due!.month}/${due!.day}'),
              style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(46)),
              onPressed: () async {
                final d = await showDatePicker(
                  context: ctx,
                  initialDate: DateTime.now().add(const Duration(days: 30)),
                  firstDate: DateTime.now(),
                  lastDate: DateTime.now().add(const Duration(days: 365)),
                );
                if (d != null) setLocal(() => due = d);
              },
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () {
                if (name.text.trim().isEmpty || phone.text.trim().isEmpty) {
                  ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
                      content: Text('اسم العميل ورقمه مطلوبان'),
                      backgroundColor: AmialColors.red));
                  return;
                }
                Navigator.pop(ctx, true);
              },
              style: FilledButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  minimumSize: const Size.fromHeight(52)),
              child: const Text('تسجيل البيع الآجل'),
            ),
          ]),
        ),
      ),
    );
    if (ok != true || !mounted) return;
    await _recordAndShowReceipt(
      'credit',
      customer: {'name': name.text.trim(), 'phone': phone.text.trim()},
      creditDueDate: due == null
          ? null
          : '${due!.year}-${due!.month.toString().padLeft(2, '0')}-${due!.day.toString().padLeft(2, '0')}',
    );
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تأكيد الدفع'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // ====== الإجمالي ======
          Container(
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  AmialColors.yellow.withValues(alpha: 0.25),
                  Colors.white,
                ],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
              borderRadius: BorderRadius.circular(18),
              border:
                  Border.all(color: AmialColors.yellow.withValues(alpha: 0.6)),
            ),
            child: Column(children: [
              const Text('إجمالي المطلوب سداده',
                  style: TextStyle(
                      fontSize: 13, color: AmialColors.textSecondary)),
              const SizedBox(height: 8),
              if (_discount > 0 || _redeemPoints > 0)
                Text(AmialMoney.yer(widget.total),
                    style: const TextStyle(
                        fontSize: 16,
                        color: AmialColors.textMuted,
                        decoration: TextDecoration.lineThrough)),
              Text(AmialMoney.yer(_net),
                  style: const TextStyle(
                      fontSize: 34,
                      fontWeight: FontWeight.bold,
                      color: AmialColors.primary)),
              if (_redeemPoints > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                      'نقاط ولاء ${_redeemPoints.toStringAsFixed(0)} • '
                      'خصم ${AmialMoney.yer(_loyaltyDiscount)}',
                      style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: AmialColors.success)),
                ),
              if (_discount > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('خصم ${AmialMoney.yer(_discount)}${_promoLabel != null ? ' • $_promoLabel' : ''}',
                      style: const TextStyle(
                          fontSize: 12, fontWeight: FontWeight.bold, color: AmialColors.success)),
                ),
            ]),
          ),
          const SizedBox(height: 12),

          // ====== عملة القبض (تظهر لمن فعّل أكثر من عملة) ======
          if (_acceptedCurrencies.length > 1) _currencyPicker(),

          // ====== خصم / كوبون (باقة ستارتر فأعلى) ======
          AccessGate(feature: 'promotions', child: Align(
            alignment: Alignment.centerRight,
            child: _discount > 0
                ? TextButton.icon(
                    onPressed: _busy ? null : _clearDiscount,
                    icon: const Icon(Icons.close, size: 18, color: AmialColors.red),
                    label: const Text('إزالة الخصم', style: TextStyle(color: AmialColors.red)),
                  )
                : OutlinedButton.icon(
                    onPressed: _busy ? null : _applyDiscount,
                    icon: const Icon(Icons.local_offer_outlined, size: 18),
                    label: const Text('تطبيق خصم / كوبون'),
                    style: OutlinedButton.styleFrom(
                        foregroundColor: AmialColors.primary,
                        side: const BorderSide(color: AmialColors.primary)),
                  ),
          )),
          const SizedBox(height: 10),

          // ====== نقاط الولاء (باقة الأعمال فأعلى) ======
          AccessGate(feature: 'loyalty', child: _loyaltyCard()),

          // ====== المبلغ المستلم والباقي (نقداً فقط) ======
          //
          // **ولا يُعرَض في الآجل ولا المحفظة** — لا مستلَمَ فيهما أصلاً،
          // وحقلٌ يُعرَض حيث لا معنى له يُعلّم البائعَ أن يتجاهل الشاشة.
          if (_method == 'cash')
            CashTenderedField(
              net: _amountToSend,
              symbol: _isBaseCurrency ? null : _currencySymbol,
              onChanged: (v) => setState(() => _received = v),
            ),

          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: const [
            Text('الرجاء تحديد خيار واحد',
                style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
            Text('اختر وسيلة الدفع',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 12),

          // AMIAL-MULTI-CURRENCY-003 — **وسائلُ المحفظة تُخفى مع عملةٍ
          // أجنبيّة، ولا تُعرَض ثمّ تُرفَض.**
          //
          // «أميال باي» و«مختلط» و«حساب شركة» تخصم من رصيدٍ بالريال،
          // فاختيارُها مع الدولار يُسجّل بيعةً بعملةٍ لم يدفع بها الزبون.
          // وزرٌّ معروضٌ يُفترَض أنّه يعمل — فإخفاؤه أصدقُ من رفضٍ بعد
          // الضغط والزبونُ واقف. (والسببُ مكتوبٌ في بطاقة العملة أعلاه.)
          MerchantPaymentMethodPicker(
            layout: MerchantPaymentPickerLayout.cards,
            selectedValue: _method,
            onChanged: _busy ? null : (value) => setState(() => _method = value),
            options: [
              MerchantPaymentOption.cash,
              if (_methodAllowedInCurrency('amial_pay'))
                MerchantPaymentOption.amialPay,
              MerchantPaymentOption.credit,
              if (_methodAllowedInCurrency('mixed'))
                MerchantPaymentOption.mixed,
            ],
          ),

          // AMIAL-SECTOR-PAY-UNIFY-001 — **اللافتةُ نفسُها في كلّ قطاع.**
          // سأل صاحبُ المشروع «أيٌّ منهم مرتبطٌ الآجلُ فيه بنظام الديون؟»
          // — والجوابُ: كلُّها. فتقوله الشاشةُ بدل أن يُخمَّن، وبنصٍّ واحدٍ
          // مشتركٍ فلا يفترق عن نصّ الصيدليّة بعد أوّل تعديل.
          if (_method == 'credit') const CreditSaleNotice(),

          if (_methodAllowedInCurrency('corporate'))
            AccessGate(feature: 'corporate_accounts', child: _methodCard(
              value: 'corporate',
              icon: Icons.business_center_outlined,
              title: 'حساب شركة',
              subtitle: 'قيد العملية على حساب شركة (ضمن حدّ الائتمان)',
            )),
          const SizedBox(height: 20),

          FilledButton.icon(
            onPressed: _busy ? null : _confirm,
            icon: _busy
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.keyboard_double_arrow_left),
            label: const Text('تأكيد ومعالجة الدفع',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary,
              minimumSize: const Size.fromHeight(56),
              shape:
                  RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            ),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: () => Get.back(),
            child: const Text('إلغاء العملية',
                style: TextStyle(color: AmialColors.textSecondary)),
          ),
        ],
      ),
    );
  }

  Widget _methodCard({
    required String value,
    required IconData icon,
    required String title,
    required String subtitle,
    bool recommended = false,
    bool disabled = false,
  }) {
    final selected = _method == value;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        onTap: disabled
            ? () => _snack('الدفع المختلط سيتوفّر قريباً')
            : () => setState(() => _method = value),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: disabled
                ? Colors.white.withValues(alpha: 0.6)
                : selected
                    ? const Color(0xFFEDF3EF)
                    : Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: selected && !disabled
                  ? AmialColors.primary
                  : AmialColors.border,
              width: selected && !disabled ? 1.6 : 1,
            ),
          ),
          child: Row(children: [
            if (recommended)
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AmialColors.yellow,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Text('موصى به',
                    style: TextStyle(
                        fontSize: 9,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF053391))),
              ),
            const Spacer(),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(title,
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: disabled ? AmialColors.textMuted : Colors.black87)),
              Text(subtitle,
                  style: const TextStyle(
                      fontSize: 11, color: AmialColors.textMuted)),
            ]),
            const SizedBox(width: 12),
            Container(
              height: 46,
              width: 46,
              decoration: BoxDecoration(
                color: selected && !disabled
                    ? AmialColors.primary
                    : const Color(0xFFE9EEF6),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon,
                  size: 22,
                  color: selected && !disabled
                      ? Colors.white
                      : AmialColors.primary),
            ),
          ]),
        ),
      ),
    );
  }
}
