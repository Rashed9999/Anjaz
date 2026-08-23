import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/features/auth/domain/quick_receive_preferences.dart';

/// AMIAL-QUICK-RECEIVE-003 — **حارسُ الحساب القديم كان مبنيّاً ولا يُنادى.**
///
/// ══════════════════════════════════════════════════════════════════════
/// «الاستلامُ السريع» يعرض رمزَ QR **قبل تسجيل الدخول** يحمل عنوانَ استقبالٍ
/// عامّاً واسمَ عرض. وهو تصميمٌ سليم: لا كلمةَ مرورٍ ولا رمزَ جلسة، والهاتفُ
/// لا يُرمَّز في الـQR إطلاقاً.
///
/// **والخطرُ الوحيدُ فيه أن يبقى بعد أن يتبدّل صاحبُ الجهاز.** فمن يمسح
/// الرمزَ يُرسل مالاً إلى **حساب المالك السابق** وهو يظنّه الحاضر.
///
/// ولذلك كُتبت `disableIfOwnedByAnother` في التزامٍ عنوانُه
/// «prevent stale-account quick receive exposure».
///
/// **وقِيس أنّ لا مُنادِيَ لها في التطبيق كلِّه** — مسحاً بالرمز:
///
///     enable · disable · isEnabled · read   →  تُنادى
///     disableIfOwnedByAnother               →  **صفرُ نداء**
///
/// فالحمايةُ المُعلَنةُ في نصّ الالتزام لم تكن تقع. وهو نمطُ العطل الأكثرُ
/// تكراراً في المشروع — **مبنيٌّ ولا يُوصَل إليه** — واقعاً هذه المرّةَ على
/// التزامٍ يَعِد بالأمان.
///
/// ══════════════════════════════════════════════════════════════════════
/// **وعطلان ظهرا عند توصيلها، ولولا التوصيلُ ما ظهرا:**
///
///   ١) المقارنةُ كانت حرفيّة. والمخزَّنُ يأتي من الملفّ الشخصيّ
///      (`967777100001`)، والداخلُ رمزَ اتّصالٍ + رقماً (`+967` و
///      `777100001`). **فكانت ستُطفئ الميزةَ على صاحبها في كلّ دخول** —
///      وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.
///
///   ٢) كانت تخرج صامتةً إن كان المالكُ المخزَّن فارغاً، و`enable()` تقبل
///      هاتفاً فارغاً. **ففشلٌ مفتوحٌ في قلب الدالّة التي تُسمّى «fail
///      closed»**: تفضيلٌ لا يُنظَّف أبداً مهما تبدّل صاحبُ الجهاز.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    Get.reset();
    Get.put<SharedPreferences>(await SharedPreferences.getInstance());
  });

  Future<void> enableFor(String phone) async {
    final ok = await QuickReceivePreferences.enable(
      displayName: 'أحمد م.',
      receiveAddress: 'AMB-100200',
      ownerPhone: phone,
    );
    expect(ok, isTrue, reason: 'تعذّر التفعيل — والحالةُ الابتدائيّة شرطُ الاختبار');
  }

  group('الحارسُ ضدّ الحساب القديم', () {
    test('حسابٌ آخر يدخل الجهاز فيُطفأ العنوانُ القديم', () async {
      // **هذا هو العطلُ بعينه.** بلا هذا يبقى رمزُ المالك السابق معروضاً
      // قبل الدخول، فيُرسَل إليه مالُ من يمسحه.
      await enableFor('967777100001');

      await QuickReceivePreferences.disableIfOwnedByAnother('+967777200002');

      expect(QuickReceivePreferences.isEnabled, isFalse,
          reason: 'بقي عنوانُ الحساب السابق مفعّلاً بعد دخول حسابٍ آخر');
      expect(QuickReceivePreferences.read(), isNull);
    });

    test('صاحبُه يدخل بصيغةٍ أخرى للرقم فلا يُطفأ', () async {
      // ══════════════════════════════════════════════════════════════
      // **والرقمُ الواحد يصل بأربع صيغ.** لو قُورن حرفيّاً لأُطفئت
      // الميزةُ على صاحبها في كلّ دخول — وهو عطلٌ مسجَّلٌ في المشروع
      // مرّتين: «مقارنةٌ حرفيّةٌ تجعل الحساب يعمل من شاشةٍ ويُرفض من أخرى».
      // ══════════════════════════════════════════════════════════════
      await enableFor('967777100001');

      for (final form in ['+967777100001', '00967777100001', '777100001', '967777100001']) {
        await QuickReceivePreferences.disableIfOwnedByAnother(form);

        expect(QuickReceivePreferences.isEnabled, isTrue,
            reason: 'أُطفئت الميزةُ على صاحبها حين دخل بالصيغة «$form»');
      }
    });

    test('رقمٌ داخلٌ لا يُقرأ منه شيء يُطفئ ولا يُترك', () async {
      // **و«غير معروف» ليس «هو نفسُه».** إن تعذّر التعرّف على صاحب
      // الجهاز فالعرضُ يتوقّف — لا يُفترض أنّه المالك.
      await enableFor('967777100001');

      await QuickReceivePreferences.disableIfOwnedByAnother('   ');

      expect(QuickReceivePreferences.isEnabled, isFalse,
          reason: 'بقي مفعّلاً ولم يُعرَف صاحبُ الجهاز');
    });
  });

  group('الكتابةُ تُشترط فلا تُقرأ فارغة', () {
    test('تفعيلٌ بلا هاتفِ مالكٍ يُرفض', () async {
      // **وإلّا وُلد تفضيلٌ لا يُنظَّف أبداً**: الحارسُ يخرج صامتاً على
      // مالكٍ فارغ، فيبقى العنوانُ معروضاً لكلّ من يملك الجهاز بعده.
      final ok = await QuickReceivePreferences.enable(
        displayName: 'أحمد م.',
        receiveAddress: 'AMB-100200',
        ownerPhone: '',
      );

      expect(ok, isFalse, reason: 'قُبل تفعيلٌ بلا مالك — فالحارسُ لا يعمل عليه');
      expect(QuickReceivePreferences.isEnabled, isFalse);
    });

    test('تفعيلٌ بلا عنوانِ استقبالٍ يُرفض', () async {
      // **ولا يُستعمل الهاتفُ بديلاً.** الشاشةُ تُفتح قبل الدخول، ورمزٌ
      // يحمل رقمَ الهاتف يُفشيه لكلّ من يمسحه.
      final ok = await QuickReceivePreferences.enable(
        displayName: 'أحمد م.',
        receiveAddress: '   ',
        ownerPhone: '967777100001',
      );

      expect(ok, isFalse);
      expect(QuickReceivePreferences.isEnabled, isFalse);
    });
  });

  group('الفشلُ مغلق', () {
    test('بلا تخزينٍ محلّيٍّ لا يُعرَض شيء', () async {
      // **وغيابُ التخزين لا يُقرأ «مفعّل»**: لا انهيارَ ولا عرضَ عنوانٍ
      // قديم — بل صمتٌ آمن.
      Get.reset();

      expect(QuickReceivePreferences.isEnabled, isFalse);
      expect(QuickReceivePreferences.read(), isNull);
    });
  });

  group('التوصيل', () {
    test('الحارسُ يُنادى من مسار الدخول', () async {
      // ══════════════════════════════════════════════════════════════
      // **ودالّةٌ صحيحةٌ بلا مُنادٍ ليست حماية.** هذا ما كان: التزامٌ
      // عنوانُه «prevent stale-account exposure» ودالّةٌ بصفر نداء.
      //
      // ويُقاس من الشيفرة بلا تعليقاتها — فالتعليقُ الذي يشرح الحمايةَ
      // كان يُخفي غيابَها في حرّاسَ أُخرى بهذه الجلسة.
      // ══════════════════════════════════════════════════════════════
      final raw = await _read('lib/features/auth/controllers/auth_controller.dart');
      final code = raw
          .replaceAll(RegExp(r'/\*.*?\*/', dotAll: true), '')
          .replaceAll(RegExp(r'^[ \t]*//[^\n]*$', multiLine: true), '');

      expect(code.contains('QuickReceivePreferences.disableIfOwnedByAnother'), isTrue,
          reason: 'مسارُ الدخول لا ينادي الحارس — فجهازٌ تبدّل صاحبُه '
              'يبقى يعرض عنوانَ المالك السابق قبل الدخول');
    });
  });
}

Future<String> _read(String relative) async {
  // من `test/` إلى جذر التطبيق.
  return await File(relative).readAsString();
}
