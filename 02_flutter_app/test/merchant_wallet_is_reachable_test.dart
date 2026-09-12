import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-MERCHANT-WALLET-001 — **محفظةُ المتجر تُفتَح، ولها اسمٌ واحد.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الطلب بنصّه:** «المفترض لديه المحفظة يرى المال الموجود فيه من
/// عمليّات البيع، يستطيع سحبَه تحويلَه فقط».
///
/// وقِيست الشيفرةُ فما كان فيها شاشةُ محفظةٍ لتاجر — **أربعُ قطعٍ
/// متفرّقةٍ ولكلٍّ اسمٌ آخر**:
///
///   الرصيد   بطاقةٌ صمّاءُ في ترويسة اللوحة، لا تُضغط
///   الحركات  شاشةٌ عنوانُها «المبيعات والعمليات»، واسمُها في «خدماتي»
///            «حركات المتجر»، ورابطُها في اللوحة «المبيعات»
///   السحب    رابطٌ في **أسفل** اللوحة، مبنيٌّ ويعمل
///   التحويل  **غيرُ موصولٍ لحساب تاجرٍ إطلاقاً**
///
/// و`AmialSendMoneyScreen` تُفتح من رئيسيّة العميل في ستّة مواضع
/// **ولا موضعَ واحدٍ من أيّ شاشة تاجر** — مبنيّةٌ ولا يُوصَل إليها من هنا.
///
/// ══════════════════════════════════════════════════════════════════════
/// **وهذا الحارسُ يقيس الوصولَ لا الوجود.** (القاعدة الثانية عشرة:
/// المسارُ المسجَّل ليس ظهوراً — لا بدّ من رابطٍ يقود إليه من مكانٍ يمرّ
/// به المستعمل. وأختُها: صفحةٌ لا يُوصل إليها ليست مبنيّة.)
void main() {
  final wallet =
      File('lib/features/merchant/screens/merchant_wallet_screen.dart');

  /// **المداخلُ تُسمّى واحداً واحداً** — لا «أيّ ملفّ في المشروع»:
  /// مدخلٌ واحدٌ يكفي لإرضاء عدّادٍ، وثلاثةٌ هي ما يجعلها موجودةً فعلاً
  /// لتاجرٍ يبدأ من الرئيسيّة أو من الدرج أو من قائمة خدماته.
  const entries = {
    'lib/features/merchant/screens/merchant_dashboard_screen.dart':
        'لوحةُ التاجر — بطاقةُ الرصيد ورابطُ القائمة',
    'lib/features/merchant/screens/merchant_adaptive_shell.dart':
        'الدرجُ — وهو ما يُفتَح من كلّ شاشة',
    'lib/features/access/screens/role_based_home_screens.dart':
        'رئيسيّةُ التجزئة — زرٌّ كبير',
    'lib/features/merchant/screens/merchant_services_hub_screen.dart':
        'خدماتي',
  };

  test('الشاشةُ موجودة', () {
    expect(wallet.existsSync(), isTrue,
        reason: 'لا شاشةَ محفظةٍ لتاجر — والطلبُ كان شاشةً واحدةً تجمع '
            'المال المبعثر');
  });

  test('وفيها البابان المطلوبان: سحبٌ وتحويل', () {
    final src = wallet.readAsStringSync();

    expect(src.contains('WithdrawRequestScreen'), isTrue,
        reason: '**لا سحبَ في المحفظة** — وهو مبنيٌّ ويعمل منذ شهور، '
            'وكان مدفوناً في أسفل اللوحة.');

    expect(src.contains('AmialSendMoneyScreen'), isTrue,
        reason: '**لا تحويلَ في المحفظة.** وهو نصفُ الطلب: «يستطيع سحبَه '
            'تحويلَه». وشاشةُ الإرسال مبنيّةٌ وتُفتح من رئيسيّة العميل '
            'في ستّة مواضع — **ولا موضعَ من شاشة تاجر**.');

    expect(src.contains('MerchantTransactionsScreen'), isTrue,
        reason: 'المحفظةُ بلا بابٍ إلى حركاتها كاملةً');
  });

  test('ولا تكتب صفراً على رصيدٍ محجوب', () {
    final model = File(
        'lib/features/merchant/domain/models/merchant_models.dart')
        .readAsStringSync();

    expect(model.contains('final String? balance'), isTrue,
        reason: '**`balance` غيرُ قابلٍ للعدم.** والخادمُ يحذف '
            '`current_balance` عن موظّف نقطة البيع، فيكتب الافتراضُ '
            '«الرصيد المتاح: 0 ر.ي» على متجرٍ فيه مئتا ألف. '
            '(القاعدة السابعة: «غير معروف» ليس صفراً.)');

    expect(model.contains("balance: raw?.toString()"), isTrue,
        reason: 'الرصيدُ يُقرأ بافتراضٍ بدل أن يُترك عدماً');

    expect(wallet.readAsStringSync().contains('balance != null'), isTrue,
        reason: 'الشاشةُ لا تفرّق بين «رصيدٌ صفر» و«رصيدٌ لا يُعرَض لك»');
  });

  test('ويُوصَل إليها من أربعة مداخل — لا من واحد', () {
    final missing = <String>[];

    entries.forEach((path, where) {
      final f = File(path);
      if (!f.existsSync()) {
        missing.add('  $path (الملفُّ مفقود) — $where');
        return;
      }
      if (!f.readAsStringSync().contains('MerchantWalletScreen')) {
        missing.add('  $where\n      ← $path');
      }
    });

    expect(missing, isEmpty,
        reason: '**مداخلُ لا تقود إلى المحفظة:**\n${missing.join('\n')}\n\n'
            'وشاشةٌ مبنيّةٌ بمدخلٍ واحدٍ يعرفه كاتبُها وحدَه '
            '**ليست مبنيّة**: التاجرُ يبدأ من رئيسيّته أو من درجه أو من '
            'خدماته، ومن لم يمرّ بالباب الوحيد لم يجدها قطّ.');
  });

  test('واسمٌ واحدٌ للمال — لا ثلاثة', () {
    // ══════════════════════════════════════════════════════════════
    // كان: «حركات المتجر» في خدماتي · «المبيعات والعمليات» عنوانَ
    // الشاشة · «المبيعات» في اللوحة — **ثلاثةُ أسماءٍ لشيءٍ واحد**
    // يفتحها التاجرُ من ثلاثة أبواب في الجلسة نفسِها.
    //
    // والمقياسُ ليس منعَ التشابه بل **منعَ الاسم المهجور**: العنوانُ
    // القديمُ إن بقي في أيّ موضعٍ عاد الالتباس.
    // ══════════════════════════════════════════════════════════════
    final txns = File(
        'lib/features/merchant/screens/merchant_transactions_screen.dart')
        .readAsStringSync();

    expect(txns.contains("Text('المبيعات والعمليات')"), isFalse,
        reason: 'عنوانُ شاشة الحركات ما زال «المبيعات والعمليات» — '
            'وهي جزءٌ من المحفظة، فتُسمّى باسمها.');

    final hub = File(
        'lib/features/merchant/screens/merchant_services_hub_screen.dart')
        .readAsStringSync();

    expect(hub.contains("'حركات المتجر'"), isFalse,
        reason: '«حركات المتجر» ما زالت في «خدماتي» — اسمٌ ثالثٌ للمال '
            'نفسِه، ويفتحه التاجرُ من الجلسة نفسِها.');
  });
}
