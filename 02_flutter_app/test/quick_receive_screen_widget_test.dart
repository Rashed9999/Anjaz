// AMIAL-QUICK-RECEIVE-002 — **سطحٌ يُفتح قبل تسجيل الدخول.**
//
// ══════════════════════════════════════════════════════════════════════
// هذه الشاشةُ تُفتح **من شاشة الدخول، بلا مصادقة**. فكلُّ ما تعرضه يراه
// من يحمل الجهاز، لا من يملك الحساب: من وجده مفقوداً، ومن استعاره
// دقيقة، ومن سرقه.
//
// فالعقدُ ليس «تعمل» بل **«لا تُظهر أكثرَ ممّا يجب، ولا تفعل شيئاً
// سوى الاستلام»**. وهو عقدُ خصوصيّةٍ لا عقدُ واجهة، ولا يُحرَس بقراءة
// الشيفرة نصّاً: تُبنى الشاشةُ ويُقرأ ما رُسم فعلاً.
//
// ══════════════════════════════════════════════════════════════════════
// **ولماذا هنا لا في الخادم:** الخادمُ لا يعرف ما رسمته الشاشة. ولو
// سرّب هذا السطحُ الرصيدَ أو الحركاتِ لَما ظهر في أيّ سجلٍّ ولا أسقط
// اختباراً من ٣٩٦ ملفّاً.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/features/auth/domain/quick_receive_preferences.dart';
import 'package:amial_pay/features/auth/screens/quick_receive_screen.dart';

void main() {
  const owner = '967771234567';
  const address = 'AMIAL-9F3K2Q7T';

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    Get.put<SharedPreferences>(await SharedPreferences.getInstance());
  });

  tearDown(Get.reset);

  /// ══════════════════════════════════════════════════════════════════
  /// **السطحُ يحتاج شيئين لا واحداً**: تفضيلَ الاستلام، **و**مستخدماً
  /// أخيراً عميلاً هاتفُه هو هاتفُ المالك.
  ///
  /// وهذا حارسُ مِلكيّةٍ سليم: حسابٌ آخرُ دخل على الجهاز نفسِه لا يرى
  /// سطحَ من سبقه. **وهو ما يجعل شكوى ① أعمقَ ممّا بدت**: الدخولُ
  /// بالبصمة لم يكن يكتب «آخرَ مستخدم»، فكان هذا السطحُ كلُّه ميّتاً
  /// لمن يدخل ببصمته — لا البطاقةُ وحدَها.
  Future<void> arm() async {
    await UnifiedAuthController.rememberLastUser(
      name: 'راشد محمد المعربي', phone: owner, kind: 'customer');

    final ok = await QuickReceivePreferences.enable(
      displayName: 'راشد محمد المعربي',
      receiveAddress: address,
      ownerPhone: owner,
    );
    expect(ok, isTrue, reason: 'تعذّر التفعيل — والاختبارُ يقيس غيرَ ما يدّعي');
  }

  Future<void> pump(WidgetTester t) async {
    await t.pumpWidget(const MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: QuickReceiveScreen(
          displayName: 'راشد محمد المعربي',
          paymentAddress: owner,
        ),
      ),
    ));
    await t.pumpAndSettle();
  }

  /// كلُّ نصٍّ رُسم فعلاً على الشاشة — لا ما في الشيفرة.
  String rendered(WidgetTester t) => t
      .widgetList<Text>(find.byType(Text))
      .map((w) => w.data ?? '')
      .join('\n');

  // ══════════════════════════════════════════════════════════════════
  //  ① غيرُ مفعَّلةٍ: تقول لماذا، ولا تُظهر شيئاً
  // ══════════════════════════════════════════════════════════════════

  testWidgets('بلا تفعيلٍ تُبنى وتقول السبب — ولا تُظهر معرِّفاً', (t) async {
    await pump(t);

    expect(find.textContaining('غير مفعّل'), findsOneWidget,
        reason: 'شاشةٌ فارغةٌ بلا سببٍ تُقرأ عطلاً — والمستعملُ يذهب '
            'إلى الدعم بلا معلومة');

    // **ولا يُسرَّب شيءٌ قبل التفعيل** — ولا حتّى مقنَّعاً.
    expect(rendered(t).contains(owner), isFalse,
        reason: 'ظهر رقمُ المالك على سطحٍ يُفتح بلا مصادقة');

    // **والحالةُ غيرُ المفعَّلة سطحٌ بلا مصادقةٍ كذلك.**
    //
    // جُرّب هذا بالعكس فمرّ: دُسّ زرُّ إرسالٍ في هذه الحالة وحدَها،
    // ولم يره حارسٌ يفحص الحالةَ المفعَّلة فقط. **وشاشةٌ لها حالتان
    // تُختبَر من حالتيها** — وهي القاعدةُ الرابعة على الحالات لا
    // على المداخل.
    _expectNoMoneyAffordance(t);
  });

  // ══════════════════════════════════════════════════════════════════
  //  ② مفعَّلةً: تعمل — ولا تُظهر العنوانَ كاملاً أبداً
  // ══════════════════════════════════════════════════════════════════

  testWidgets('مفعَّلةً تعرض الاستلام — والمعرِّفُ مقنَّعٌ لا كامل', (t) async {
    await arm();
    await pump(t);

    final text = rendered(t);

    expect(text.contains('غير مفعّل'), isFalse,
        reason: 'فُعّلت ولم تُقرأ الحالة');

    // ══════════════════════════════════════════════════════════════
    // **العقدُ الأوّل: لا عنوانَ كاملاً على سطحٍ بلا مصادقة.**
    //
    // من حمل الجهازَ يرى ما رُسم. ومعرِّفُ استلامٍ كاملٌ يكفي لبناء
    // صورةٍ عن صاحبه ومطالبته، فيُقنَّع ويُعرَض آخرُه وحدَه.
    // ══════════════════════════════════════════════════════════════
    expect(text.contains(address), isFalse,
        reason: '**عُرض معرِّفُ الاستلام كاملاً** على شاشةٍ تُفتح قبل '
            'الدخول — فمن حمل الجهاز قرأه');

    expect(text.contains('••••••'), isTrue,
        reason: 'لا قناعَ إطلاقاً — فإمّا كُشف العنوانُ وإمّا اختفى، '
            'وكلاهما ليس العقد');

    expect(text.contains(owner), isFalse,
        reason: 'ظهر هاتفُ المالك — وهو ليس عنوانَ الاستلام أصلاً');
  });

  testWidgets('والاسمُ لا يُعرَض كاملاً — لقبُ العائلة يُعرِّف صاحبَه', (t) async {
    await arm();
    await pump(t);

    expect(rendered(t).contains('راشد محمد المعربي'), isFalse,
        reason: 'الاسمُ الثلاثيُّ كاملاً على سطحٍ بلا مصادقة — وهو '
            'يُعرِّف صاحبَه لمن وجد الجهاز');
  });

  // ══════════════════════════════════════════════════════════════════
  //  ③ **ولا فعلَ سوى الاستلام** — وهذا هو العقدُ الذي يحمي المال
  // ══════════════════════════════════════════════════════════════════

  testWidgets('**لا رصيدَ ولا حركاتٍ ولا إرسالَ ولا سحب**', (t) async {
    await arm();
    await pump(t);

    final text = rendered(t);

    // ══════════════════════════════════════════════════════════════
    // **وهذا أخطرُ ما في الشاشة.** سطحٌ يُفتح بلا مصادقةٍ ويحمل زرَّ
    // إرسالٍ واحداً يعني أنّ من وجد الهاتفَ يُحوّل المال. والشاشةُ
    // مكتوبةٌ صحيحةً اليوم — **والحارسُ لئلّا يُضاف زرٌّ غداً**
    // بحسن نيّة: «نضيف الرصيدَ ليعرف كم وصله».
    //
    // ══════════════════════════════════════════════════════════════
    // **ويُقاس ما يُضغَط لا ما يُكتب.**
    //
    // جُرّب هذا أوّلاً على النصّ كلِّه فسقط على جملةٍ **تشرح** ولا
    // تفعل: «التحويل يُنفَّذ على خادم أميال، وقد يتأخر إشعار الهاتف».
    // وهي جملةٌ نافعةٌ يجب أن تبقى.
    //
    // فحارسٌ يمنع نصّاً صحيحاً يُعطَّل، ثمّ لا يحرس شيئاً. والسؤالُ
    // الصحيح: **أثمّة عنصرٌ يُضغَط فيُحرّك مالاً؟**
    // ══════════════════════════════════════════════════════════════
    _expectNoMoneyAffordance(t);

    // **ولا يُقرأ رصيدٌ ولا حركةٌ نصّاً كذلك** — العرضُ تسريبٌ وإن لم
    // يكن فعلاً. (والكلماتُ هنا لا تردُ في شرحٍ مشروع، خلافاً
    // لـ«التحويل».)
    for (final leaked in const ['رصيدك', 'آخر الحركات', 'ر.ي']) {
      expect(text.contains(leaked), isFalse,
          reason: '**عُرض «$leaked»** على سطحٍ يُفتح بلا مصادقة');
    }
  });
}

/// نصُّ عنصرٍ قابلٍ للضغط — أوّلُ `Text` داخله، أو فراغٌ إن لم يكن.
String _label(Widget? child) {
  if (child is Text) return child.data ?? '';
  if (child is Row) {
    return child.children.map(_label).join(' ');
  }
  if (child is Column) {
    return child.children.map(_label).join(' ');
  }
  if (child is Padding) return _label(child.child);
  if (child is Container) return _label(child.child);
  if (child is Center) return _label(child.child);
  return '';
}

/// **لا عنصرَ يُضغَط فيُحرّك مالاً** — يُفحص في كلّ حالةٍ تعرضها الشاشة.
void _expectNoMoneyAffordance(WidgetTester t) {
  final tappables = <String>[
    ...t.widgetList<ElevatedButton>(find.byType(ElevatedButton))
        .map((b) => _label(b.child)),
    ...t.widgetList<TextButton>(find.byType(TextButton))
        .map((b) => _label(b.child)),
    ...t.widgetList<OutlinedButton>(find.byType(OutlinedButton))
        .map((b) => _label(b.child)),
    ...t.widgetList<InkWell>(find.byType(InkWell))
        .where((w) => w.onTap != null)
        .map((w) => _label(w.child)),
    ...t.widgetList<GestureDetector>(find.byType(GestureDetector))
        .where((w) => w.onTap != null)
        .map((w) => _label(w.child)),
  ];

  for (final forbidden in const ['إرسال', 'تحويل', 'سحب', 'الرصيد', 'كشف']) {
    expect(tappables.any((l) => l.contains(forbidden)), isFalse,
        reason: '**عنصرٌ يُضغَط عليه «$forbidden» على سطحٍ يُفتح بلا '
            'مصادقة** — ومن حمل الجهازَ يفعلها. وهذه الشاشةُ '
            'للاستلام وحدَه.');
  }
}
