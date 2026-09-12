import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-PROFILE-ROLE-001 — **قوائمُ «حسابي» تتبع الدور، لا تُعرض للجميع.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **قاله صاحبُ المشروع أمام شاشتَي عميل:** «هذه لحساب عميل… المشكلة أنّ
/// التجّار لديهم نفس هذه القوائم؟؟؟؟ لماذا؟ المفترضُ لديه المحفظة، يرى
/// المال الموجود فيه من عمليّات البيع، يستطيع سحبَه وتحويلَه فقط».
///
/// وقِيس فكان محقّاً، **والخادمُ يقول ما قاله هو بالحرف**:
///
///     roleBase('user')     → … favorite_numbers، payment_requests …
///     roleBase('merchant') → wallet، transfer، receive، receipts …
///                            **ولا favorite_numbers ولا payment_requests**
///
/// و`profile_screen.dart` كان فيه **صفرُ فحصٍ للدور** — ملفٌّ من ٣٥١ سطراً
/// لا يذكر `role` ولا `merchant` ولا `type` مرّةً واحدة. ودرجُ التاجر
/// (`merchant_adaptive_shell.dart`) يفتحه بعينه.
///
/// ══════════════════════════════════════════════════════════════════════
/// **وأصمتُ ما في العطل أنّ الشاشةَ الشقيقةَ كانت تحرس ما لا تحرسه هذه.**
/// `my_services_screen.dart` تفتح «طلبات واردة» و«صادرة» خلف
/// `access.has('payment_requests')` — **الشاشتان نفسُهما، والقدرةُ
/// نفسُها، وحكمان متناقضان**. فالبابُ الواحد مغلقٌ في موضعٍ ومفتوحٌ في
/// موضع، ولا شيءَ يمسك ذلك: كلاهما يُصرَّف، و`flutter analyze` عنهما راضٍ.
///
/// **فالحارسُ يسأل الاتّفاق لا الوجود**: أن يكون كلُّ بابٍ محروسٍ هناك
/// محروساً ها هنا بالقدرة نفسِها.
void main() {
  final profile = File('lib/features/setting/screens/profile_screen.dart');
  final services = File('lib/features/me/screens/my_services_screen.dart');
  final dashboard =
      File('lib/features/merchant/screens/merchant_dashboard_screen.dart');

  /// هل بناءُ هذه الشاشة محروسٌ بهذه القدرة؟
  ///
  /// **ويُقاس موضعُ البناء لا ذكرُ الاسم.** فحصٌ يبحث عن القدرة في الملفّ
  /// كلِّه يمرّ ولو كانت تحرس زرّاً آخر تماماً — وهو نفسُ العطل الذي وقع
  /// في حارس لوحة الوقود حين وجد اسمَ الشاشة في سطر `import`.
  ///
  /// وتُفحَص **كلُّ** مواضع البناء: بابان لشاشةٍ واحدة، أحدُهما محروسٌ
  /// والآخر مكشوف، حالةٌ قائمةٌ في هذا المشروع (القاعدة الرابعة).
  bool everyDoorGuarded(String src, String screen, String capability) {
    final needle = 'const $screen()';
    var from = 0;
    var found = 0;

    while (true) {
      final at = src.indexOf(needle, from);
      if (at < 0) break;
      found++;
      from = at + needle.length;

      final window = src.substring((at - 320).clamp(0, at), at);
      if (!window.contains(capability)) return false;
    }

    return found > 0;
  }

  test('الملفّاتُ في مكانها — وإلّا فحصنا العدم', () {
    for (final f in [profile, services, dashboard]) {
      expect(f.existsSync(), isTrue, reason: 'ملفٌّ مفقود: ${f.path}');
    }
  });

  test('«حسابي» تسأل القدرةَ قبل أن ترسم زرّاً ليس لصاحبه', () {
    final src = profile.readAsStringSync();

    expect(src.contains('AccessController'), isTrue,
        reason: 'شاشةُ «حسابي» لا تعرف الدورَ إطلاقاً — فهي تعرض قوائمَ '
            'العميل لكلّ حساب، وهو ما شُكي منه بالنصّ.');

    final gated = <String, String>{
      'AmialFavoritesScreen': "'favorite_numbers'",
      'IncomingRequestsScreen': "'payment_requests'",
      'OutgoingRequestsScreen': "'payment_requests'",
    };

    final leaks = <String>[];

    gated.forEach((screen, capability) {
      if (!everyDoorGuarded(src, screen, capability)) {
        leaks.add('  $screen ← $capability');
      }
    });

    expect(leaks, isEmpty,
        reason: '**أبوابٌ تُرسم لمن لا يملكها:**\n${leaks.join('\n')}\n\n'
            'والخادمُ لا يمنح التاجرَ `favorite_numbers` ولا '
            '`payment_requests` — فالزرُّ يُعرض ويُضغط ويعمل بما لا يخصّه، '
            'أو يُردّ. وكلاهما عطل.');
  });

  test('والشاشتان الشقيقتان لا تختلفان على البابِ نفسِه', () {
    final profileSrc = profile.readAsStringSync();
    final servicesSrc = services.readAsStringSync();

    // ══════════════════════════════════════════════════════════════
    // **هذا هو الحارسُ الحقيقيّ.** فالعطلُ لم يكن غيابَ فحصٍ في مكانٍ
    // مجهول — بل **وجودَه في إحدى الشاشتين وغيابَه في أختها**، على
    // الشاشة نفسِها بالقدرة نفسِها. ولا يُرى ذلك بقراءة ملفٍّ واحد.
    // ══════════════════════════════════════════════════════════════
    for (final screen in ['IncomingRequestsScreen', 'OutgoingRequestsScreen']) {
      final inServices =
          everyDoorGuarded(servicesSrc, screen, "'payment_requests'");
      final inProfile =
          everyDoorGuarded(profileSrc, screen, "'payment_requests'");

      expect(inProfile, inServices,
          reason: '**«$screen» محروسةٌ في شاشةٍ ومكشوفةٌ في أختها** — '
              'شاشةُ الخدمات: $inServices · شاشةُ حسابي: $inProfile. '
              'فيرى صاحبُ الحساب البابَ مغلقاً في موضعٍ ومفتوحاً في موضع، '
              'ولا يقول ذلك مُصرِّفٌ ولا محلِّل.');
    }
  });

  test('ولوحةُ التاجر لا تقول «قريباً» عمّا هو مبنيٌّ ويعمل', () {
    final src = dashboard.readAsStringSync();

    // ══════════════════════════════════════════════════════════════
    // ثلاثةُ أبوابٍ كانت تقول «قريباً»، **واثنان منها مبنيّان منذ
    // شهور** ويُفتحان من شاشة الخدمات. والثالث «سحب للبنك» — ولا سحبَ
    // بنكيَّ في المنصّة، **والسحبُ عبر الوكيل يعمل**. فالتاجرُ يقرأ
    // «قريباً» فيظنّ أنّه لا يستطيع إخراجَ مال بيعه، وهو يستطيع.
    //
    // **و«قريباً» عن مبنيٍّ أسوأ من غياب الزرّ**: الغيابُ يُسأل عنه،
    // و«قريباً» تُصدَّق فيُكفّ عن السؤال — ولا خطأَ في أيّ سجلّ.
    // ══════════════════════════════════════════════════════════════
    expect(src.contains('_comingSoon'), isFalse,
        reason: 'عادت «قريباً» إلى لوحة التاجر — وقد كانت على ثلاثة '
            'أبوابٍ اثنان منها مبنيّان ويعملان.');

    for (final screen in [
      'MerchantStaffScreen',
      'MerchantServicesHubScreen',
    ]) {
      expect(src.contains('const $screen()'), isTrue,
          reason: 'سقط بابُ «$screen» من لوحة التاجر — والتاجرُ يبدأ '
              'يومَه من لوحته، فما لا يُوصل إليه منها غيرُ موجودٍ عنده.');
    }
  });

  test('وسحبُ المال يبقى مبلوغاً من اللوحة — مباشرةً أو عبر المحفظة', () {
    // ══════════════════════════════════════════════════════════════
    // AMIAL-MERCHANT-WALLET-001 — **الضمانةُ هي الوصول، لا الموضع.**
    //
    // كان هذا الفحصُ يشترط `WithdrawRequestScreen` **في اللوحة نفسِها**،
    // لأنّها كانت الموضعَ الوحيد. ثمّ جُمع المالُ المبعثرُ في «محفظة
    // المتجر» — الرصيدُ والحركاتُ والسحبُ والتحويل — فصار السحبُ داخلها.
    //
    // **ولا يُلَيَّن الحارسُ ليمرّ التغيير.** الشرطُ الأصليُّ باقٍ بحرفه:
    // **يبلغ التاجرُ سحبَ ماله من لوحته**. وما تغيّر أنّ الطريقَ صار
    // خطوتين، **فتُفحَص الخطوتان معاً**: اللوحةُ تفتح المحفظة،
    // والمحفظةُ تفتح السحب. وسقوطُ أيٍّ منهما يُسقط هذا الفحص.
    //
    // (ولو قُبلت «المحفظةُ موجودة» وحدَها لكان هذا حارساً يكذب: بابٌ
    // يقود إلى غرفةٍ لا بابَ فيها.)
    // ══════════════════════════════════════════════════════════════
    final src = dashboard.readAsStringSync();

    final directly = src.contains('const WithdrawRequestScreen()');

    final viaWallet = src.contains('const MerchantWalletScreen()') &&
        File('lib/features/merchant/screens/merchant_wallet_screen.dart')
            .readAsStringSync()
            .contains('const WithdrawRequestScreen()');

    expect(directly || viaWallet, isTrue,
        reason: '**لا يبلغ التاجرُ سحبَ ماله من لوحته.** '
            'مباشرةً: $directly · عبر المحفظة: $viaWallet.\n\n'
            'والسحبُ عبر الوكيل **مبنيٌّ ويعمل منذ شهور**؛ وكانت اللوحةُ '
            'تقول عنه «قريباً» فيظنّ التاجرُ أنّه لا يستطيع إخراجَ مال '
            'بيعه. فإن سقط البابُ عاد الظنُّ نفسُه بلا كلمةٍ تقوله.');
  });
}
