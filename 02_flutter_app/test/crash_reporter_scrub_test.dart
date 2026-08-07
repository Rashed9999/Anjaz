import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/helper/amial_crash_reporter.dart';

/// AMIAL-CRASH-002 — ما يُرفع إلى خوادم Google، وما لا يجوز أن يغادر الجهاز.
///
/// **لماذا هذا الملفّ موجود:**
/// تقارير الأعطال تُرفع خارج الجهاز. ورسائل الاستثناءات ليست بريئة في تطبيق
/// مالي: ردّ الخادم قد يحمل رقم الهاتف، ومسار الطلب قد يحمل رقم الحساب،
/// وترويسة المصادقة تحمل الرمز كاملاً. تسريبٌ هنا لا يُلاحَظ أبداً — لا شاشة
/// تعرضه ولا مستخدم يشتكي منه — ويبقى مخزّناً عند طرف ثالث إلى الأبد.
///
/// ولأنّ التنقية تعمل على ما لا يراه أحد، فالاختبار هو الرقيب الوحيد عليها.
///
/// والوجه الآخر مُختبَر أيضاً: تنقية تبتلع كل شيء تجعل التقرير عديم الفائدة.
/// فما يُشخَّص به العطل — النوع، والملفّ، ورقم السطر، ورمز HTTP — يجب أن يبقى.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('لا يغادر الجهاز', () {
    test('رقم الهاتف اليمني بكل صوره', () {
      for (final raw in const [
        'فشل التحويل إلى 771234567',
        'المستلم +967771234567 غير موجود',
        'رقم 967 77 1234567 مرفوض',
        'to=738901234&amount=500',
      ]) {
        final out = AmialCrashReporter.scrub(raw);
        expect(RegExp(r'7[0-8]\d{7}').hasMatch(out.replaceAll(RegExp(r'[\s\-]'), '')),
            isFalse,
            reason: 'نجا رقم هاتف من: $raw  ←  $out');
      }
    });

    test('رمز المصادقة — الترويسة كاملة وسلسلة JWT', () {
      final out = AmialCrashReporter.scrub(
        'DioError 401 {Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc}',
      );

      expect(out, isNot(contains('eyJhbGci')));
      // من يملك الرمز يتصرّف بحساب صاحبه كاملاً — لا يجوز أن ينجو منه جزء.
      expect(out, isNot(contains('abc')));
      expect(out, contains('401'), reason: 'رمز HTTP يُشخَّص به العطل فيبقى');
    });

    test('رقم الحساب ورقم الإيصال ورمز التحقّق', () {
      final out = AmialCrashReporter.scrub(
        'الإيصال 260726123456 برمز 12345678 للحساب 900112233',
      );

      expect(RegExp(r'\d{6,}').hasMatch(out), isFalse, reason: 'نجا رقم طويل: $out');
    });

    test('الرمز السري لا يُرفع ولو ورد في رسالة خطأ', () {
      for (final raw in const [
        'invalid pin 4821',
        'كلمة المرور 9137 خاطئة',
        'otp: 123456',
      ]) {
        final out = AmialCrashReporter.scrub(raw);
        expect(RegExp(r'\d{4}').hasMatch(out), isFalse,
            reason: 'نجا رمز من: $raw  ←  $out');
      }
    });

    test('البريد الإلكتروني', () {
      expect(AmialCrashReporter.scrub('user: ahmed.ali@example.com'),
          isNot(contains('@example.com')));
    });
  });

  group('يبقى ما يُشخَّص به العطل', () {
    test('نوع الاستثناء ونصّه غير الحسّاس', () {
      final out = AmialCrashReporter.scrub(
        'RangeError (index): Invalid value: Not in inclusive range 0..3: 7',
      );

      expect(out, contains('RangeError'));
      expect(out, contains('Invalid value'));
      // أرقام قصيرة: حدود مصفوفة ورموز حالة وأرقام أسطر. حجبها يُفقد التقرير
      // معناه ولا يحمي شيئاً — لا حساب في العالم رقمه 7.
      expect(out, contains('0..3'));
    });

    test('مسار الملفّ ورقم السطر', () {
      final out = AmialCrashReporter.scrub(
        'lib/features/transaction_money/screens/amial_send_money_screen.dart:418:45',
      );

      expect(out, contains('amial_send_money_screen.dart'));
      expect(out, contains('418'), reason: 'بلا رقم السطر يصير الأثر بلا قيمة');
    });

    test('عنوان الخادم يبقى — العطل قد يكون في مسار بعينه', () {
      final out = AmialCrashReporter.scrub('POST /api/v1/amial/transfers → 500');

      expect(out, contains('/api/v1/amial/transfers'));
      expect(out, contains('500'));
    });
  });

  group('لا تنهار التنقية نفسها', () {
    test('نصّ فارغ وبلا أرقام', () {
      expect(AmialCrashReporter.scrub(''), '');
      expect(AmialCrashReporter.scrub('انقطع الاتصال'), 'انقطع الاتصال');
    });

    test('نصّ يجمع كل الأصناف معاً', () {
      // الترتيب بين القواعد يهمّ: لو التهمت قاعدةُ الأرقام الطويلة رقمَ
      // الهاتف أوّلاً لضاع تمييزه، ولو مزّقت قاعدةُ الهاتف سلسلةَ JWT لنجا
      // نصفها. هذا النصّ يجعل التداخل ينكشف.
      final out = AmialCrashReporter.scrub(
        'حوالة من 771234567 إلى 738901234 بمبلغ 150000 إيصال 260726123456 '
        'Bearer eyJhbGciOiJIUzI1NiJ9.xyz بريد a@b.co رمز pin 4821',
      );

      expect(out, isNot(contains('771234567')));
      expect(out, isNot(contains('738901234')));
      expect(out, isNot(contains('260726123456')));
      expect(out, isNot(contains('eyJhbGci')));
      expect(out, isNot(contains('a@b.co')));
      expect(out, isNot(contains('4821')));
      expect(out, contains('حوالة من'), reason: 'ضاع سياق العطل كلّه');
    });
  });

  group('نصّ العطل في اللوحة', () {
    test('لا يتكرّر اسم النوع', () {
      // ظهر فعلاً على اللوحة: «_Exception: Exception: …». الاستثناء يذكر
      // اسمه في نصّه، وإلحاق runtimeType دائماً يكرّره في كل تقرير.
      final out = AmialCrashReporter.describe(Exception('فشل التحويل'));

      expect(out, 'Exception: فشل التحويل');
      expect('Exception'.allMatches(out).length, 1);
    });

    test('النوع يُضاف حين لا يذكره النصّ — فعليه تُصنَّف القضايا', () {
      expect(AmialCrashReporter.describe(const FormatException('نصّ تالف')),
          startsWith('FormatException'));
    });

    test('نصّ الخطأ يُنقّى هنا أيضاً — لا يكفي التنقية في مكان واحد', () {
      final out = AmialCrashReporter.describe(
          Exception('تعذّر التحويل إلى 771234567'));

      expect(out, isNot(contains('771234567')));
      expect(out, contains('تعذّر التحويل'));
    });
  });

  group('بقاء الهوية بين الإقلاعات', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('الهوية تُحفظ عند الدخول', () async {
      // العطل الذي يحرسه: كان الربط في مسار الدخول وحده، ومن يفتح التطبيق
      // بجلسة محفوظة لا يمرّ به — أي كل مستخدم عائد. فكانت أعطالهم تصل بلا
      // هوية ولا شيء في اللوحة يشي بأن حقلاً ناقص.
      await AmialCrashReporter.identify(
          account: '412', role: 'customer', zone: 'SOUTH');

      final stored = await AmialCrashReporter.storedIdentity();
      expect(stored['account'], '412');
      expect(stored['role'], 'customer');
      expect(stored['zone'], 'SOUTH');
    });

    test('تُحفظ حتى والتقارير معطّلة — التعطيل حالة تتبدّل', () async {
      expect(AmialCrashReporter.isActive, isFalse);

      await AmialCrashReporter.identify(account: '99', role: 'agent');

      expect((await AmialCrashReporter.storedIdentity())['account'], '99',
          reason: 'أوّل إقلاع تُفعَّل فيه التقارير يجب أن يجد الهوية جاهزة');
    });

    test('الخروج يمحوها', () async {
      // الجهاز الواحد يتناوب عليه صرّافان. إبقاء هوية السابق يقود التحقيق
      // إلى الشخص الخطأ — وهو أسوأ من لا هوية.
      await AmialCrashReporter.identify(
          account: '412', role: 'customer', zone: 'SOUTH');
      await AmialCrashReporter.forgetIdentity();

      final stored = await AmialCrashReporter.storedIdentity();
      expect(stored['account'], isNull);
      expect(stored['role'], isNull);
      expect(stored['zone'], isNull);
    });

    test('القيم الفارغة لا تمحو ما هو محفوظ', () async {
      // استجابة دخول ناقصة يجب ألّا تُفقدنا هوية صحيحة سابقة.
      await AmialCrashReporter.identify(
          account: '412', role: 'customer', zone: 'SOUTH');
      await AmialCrashReporter.identify(account: '412', role: '', zone: null);

      final stored = await AmialCrashReporter.storedIdentity();
      expect(stored['role'], 'customer');
      expect(stored['zone'], 'SOUTH');
    });
  });

  group('التفعيل', () {
    test('لا يُبلَّغ قبل التهيئة — ولا يرمي', () async {
      // الودجات تُبنى في الاختبارات وفي أدوات التصميم بلا Firebase. استدعاء
      // مُبلِّغ غير مهيَّأ يجب أن يكون بلا أثر، لا أن يُسقط ما يستدعيه.
      expect(AmialCrashReporter.isActive, isFalse);

      await AmialCrashReporter.record(Exception('اختبار'), StackTrace.current);
      AmialCrashReporter.breadcrumb('شاشة ما');
      await AmialCrashReporter.identify(account: 'AM-1001', role: 'customer');
    });
  });
}
