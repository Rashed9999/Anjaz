import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';

/// AMIAL-SHIFT-DOOR-001 — **الحدُّ قائمٌ ولا بابَ في اللوحة.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمنُ الذي دُفع.** أرسل صاحبُ المشروع صورتَي لوحة تجزئةٍ وصيدليّة
/// وقال: «يطلبون فتحَ ورديّةٍ عند العمل على الكاشير — هذا جيّد. **المشكلة
/// لا يوجد نظامُ الورديّات هنا**، بينما الوقودُ موجود».
///
/// **وقِيس، فكان ثلاثةَ أشياء:**
///
///   ① **لوحةُ الصيدليّة بلا بلاطةِ ورديّةٍ إطلاقاً** — والخادمُ يردّ ٤٠٩
///      على كلّ بيعة. فالمستعملُ أمام حدٍّ لا يعرف بابَه.
///
///   ② **ولوحةُ التجزئة فيها بلاطةٌ اسمُها «إقفال الوردية»** — والفعلُ
///      الذي يسبق البيعَ هو **الفتح** لا الإقفال. فمن أراد أن يبدأ لم
///      يبحث عن «إقفال»، ووجدها بين العروضِ والولاءِ وبطاقاتِ الهدايا
///      بعيداً عن «الكاشير». **اسمٌ يصف نصفَ الفعل يُخفي نصفَه.**
///
///   ③ **ولا تقول البلاطةُ حالةً**: أمفتوحةٌ الورديّةُ أم لا، ومنذ متى،
///      وباسم من. فيفتحها التاجرُ ليعرف، أو يبيع فيُردّ.
///
/// **والفرقُ مع الوقود ليس بناءً بل باباً**: `FuelOwnerConsoleScreen` فيها
/// «الورديات» بندٌ ظاهرٌ في مجموعة التشغيل، وشاشةُ `CashierShiftScreen`
/// مبنيّةٌ كاملةً للتجزئة والصيدليّة (فتحٌ · تقرير X · إقفالٌ · سجلّ) —
/// **ومداخلُها كانت في «خدمات المنشأة» وشاشة موظّف نقطة البيع وسجلّ
/// القدرات، ولا واحدَ منها اللوحةُ التي يفتحها صاحبُ المتجر كلَّ صباح.**
/// (القاعدة الثانية عشرة: المسارُ المسجَّل ليس ظهوراً.)
///
/// **وتقرأ الحالةَ ولا تخمّنها**، ومن تعذّرت عليه القراءة يُقال له ذلك
/// ولا يُقرأ «لا ورديّة» — فالغيابُ والجهلُ لا يُخلطان (القاعدة السابعة).
///
/// يظهر في : التطبيق ← لوحة التجزئة · ولوحة الصيدليّة. وفي لوحة الإدارة: لا.
class ShiftStatusTile extends StatefulWidget {
  const ShiftStatusTile({super.key});

  @override
  State<ShiftStatusTile> createState() => _ShiftStatusTileState();
}

class _ShiftStatusTileState extends State<ShiftStatusTile> {
  bool _loading = true;
  bool _unreadable = false;
  bool _required = true;

  /// `null` = مغلقةٌ أو تعذّرت القراءة — ويُفرَّق بينهما بـ[_unreadable].
  Map<String, dynamic>? _shift;

  @override
  void initState() {
    super.initState();

    // **ولا يُنادى `setState` من `initState`** — الشجرةُ في طور البناء،
    // فتُرمى `_dependents.isEmpty` وتسقط الشاشةُ كلُّها معها. (كشفه
    // `screens_widget_test` عند أوّل تشغيل، وهو ما تمسكه الطبقةُ العاشرة
    // ولا يراه `flutter analyze`.)
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _load();
    });
  }

  Future<void> _load() async {
    if (!mounted) return;
    setState(() { _loading = true; _unreadable = false; });

    try {
      // **ولا يُحَلُّ المورِدُ في مُهيّئ حقل** — يُنفَّذ عند الإنشاء، فإن
      // لم يكن مسجَّلاً رُمي الاستثناءُ **أثناء بناء الشجرة** فأسقط
      // اللوحةَ كلَّها معه، لا البلاطةَ وحدَها. (كشفته اختباراتُ الشاشات:
      // «ApiClient not found» ثمّ انهيارُ ثلاثِ لوحات.)
      //
      // **وبلاطةٌ تُسقط لوحةً أسوأ من بلاطةٍ لا تعمل.**
      final api = Get.isRegistered<ApiClient>() ? Get.find<ApiClient>() : null;

      if (api == null) {
        setState(() { _unreadable = true; _shift = null; });

        return;
      }

      final r = await api.getData('/api/v1/amial/cashier/shift');

      if (!mounted) return;

      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final s = meta['shift'];
        setState(() {
          _shift = s is Map ? Map<String, dynamic>.from(s) : null;
          _required = meta['required'] is bool ? meta['required'] as bool : true;
        });
      } else {
        setState(() { _unreadable = true; _shift = null; });
      }
    } catch (_) {
      if (mounted) setState(() { _unreadable = true; _shift = null; });
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open() async {
    await Get.to(() => const CashierShiftScreen());
    if (mounted) await _load();     // الحالةُ تُعاد بعد الرجوع، لا تُفترَض.
  }

  @override
  Widget build(BuildContext context) {
    final open = _shift != null;

    final (IconData icon, Color color, String title, String subtitle) =
        switch ((_loading, _unreadable, open)) {
      (true, _, _) => (
        Icons.schedule_rounded, AmialColors.textMuted,
        'الوردية', 'جارٍ قراءة الحالة…'),

      // **«تعذّرت القراءة» ليست «لا ورديّة»** — ولا يُرسَل صاحبُها يفتح
      // ثانيةً فوق مفتوحة.
      (_, true, _) => (
        Icons.wifi_off_rounded, AmialColors.warning,
        'الوردية', 'تعذّرت قراءة الحالة — اضغط للمحاولة'),

      (_, _, true) => (
        Icons.lock_clock_rounded, AmialColors.success,
        'الوردية مفتوحة', _openedLine()),

      _ => (
        Icons.play_circle_fill_rounded,
        _required ? AmialColors.warning : AmialColors.textMuted,
        _required ? 'افتح الوردية' : 'الوردية (اختيارية)',
        _required
            ? 'لا يُقبض نقدٌ قبل فتحها — والدرجُ يُجرَد عند الإقفال'
            : 'الإلزامُ مُطفأٌ من لوحتك — تُفتَح للجرد إن شئت'),
    };

    return Card(
      key: const Key('shift-status-tile'),
      margin: const EdgeInsets.symmetric(vertical: 6),
      child: ListTile(
        leading: Icon(icon, color: color, size: 30),
        title: Text(title,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
        subtitle: Text(subtitle,
            style: const TextStyle(fontSize: 12, height: 1.35)),
        trailing: const Icon(Icons.chevron_left_rounded),
        onTap: _unreadable ? _load : _open,
      ),
    );
  }

  /// **ومن فتحها ومتى** — فالفاتورةُ تُطبَع باسمه، والفرقُ يُنسَب إليه.
  String _openedLine() {
    final by = (_shift?['opened_by_name'] ?? '').toString().trim();
    final at = (_shift?['opened_at'] ?? '').toString().trim();

    final parts = <String>[
      if (by.isNotEmpty) 'باسم $by',
      if (at.isNotEmpty) 'منذ ${at.length >= 16 ? at.substring(11, 16) : at}',
    ];

    return parts.isEmpty
        ? 'اضغط للجرد والإقفال'
        : '${parts.join(' · ')} — اضغط للجرد والإقفال';
  }
}
