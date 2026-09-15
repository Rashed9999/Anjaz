import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SECTOR-PAY-UNIFY-001 — **«آجل» يقول إنّه دين.**
///
/// ══════════════════════════════════════════════════════════════════════
/// سأل صاحبُ المشروع: **«لا أعلم أيٌّ منهم مرتبطٌ الآجلُ فيه بنظام
/// الديون»** — وهو سؤالٌ لا يُجاب عنه من الشاشة اليوم: زرٌّ اسمُه «آجل»
/// في كلّ قطاع، ولا شيءَ يقول ما يفعله.
///
/// **وقِيس فالجوابُ أنّ الأربعةَ موصولةٌ كلُّها:**
///
///     تجزئة/سريع → CashierService        → CustomerCreditService  ✓
///     صيدليّة    → PharmacySaleService   → CustomerCreditService  ✓
///     وقود       → FuelStationService    → CustomerCreditService  ✓
///     مطعم       → closeOrder → CashierService (تفويض)            ✓
///     جملة       → فواتيرُ + WholesaleCollectionService           ✓
///
/// **فالعطلُ ليس في الوصل بل في الصمت عنه.** والبائعُ الذي لا يعرف أنّ
/// «آجل» يفتح ديناً قد يضغطه ليُنهيَ زبوناً مستعجلاً، فيصير على المتجر
/// ذمّةٌ لا يعرف صاحبُه أنّها فُتحت.
///
/// **ولمَ أداةٌ مشتركةٌ لا نصٌّ في كلّ شاشة:** نصّان يفترقان بعد أوّل
/// تعديل، فيقول أحدُهما ما لا يقوله الآخر عن الفعل نفسِه.
class CreditSaleNotice extends StatelessWidget {
  const CreditSaleNotice({
    super.key,
    this.customerLabel,
    this.missingCustomerMessage,
    this.dense = false,
  });

  /// «أحمد — 7xxxxxxxx» إن عُرف العميل. و`null` يعني أنّه لم يُدخَل بعد.
  final String? customerLabel;

  /// نصٌّ خاص لقطاع لديه عميل مختار لكن لا يملك رقم هاتف بعد (الجملة).
  /// لا نكذب فنقول إن الدين ظهر في تطبيق العميل قبل ربط هويته.
  final String? missingCustomerMessage;

  /// صيغةٌ أضيقُ لورقةٍ سفليّةٍ ضيّقة (شاشةُ الصيدليّة).
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final known = (customerLabel ?? '').trim().isNotEmpty;

    return Container(
      width: double.infinity,
      margin: EdgeInsets.only(top: dense ? 6 : 10),
      padding: EdgeInsets.all(dense ? 9 : 12),
      decoration: BoxDecoration(
        color: AmialColors.warningSurface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AmialColors.yellowDark.withValues(alpha: 0.35)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(Icons.account_balance_wallet_outlined,
            size: dense ? 15 : 18, color: AmialColors.yellowDark),
        SizedBox(width: dense ? 6 : 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              'يُقيَّد ديناً على حساب العميل',
              style: TextStyle(
                fontSize: dense ? 12 : 13,
                fontWeight: FontWeight.bold,
                color: AmialColors.textPrimary,
              ),
            ),
            SizedBox(height: dense ? 2 : 4),
            Text(
              known
                  // **ويُسمّى من عليه الدين** — «دينٌ على العميل» بلا اسمٍ
                  // لا يُحصَّل، ولا يُعرف على من هو بعد أسبوع.
                  ? 'على: $customerLabel  ·  يظهر في «دفتر الديون» وتُحصَّل منه دفعاتُه.'
                  : (missingCustomerMessage ??
                      'أدخل رقم العميل — فدَينٌ بلا صاحبٍ لا يُحصَّل. '
                          'ويظهر بعدها في «دفتر الديون».'),
              style: TextStyle(
                fontSize: dense ? 10.5 : 11.5,
                height: 1.45,
                color: AmialColors.textSecondary,
              ),
            ),
          ]),
        ),
      ]),
    );
  }
}
