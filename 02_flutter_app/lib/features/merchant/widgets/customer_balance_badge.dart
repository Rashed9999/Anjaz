import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CREDIT-AT-TILL-001 — **دَينُ العميل يُقرأ قبل أن يُزاد.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **من أين جاءت:** في الشاشة المنافسة يظهر رصيدُ الحساب بجانب اسمه لحظةَ
/// اختياره في الفاتورة. وقِيس في أميال: **دفترُ الديون شاشةٌ أخرى**،
/// فالبائعُ يبيع آجلاً وهو لا يعرف كم على الزبون — فيبيع لمن عليه أربعون
/// ألفاً وحدُّه ثلاثون، **ولا يُكتشَف إلّا حين يفتح المالكُ الدفتر بعد
/// أيّام**.
///
/// **والحدُّ يُقال ولا يُفرَض** — والقرارُ لصاحبه. ثلاثةُ أسبابٍ مقيسة:
/// كثيرٌ من الحسابات حدُّها صفرٌ (أي غيرُ مضبوط) فمنعُها يُقفل البيعَ
/// الآجلَ على الجميع · والبائعُ قد يكون صاحبَ المتجر نفسَه · والقرارُ
/// التجاريُّ ليس قرارَ شيفرة.
///
/// **وثلاثُ حالاتٍ لا اثنتان** (القاعدة السابعة):
///
///   · **لا حساب** — عميلٌ جديد. وليست «عليه صفر».
///   · **عليه X** — ويُقال المتبقّي إن كان له حدّ.
///   · **بلغ حدَّه** — لافتةٌ حمراءُ تُقرأ قبل الضغط لا بعده.
///
/// **وأداةٌ مشتركةٌ لا نسخةٌ في كلّ شبّاك:** الكاشيرُ العامُّ وشبّاكُ
/// الصيدليّة يبيعان آجلاً كلاهما، ونسختان تفترقان فتقول إحداهما «بلغ
/// حدَّه» والأخرى تسكت على الحساب نفسِه.
class CustomerBalanceBadge extends StatefulWidget {
  const CustomerBalanceBadge({super.key, required this.phone});

  /// رقمُ العميل كما هو في الحقل — تُعاد القراءةُ كلَّما تغيّر.
  final String phone;

  @override
  State<CustomerBalanceBadge> createState() => _CustomerBalanceBadgeState();
}

class _CustomerBalanceBadgeState extends State<CustomerBalanceBadge> {
  bool _busy = false;
  bool _done = false;
  String? _error;

  bool _found = false;
  String? _name;
  double _balance = 0;
  double? _limit;
  double? _remaining;
  bool _overLimit = false;

  @override
  void initState() {
    super.initState();
    _lookup();
  }

  @override
  void didUpdateWidget(covariant CustomerBalanceBadge old) {
    super.didUpdateWidget(old);
    if (old.phone != widget.phone) _lookup();
  }

  Future<void> _lookup() async {
    final phone = widget.phone.trim();

    // **ولا يُسأل الخادمُ عن رقمٍ ناقص** — كلُّ حرفٍ يُكتب نداءٌ، والشبّاكُ
    // على شبكةٍ ضعيفة.
    if (phone.length < 7) {
      setState(() { _done = false; _busy = false; _error = null; });
      return;
    }

    setState(() { _busy = true; _error = null; });

    try {
      final r = await Get.find<ApiClient>()
          .getData('/api/v1/amial/merchant/credit/lookup', query: {'phone': phone});

      if (!mounted) return;

      if (r.statusCode == 200 && r.body is Map) {
        final m = (r.body['meta'] ?? {}) as Map;
        setState(() {
          _done = true;
          _found = m['found'] == true;
          _name = m['customer_name']?.toString();
          _balance = double.tryParse('${m['current_balance'] ?? 0}') ?? 0;
          _limit = m['credit_limit'] == null
              ? null : double.tryParse('${m['credit_limit']}');
          _remaining = m['remaining'] == null
              ? null : double.tryParse('${m['remaining']}');
          _overLimit = m['is_over_limit'] == true;
        });
      } else {
        // **ويُقال تعذُّرُ القراءة ولا يُترَك فراغاً** — فراغٌ يُقرأ
        // «لا دينَ عليه»، وهو أخطرُ من رسالةِ عطل.
        setState(() { _done = false; _error = 'تعذّرت قراءة رصيد العميل'; });
      }
    } catch (_) {
      if (mounted) setState(() { _done = false; _error = 'تعذّر الاتّصال'; });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_busy) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 8),
        child: Row(children: [
          SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
          SizedBox(width: 8),
          Text('يُقرأ رصيد العميل…',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
        ]),
      );
    }

    if (_error != null) return _box(_error!, AmialColors.yellowDark, Icons.wifi_off_outlined);

    if (!_done) return const SizedBox.shrink();

    if (!_found) {
      return _box('عميل جديد — لا حساب ديونٍ له بعد',
          AmialColors.textSecondary, Icons.person_add_alt_outlined);
    }

    final who = (_name ?? '').trim().isEmpty ? '' : '${_name!} · ';

    if (_overLimit) {
      return _box(
          '$whoعليه ${AmialMoney.yer(_balance)} — بلغ حدَّه '
          '(${AmialMoney.yer(_limit ?? 0)})',
          AmialColors.red, Icons.warning_amber_rounded);
    }

    final tail = _remaining != null
        ? ' · يتبقّى له ${AmialMoney.yer(_remaining!)}'
        : '';

    return _box('$whoعليه ${AmialMoney.yer(_balance)}$tail',
        _balance > 0 ? AmialColors.yellowDark : AmialColors.success,
        Icons.account_balance_wallet_outlined);
  }

  Widget _box(String text, Color color, IconData icon) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(9),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(9),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(children: [
        Icon(icon, size: 16, color: color),
        const SizedBox(width: 7),
        Expanded(
          child: Text(text,
              style: TextStyle(fontSize: 12, height: 1.4, color: color,
                  fontWeight: FontWeight.w600)),
        ),
      ]),
    );
  }
}
