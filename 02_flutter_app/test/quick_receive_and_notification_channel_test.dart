import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/features/auth/domain/quick_receive_preferences.dart';

/// AMIAL-QUICK-RECEIVE-004 · AMIAL-FCM-CHANNEL-001
///
/// ══════════════════════════════════════════════════════════════════════
/// **عطلان قالهما صاحبُ المشروع في رسالةٍ واحدة:**
///
///   ① «الدفعُ السريع دون الدخول للحساب لا تعمل، وقد كانت في الكود
///      الأصليّ 6cash تعمل سابقاً».
///   ② «الإشعاراتُ في الكود الأصليّ تعمل: إشعارٌ في خلفيّة الهاتف وصوتُ
///      نغمة الإشعارات… هذه لا تعمل لديّ».
///
/// وكلاهما قِيس، وكلاهما **عطلُ صمتٍ**: لا خطأَ في أيّ سجلّ، ولا بناءٌ
/// يسقط، ولا شيءَ يُشتكى منه إلّا «لا تعمل».
void main() {
  group('① الاستلامُ السريع — مقارنةُ المالك', () {
    // ══════════════════════════════════════════════════════════════════
    // **الصيغتان الحقيقيّتان، لا صيغتان مخترَعتان:**
    //
    //     المخزَّن ← الملفّ الشخصيّ    : 967777100001
    //     الأخير  ← ما كتبه في الدخول : 777100001
    //
    // والمقارنةُ كانت حرفيّةً في الشاشة (`owner != lastPhone`) فتقول
    // «مختلفان» **في كلّ مرّة** — فتُعرَض حالةُ «مُعطَّل» والميزةُ
    // مفعَّلة. وهو حاجزٌ يشلّ عملاً سليماً، وذاك أسوأ من ثغرة.
    // ══════════════════════════════════════════════════════════════════
    test('الرقمُ الواحد بصيغتيه صاحبٌ واحد', () {
      expect(
        QuickReceivePreferences.isSameOwner(
          storedOwner: '967777100001',
          currentPhone: '777100001',
        ),
        isTrue,
        reason: '**الرقمُ نفسُه رُفض لاختلاف الصيغة** — والمخزَّن يأتي من '
            'الملفّ الشخصيّ بمفتاح الدولة، والمكتوبُ في الدخول بلا مفتاح. '
            'فتُعرَض «مُعطَّل» على ميزةٍ مفعَّلة.',
      );

      for (final typed in ['+967777100001', '00967777100001', '0777100001']) {
        expect(
          QuickReceivePreferences.isSameOwner(
            storedOwner: '967777100001',
            currentPhone: typed,
          ),
          isTrue,
          reason: 'الصيغةُ «$typed» رُفضت — والرقمُ الواحد يصل بأربع صيغ.',
        );
      }
    });

    test('ورقمُ شخصٍ آخرَ يُردّ — وإلّا كان الحارسُ يقبل كلَّ شيء', () {
      expect(
        QuickReceivePreferences.isSameOwner(
          storedOwner: '967777100001',
          currentPhone: '967777100002',
        ),
        isFalse,
        reason: '**عُرض عنوانُ حسابٍ لصاحب جهازٍ آخر** — والميزةُ تُفتح '
            'قبل الدخول، فلا شيءَ بعدها يمنع.',
      );
    });

    test('و«لا أعرف» ليس «هو نفسُه»', () {
      // القاعدةُ السابعة: الغيابُ لا يُقرأ تطابقاً.
      expect(
        QuickReceivePreferences.isSameOwner(
            storedOwner: '', currentPhone: '777100001'),
        isFalse,
        reason: 'مالكٌ فارغٌ قُرئ تطابقاً — فيُفتح لأيّ حاملٍ للجهاز',
      );
      expect(
        QuickReceivePreferences.isSameOwner(
            storedOwner: '967777100001', currentPhone: 'abc'),
        isFalse,
        reason: 'رقمٌ لا يُقرأ منه شيءٌ قُرئ تطابقاً',
      );
    });

    test('ولا يبقى في الشاشة مقارنةٌ حرفيّةٌ ثانية', () {
      final src =
          File('lib/features/auth/screens/quick_receive_screen.dart')
              .readAsStringSync();

      // **المصدرُ واحد.** مقارنتان مكتوبتان في موضعين تفترقان — وقد
      // افترقتا فعلاً: هذه حرفيّة، وأختُها بآخر تسعة أرقام.
      expect(src.contains('QuickReceivePreferences.isSameOwner'), isTrue,
          reason: 'الشاشةُ لا تستعمل المقارنةَ المشتركة — فمقارنةٌ ثانيةٌ '
              'تشيخ وحدَها، وهو ما وقع.');

      expect(src.contains('owner != lastPhone'), isFalse,
          reason: '**عادت المقارنةُ الحرفيّة** — فتردّ الشاشةُ صاحبَها '
              'كلَّما اختلفت صيغةُ الرقم.');
    });

    test('وبطاقةُ شاشة الدخول لا تَعِد بما تنكره الشاشة', () {
      final src =
          File('lib/features/auth/screens/unified_login_screen.dart')
              .readAsStringSync();

      // كانت تُضيء بمجرّد «آخر مستخدمٍ عميل» بلا أن تسأل هل فُعّلت
      // الميزة — فتقول «اعرض رمز الاستلام دون تسجيل الدخول» ثمّ تفتح
      // شاشةً تقول «مُعطَّل».
      expect(src.contains('QuickReceivePreferences'), isTrue,
          reason: '**البطاقةُ لا تسأل حالةَ الميزة** — تَعِد ثمّ تنكر في '
              'ضغطةٍ واحدة، ويقرأ صاحبُها ذلك «لا تعمل».');

      expect(src.contains('_quickReceiveReady'), isTrue,
          reason: 'شرطُ الجهوزيّة غير مشتركٍ بين إضاءة البطاقة وفتحِها — '
              'فتُضيء وتُردّ، أو تُطفأ وتُفتح.');
    });
  });

  group('② قناةُ الإشعارات — موضعُ الإعلان', () {
    // **والتعليقاتُ تُنزَع قبل القياس.** أوّلُ صياغةٍ لهذا الحارس سقطت
    // على نفسِها: التعليقُ الذي كُتب في البيان يشرح العطلَ يذكر
    // «activity» بين قوسين، فوجده `indexOf` قبل الوسم الحقيقيّ وقال إنّ
    // الإعلانَ داخل نشاط — وهو خارجَه.
    //
    // وهو بعينه العطلُ المسجَّل في المشروع: **تعليقٌ يصف العطلَ فيُخفيه**.
    final manifest =
        File('android/app/src/main/AndroidManifest.xml')
            .readAsStringSync()
            .replaceAll(RegExp(r'<!--.*?-->', dotAll: true), '');

    // ══════════════════════════════════════════════════════════════════
    // **Firebase يقرأ هذه الثلاثةَ من <application> وحدَها.**
    //
    // كانت داخل <activity> — والملفُّ صحيحٌ نحويّاً، وgradle يبنيه،
    // والإشعارُ يصل. **صامتاً**، بأيقونةٍ بيضاء، على قناةٍ احتياطيّة.
    //
    // ولا يمسكه مُصرِّفٌ ولا `flutter analyze` ولا أيّ اختبارٍ من جهة
    // الخادم: الخادمُ يرسل `channel_id` صحيحاً، والجهازُ يتجاهله.
    // ══════════════════════════════════════════════════════════════════
    final appAt = manifest.indexOf('<application');
    final firstActivityAt = manifest.indexOf('<activity', appAt);

    test('الملفُّ فيه <application> و<activity> — وإلّا فحصنا العدم', () {
      expect(appAt, greaterThanOrEqualTo(0));
      expect(firstActivityAt, greaterThan(appAt));
    });

    for (final key in [
      'com.google.firebase.messaging.default_notification_channel_id',
      'com.google.firebase.messaging.default_notification_icon',
      'com.google.firebase.messaging.default_notification_color',
    ]) {
      test('«${key.split('.').last}» مُعلَنٌ تحت <application> لا داخل نشاط',
          () {
        final at = manifest.indexOf(key);

        expect(at, greaterThan(appAt),
            reason: 'الإعلانُ «$key» مفقودٌ من الملفّ');

        expect(at, lessThan(firstActivityAt),
            reason: '**«$key» داخل <activity>** — وFirebase لا ينظر هناك. '
                'فإشعارُ الخلفيّة يذهب إلى القناة الاحتياطيّة: بلا نغمتنا '
                'وبأيقونةٍ بيضاء، ولا خطأَ في أيّ سجلّ.');
      });
    }

    test('والقناةُ المُعلَنة هي التي يُنشئها التطبيق ويرسل إليها الخادم', () {
      final helper =
          File('lib/helper/notification_helper.dart').readAsStringSync();

      const id = 'amial_pay_default';

      expect(helper.contains("androidChannelId = '$id'"), isTrue,
          reason: 'اسمُ القناة في الشيفرة تغيّر — واسمان مختلفان يعنيان '
              'إشعاراً يصل إلى قناةٍ لم تُنشأ، فيفقد نغمتَه.');

      expect(manifest.contains('android:value="$id"'), isTrue,
          reason: 'القناةُ في البيان تخالف التي يُنشئها التطبيق');
    });

    test('وملفُّ النغمة موجودٌ فعلاً — فقناةٌ تشير إلى صوتٍ مفقودٍ صامتة', () {
      final raw = Directory('android/app/src/main/res/raw');

      expect(raw.existsSync(), isTrue,
          reason: 'مجلّدُ الأصوات مفقود — والقناةُ تطلب `notification`');

      final names = raw
          .listSync()
          .map((f) => f.uri.pathSegments.last.split('.').first)
          .toSet();

      expect(names.contains('notification'), isTrue,
          reason: '**ملفُّ النغمة `notification` مفقود** — والقناةُ تُنشأ '
              'وتشير إليه، فيصمت الإشعارُ بلا خطأ. وقناةُ أندرويد لا '
              'تُعدَّل بعد إنشائها، فالإصلاحُ لاحقاً لا يبلغ من نُصّبت '
              'عنده صامتة.');
    });
  });
}
