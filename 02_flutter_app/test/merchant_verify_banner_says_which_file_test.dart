// AMIAL-MERCHANT-VERIFY-BANNER-002 — **«مع أنّ الحساب موثّق».**
//
// ══════════════════════════════════════════════════════════════════════
// أرسل صاحبُ المشروع صورةَ اللافتة وقال ذلك. **وكلامُه صحيحٌ ونصُّ اللافتة
// كان صحيحاً أيضاً** — والعطلُ في أنّهما يتكلّمان عن ملفّين:
//
//   · `users.is_kyc_verified`                  → **توثيقُ الشخص**
//   · `merchant_profiles.verification_status`  → **اعتمادُ المتجر**
//
// واللافتةُ تقرأ الثاني وتقول «**حسابك** قيد الاعتماد». فمن اعتُمد حسابُه
// أمس يقرؤها كذباً ويتوقّف عن البحث.
//
// **وثلاثُ حالاتٍ كانت تُقال بجملةٍ واحدة**: «لم يُرسَل» و«قيد المراجعة»
// و«طُلبت مستنداتٌ إضافيّة». والأولى والثالثةُ فيهما عملٌ على صاحبها،
// والثانيةُ انتظارٌ محض — **و«قيد الاعتماد» لملفٍّ لم يُرسَل انتظارٌ لا
// ينتهي لأنّ لا أحدَ ينتظره**. (القاعدة السابعة: الغيابُ يُقال، ولا يُخلط.)

import 'dart:io';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const shell = 'lib/features/merchant/screens/merchant_adaptive_shell.dart';

  late String code;

  setUpAll(() {
    final f = File(shell);
    expect(f.existsSync(), isTrue);
    code = f
        .readAsStringSync()
        .split('\n')
        .where((l) => !l.trimLeft().startsWith('//') && !l.trimLeft().startsWith('///'))
        .join('\n');
  });

  test('لا تقول اللافتةُ «حسابك» عن ملفِّ المتجر', () {
    expect(code.contains('حسابك قيد الاعتماد'), isFalse,
        reason: 'ما زالت تقول «حسابك» عن اعتماد **المتجر** — فمن وُثّقت '
            'هويّتُه يقرؤها كذباً ويتوقّف عن البحث');
  });

  test('ولكلِّ حالةٍ نصُّها — لا جملةٌ واحدةٌ لثلاث', () {
    expect(code.contains("'pending_review' =>"), isTrue,
        reason: '«قيد المراجعة» لا تُميَّز');
    expect(code.contains("'resubmission_required' =>"), isTrue,
        reason: '«طُلبت مستنداتٌ إضافيّة» تُقال «قيد الاعتماد» — فينتظر '
            'صاحبُها ما لن يأتي، والعملُ عليه هو');
    expect(code.contains('لم تُرسِل ملفَّ اعتماد المتجر بعد'), isTrue,
        reason: 'ملفٌّ لم يُرسَل يُقال «قيد الاعتماد» — انتظارٌ لا ينتهي '
            'لأنّ لا أحدَ ينتظره');
  });

  test('ومن عليه عملٌ يجد بابَه في اللافتة نفسِها', () {
    // `MerchantVerificationScreen` مبنيّةٌ ولها مدخلٌ واحدٌ: لوحةُ التاجر
    // العامّة — **وصاحبُ محطّة الوقود لا يراها**، فشاشتُه لوحةُ المحطّة.
    expect(code.contains("Key('merchant-verify-banner-action')"), isTrue,
        reason: 'لا زرَّ في اللافتة — فمن قرأها لم يجد ما يفعل');
    expect(code.contains('MerchantVerificationScreen'), isTrue,
        reason: 'الزرُّ لا يقود إلى شاشة الملفّ');
    expect(
        code.contains("import 'package:amial_pay/features/merchant_verification/"
            "screens/merchant_verification_screen.dart'"),
        isTrue,
        reason: 'ذُكرت الشاشةُ بلا استيراد — فلا يُصرَّف أصلاً');
  });

  test('والمنتظِرُ لا يُعطى زرّاً لا يُغيّر شيئاً', () {
    // زرٌّ لا يفعل شيئاً يُضغط مرّةً ثمّ يُهمَل هو وما بعده.
    final pending = code.indexOf("'pending_review' =>");
    expect(pending, greaterThan(-1));
    final segment = code.substring(pending, pending + 400);
    expect(segment.contains('false,'), isTrue,
        reason: 'أُعطي المنتظِرُ زرَّ إرسالٍ وملفُّه مُرسَلٌ سلفاً');
  });
}
