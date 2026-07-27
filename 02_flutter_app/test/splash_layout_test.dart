import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// AMIAL-SPLASH-006 — شاشة البدء تملأ العرض ولا تنزوي إلى حافّة.
///
/// **العطل الذي بقي ثلاث جولات، ولماذا لم يُمسك:**
/// `Scaffold` يُخطّط جسمه بقيود **فضفاضة** ثم يضعه عند x=0. والعمود ينكمش
/// إلى عرض أعرض أبنائه. فلمّا صار أعرضهم هو الشعار المحدود بـ62% من الشاشة،
/// انكمش العمود كلّه إلى 62% وانزوى يساراً — ومعه النصّان والمؤشّر.
///
/// وكل إصلاح سابق أخطأ الطبقة: مقاسُ الشعار فُحص فوُجد سليماً، وشاشةُ إقلاع
/// النظام فُحصت فوُجدت سليمة. الجاني هو **الحاوي**، ولم يُنظر إليه.
///
/// ولم يمسكه شيء لأن كل ما بُني من فحوص كان يسأل «هل العنصر موجود؟» و«هل
/// مقاسه صحيح؟» — وكلا الجوابين نعم. السؤال الغائب: **أين يقع على الشاشة؟**
///
/// ولذلك يقيس هذا الملفّ المواضع بالبكسل. وقد أُخذ الرقم المرجعي من قياس
/// تسجيل شاشة حقيقي: كل عنصر مزاح -142 بكسل من أصل عرض 720، ثابتاً طوال
/// عمر الشاشة — وثباتُه هو ما ميّزه عن حركة انتقال.
void main() {
  /// نُعيد بناء شجرة الشاشة كما هي في splash_screen.dart.
  ///
  /// الشاشة نفسها لا تُبنى في اختبار: `initState` يفتح مستمع اتصال ويطلب
  /// إعدادات من الخادم ويوجّه بعدها. فيُفحص التخطيط وحده — وهو موضع العطل.
  Widget splashBody({required bool withFix}) {
    Widget column = Builder(builder: (context) {
      return Column(
        children: [
          const Spacer(flex: 3),
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ConstrainedBox(
                constraints: BoxConstraints(
                  maxWidth: MediaQuery.sizeOf(context).width * 0.62,
                  maxHeight: 150,
                ),
                child: Container(
                    key: const Key('logo'), width: 549, height: 378, color: Colors.white),
              ),
              const SizedBox(height: 18),
              const Text('أميال باي', key: Key('name')),
              const SizedBox(height: 6),
              const Text('دفع سريع وآمن', key: Key('tagline')),
            ],
          ),
          const Spacer(flex: 2),
          const SizedBox(width: 22, height: 22, key: Key('spinner')),
          const SizedBox(height: 46),
        ],
      );
    });

    return MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          backgroundColor: const Color(0xFFFECA1E),
          body: SafeArea(
            child: withFix ? SizedBox.expand(child: column) : column,
          ),
        ),
      ),
    );
  }

  /// مقاس جهاز المستخدم الذي صُوّر عليه العطل.
  Future<void> onDevice(WidgetTester tester, Widget app) async {
    tester.view.physicalSize = const Size(720, 1612);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(app);
  }

  double centerX(WidgetTester t, String key) =>
      t.getCenter(find.byKey(Key(key))).dx;

  group('كل عنصر في منتصف الشاشة', () {
    testWidgets('الشعار والاسم والوصف والمؤشّر', (tester) async {
      await onDevice(tester, splashBody(withFix: true));

      const middle = 180.0; // 720 بكسل ÷ كثافة 2
      for (final k in ['logo', 'name', 'tagline', 'spinner']) {
        expect(centerX(tester, k), moreOrLessEquals(middle, epsilon: 1.0),
            reason: '«$k» ليس في المنتصف — الشاشة تبدو منحرفة');
      }
    });

    testWidgets('لا يتغيّر بتغيّر عرض الجهاز', (tester) async {
      // العطل كان نسبةً من العرض (62%)، فيتبدّل أثره بتبدّل الجهاز ويصعب
      // تصديقه: «عندي سليم وعندك منحرف».
      for (final w in [480.0, 720.0, 1080.0, 1440.0]) {
        tester.view.physicalSize = Size(w, 1612);
        tester.view.devicePixelRatio = 2.0;
        addTearDown(tester.view.reset);
        await tester.pumpWidget(splashBody(withFix: true));

        expect(centerX(tester, 'name'), moreOrLessEquals(w / 4, epsilon: 1.0),
            reason: 'انحراف عند عرض $w');
      }
    });
  });

  testWidgets('بلا الإصلاح يعود العطل — الحارس يحرس فعلاً', (tester) async {
    // اختبارٌ لا يسقط عند إعادة العطل ليس حارساً. هذه الحالة تُثبت أن
    // القياس أعلاه يلتقط ما وصل المستخدم بالفعل.
    await onDevice(tester, splashBody(withFix: false));

    expect(centerX(tester, 'name'), lessThan(150.0),
        reason: 'كان يجب أن ينزوي يساراً بلا SizedBox.expand');
  });

  testWidgets('العمود يملأ العرض كلّه لا 62% منه', (tester) async {
    await onDevice(tester, splashBody(withFix: true));

    final column = tester.renderObject<RenderBox>(
        find.descendant(of: find.byType(SafeArea), matching: find.byType(Column)).first);

    expect(column.size.width, 360.0,
        reason: 'العمود انكمش إلى عرض أعرض أبنائه — وهذا أصل الانحراف');
  });
}
