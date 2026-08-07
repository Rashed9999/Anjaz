// AMIAL-WEB-ONLY-PORTALS-001 — حارس: لوحتا الإدارة والوكيل خارج التطبيق.
//
// **ولماذا حارسٌ بعد الحذف؟**
//
// لأنّ الحذف حدثٌ مرّة، والعودة تحدث بلا قصد: شاشةٌ جديدة تُضاف تحت
// `features/agent/`، أو حالةٌ تُعاد إلى `switch` في `RoleRouter`، ولا شيء
// في الترجمة يمنعها. فتنشأ لوحةٌ ثانية للوكيل تنافس بوّابة المتصفّح —
// وتُصلَح الأعطال في واحدةٍ دون الأخرى.
//
// وثلاثة أشياء يمنعها هذا الملفّ:
//   ١) عودة مجلّدَي `features/admin` أو `features/agent`.
//   ٢) سقوط `agent`/`admin` إلى `default` = شاشة العميل (الخطر الصامت:
//      لا خطأ ترجمة، والوكيل يهبط في محفظةٍ ليست له).
//   ٣) ضياع عنوانَي البوّابتين من الشيفرة.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/features/auth/screens/role_router.dart';
import 'package:amial_pay/features/home/screens/nav_bar_screen.dart';
import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';

void main() {
  const fast = Timeout(Duration(seconds: 30));

  group('لوحتا الإدارة والوكيل لا تعودان إلى التطبيق', () {
    test('لا مجلّد features/admin ولا features/agent', () {
      for (final d in ['lib/features/admin', 'lib/features/agent']) {
        expect(Directory(d).existsSync(), isFalse,
            reason: '$d عاد. لوحتا الإدارة والوكيل على المتصفّح: '
                'راجع docs أو احذف المجلّد.');
      }
    }, timeout: fast);

    test('لا استيراد لأيّ منهما في أيّ ملفّ دارت', () {
      final offenders = <String>[];
      for (final dir in ['lib', 'test', 'integration_test']) {
        final d = Directory(dir);
        if (!d.existsSync()) continue;
        for (final f in d.listSync(recursive: true).whereType<File>()) {
          if (!f.path.endsWith('.dart')) continue;
          final src = f.readAsStringSync();
          if (src.contains('package:amial_pay/features/admin/') ||
              src.contains('package:amial_pay/features/agent/')) {
            offenders.add(f.path);
          }
        }
      }
      expect(offenders, isEmpty,
          reason: 'استيراد للوحةٍ محذوفة في: ${offenders.join(", ")}');
    }, timeout: fast);
  });

  group('الوكيل والأدمن لا يسقطان إلى شاشة العميل', () {
    test('كلٌّ منهما → WebPortalNoticeScreen بدوره الصحيح', () {
      for (final role in ['agent', 'admin']) {
        final home = RoleRouter.homeForRole(role);
        expect(home, isA<WebPortalNoticeScreen>(), reason: 'الدور: $role');
        expect((home as WebPortalNoticeScreen).role, equals(role));
      }
    }, timeout: fast);

    // النفي هو الحارس الحقيقيّ: حذفُ الحالة يُعيدهما إلى `default` بصمت،
    // فيمرّ الاختبار الأوّل لو كتبناه «ليس null» ويسقط هذا وحده.
    test('ولا واحد منهما NavBarScreen (شاشة العميل)', () {
      for (final role in ['agent', 'admin']) {
        expect(RoleRouter.homeForRole(role), isNot(isA<NavBarScreen>()),
            reason: 'الدور $role هبط في شاشة العميل — حالتُه سقطت من switch');
      }
      // ضبطٌ مقابل: العميل نفسه ما زال يصل شاشته، فالنفي أعلاه ليس عامّاً.
      expect(RoleRouter.homeForRole('customer'), isA<NavBarScreen>());
    }, timeout: fast);
  });

  group('عنوانا البوّابتين', () {
    test('النطاق المعتمد + مساران متمايزان', () {
      expect(AppConstants.productionDomain, equals('https://amialpay.com'));

      const agent = WebPortalNoticeScreen(role: 'agent');
      const admin = WebPortalNoticeScreen(role: 'admin');

      expect(agent.portalUrl, equals('https://amialpay.com/agent/login'));
      expect(admin.portalUrl, equals('https://amialpay.com/admin/auth/login'));

      // خلطُ العنوانين يُرسل كلاً منهما إلى بوّابةٍ ترفضه بلا رسالةٍ مفهومة،
      // وهو خطأٌ يمرّ بسهولةٍ لأنّ الشاشتين واحدة والفرق حرفٌ في مسار.
      expect(agent.portalUrl, isNot(equals(admin.portalUrl)));

      // وكلاهما على النطاق المعتمد لا على عنوانٍ رقميّ منسيّ.
      for (final u in [agent.portalUrl, admin.portalUrl]) {
        expect(u.startsWith('https://'), isTrue, reason: u);
        expect(RegExp(r'\d+\.\d+\.\d+\.\d+').hasMatch(u), isFalse, reason: u);
      }
    }, timeout: fast);
  });
}
