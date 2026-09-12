// AMIAL-QUICK-RECEIVE-002 — **بطاقةٌ في شاشة الدخول لا تعمل، وشكواها وصلت.**
//
// ══════════════════════════════════════════════════════════════════════
// **الشكوى حرفيّاً**: «الدفع السريع في شاشة التسجيل دون الدخول للتطبيق
// لا يعمل».
//
// **والسببُ المقيس**: `rememberLastUser` كانت تُنادى من `_submit` في
// شاشة الدخول وحدَها. والدخولُ بالبصمة ينجح ويذهب إلى الرئيسيّة بلا أن
// يتذكّر — فمن يدخل ببصمته لا يُكتب له «آخرُ مستخدم» أبداً، وتبقى
// البطاقةُ ميّتةً في كلّ مرّةٍ يفتح فيها الشاشة.
//
// وهي **القاعدةُ الرابعة بنصّها**: «ميزةٌ لها مدخلان تُختبَر من
// مدخليها … جرّبتُ المسار السليم، والمستعمل يسلك المسار الآخر.»
//
// ══════════════════════════════════════════════════════════════════════
// **ولماذا اختبارٌ في دارت أصلاً:** الخادمُ لا يرى هذا. ساهر لا يقرأ
// Dart، و`flutter analyze` لا يرى «دالّةٌ لم تُنادَ من هذا المسار»،
// و٣٩٦ ملفَّ اختبارٍ في الخادم لا يلمس منها ملفٌّ واحدٌ شاشةَ دخول.
// فهذه الطبقةُ هي التي كانت غائبةً حين وصلت الشكوى.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    Get.put<SharedPreferences>(await SharedPreferences.getInstance());
  });

  tearDown(Get.reset);

  // ══════════════════════════════════════════════════════════════════
  //  ① العقد: ما لم يُكتب لا يُقرأ — والبطاقةُ تقرأ هذا بعينه
  // ══════════════════════════════════════════════════════════════════

  test('بلا دخولٍ سابق لا يوجد مستخدمٌ أخير — والبطاقةُ تقول لماذا', () {
    expect(UnifiedAuthController.readLastUser(), isNull,
        reason: 'قُرئ مستخدمٌ أخيرٌ على جهازٍ لم يدخل منه أحد');
  });

  test('ما يُحفَظ يُقرأ كما هو — الاسمُ والهاتفُ والنوع', () async {
    await UnifiedAuthController.rememberLastUser(
      name: 'راشد', phone: '967771234567', kind: 'customer');

    final last = UnifiedAuthController.readLastUser();

    expect(last, isNotNull);
    expect(last!.name, 'راشد');
    expect(last.phone, '967771234567');

    // **والنوعُ هو ما تقارنه البطاقةُ** — `_lastUser?.kind == 'customer'`.
    // فلو كُتب «عميل» أو `customer_user` لبقيت البطاقةُ ميّتةً والقيمةُ
    // محفوظة: عطلٌ لا يظهر في أيّ سجلّ.
    expect(last.kind, 'customer',
        reason: 'النوعُ المحفوظُ لا يطابق ما تقارنه البطاقة — فتبقى ميّتةً وهي مملوءة');
  });

  test('«لست أنت؟» يمسح فعلاً ولا يترك أثراً', () async {
    await UnifiedAuthController.rememberLastUser(
      name: 'راشد', phone: '967771234567', kind: 'customer');
    expect(UnifiedAuthController.readLastUser(), isNotNull);

    await UnifiedAuthController.forgetLastUser();

    expect(UnifiedAuthController.readLastUser(), isNull,
        reason: 'زرُّ «لست أنت؟» لا يمسح — فيبقى هاتفُ من سبقك معروضاً على جهازك');
  });

  // ══════════════════════════════════════════════════════════════════
  //  ② العطلُ نفسُه: التذكُّرُ في المخرج الواحد لا في شاشةٍ واحدة
  // ══════════════════════════════════════════════════════════════════

  test('**كلُّ دخولٍ ناجحٍ يتذكّر — لا مدخلٌ واحدٌ منها**', () {
    final controller = File(
      'lib/features/auth/controllers/unified_auth_controller.dart',
    ).readAsStringSync();

    // المخرجُ الواحدُ لكلّ دخولٍ ناجحٍ لكلّ دور.
    final execute = _stripComments(controller.substring(
      controller.indexOf('Future<bool> _execute('),
      controller.indexOf('Future<void> _saveAuth('),
    ));

    // ══════════════════════════════════════════════════════════════
    // **وتُنزَع التعليقاتُ قبل البحث — وإلّا حرس الحارسُ نفسَه.**
    //
    // جُرّب هذا بالعكس فمرّ والعطلُ مُعاد: التعليقُ العربيُّ الذي يشرح
    // التذكُّرَ يحوي كلمةَ `rememberLastUser`، فوجدها الحارسُ في نصٍّ
    // **يصف** الميزةَ لا في نداءٍ يُنفّذها.
    //
    // وهو بنصّه ما وقع في هذا المشروع من قبل: «حارسٌ مرّ لأنّ الكلمة
    // وردت في تعليقٍ عربيٍّ يشرح أنّ النقطة غير موصولة» — أي أنّ
    // التعليقَ الذي يصف العطل كان يُخفيه.
    // ══════════════════════════════════════════════════════════════
    expect(execute.contains('rememberLastUser('), isTrue,
        reason: '**التذكُّرُ ليس في المخرج الواحد** — فكلُّ شاشةِ دخولٍ تتذكّر '
        'بنفسها أو تنسى، والدخولُ بالبصمة نسي فعلاً. ومدخلٌ ثالثٌ '
        'يُضاف غداً يرث النسيان.');

    // **ولا كاتبَ ثانٍ**: نسختان لحقيقةٍ واحدةٍ تفترقان أوّلَ ما يتغيّر
    // أحدُهما — وقد كانت الشاشةُ تكتب `_kind.name` والخادمُ يردّ غيرَه.
    final screen = File(
      'lib/features/auth/screens/unified_login_screen.dart',
    ).readAsStringSync();

    expect(_stripComments(screen).contains('rememberLastUser('), isFalse,
        reason: 'شاشةُ الدخول تكتب «آخرَ مستخدم» ثانيةً — وكاتبان لحقيقةٍ واحدة '
        'يفترقان، فتُكتب قيمةٌ لا تطابق ما تقارنه البطاقة');
  });

}

/// يُزيل تعليقاتِ السطر والكتلة — فالبحثُ يجب أن يقع على شيفرةٍ تُنفَّذ
/// لا على نصٍّ يصفها. (وبلا هذا يمرّ الحارسُ على عطلٍ قائم.)
String _stripComments(String src) => src
    .replaceAll(RegExp(r'/\*.*?\*/', dotAll: true), '')
    .split('\n')
    .map((l) {
      final i = l.indexOf('//');
      return i < 0 ? l : l.substring(0, i);
    })
    .join('\n');
