import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/features/access/domain/vertical_catalog.dart';

/// AMIAL-VERTICAL-COMPOSE-001 — **القطاعُ المُضاف يصل البطاقةَ التي
/// تُضغَط.**
///
/// ══════════════════════════════════════════════════════════════════════
/// الخادمُ يعرف القطاعَ الجديد، ويمنحه قدراتِه، ويرسله في القائمة —
/// **وكلُّ ذلك بلا معنىً إن سقط في الدمج هنا.** وسقوطُه صامت: تُعرَض
/// ستُّ بطاقاتٍ من سبع، ولا خطأ.
void main() {
  group('VerticalCatalog.parse', () {
    test('القطاعُ المُضاف يظهر بعد الستّة المبنيّة', () {
      final rows = [
        {'value': 'retail', 'short_label': 'تجزئة', 'is_built_in': true},
        {
          'value': 'bakery',
          'short_label': 'مخبز',
          'hint': 'خبز ومعجّنات',
          'icon': 'bakery_dining',
          'color': '#00A651',
          'is_built_in': false,
        },
      ];

      final out = VerticalCatalog.parse(rows);

      expect(out.length, 2);
      expect(out.map((o) => o.code), containsAll(['retail', 'bakery']));

      final bakery = out.firstWhere((o) => o.code == 'bakery');
      expect(bakery.label, 'مخبز');
      expect(bakery.description, 'خبز ومعجّنات');
      expect(bakery.icon, Icons.bakery_dining);
      expect(bakery.color, const Color(0xFF00A651));
    });

    test('المبنيُّ يحتفظ بشكله المعروف ولا يُعاد رسمُه من الخادم', () {
      // الخادمُ لا يرسل أيقونةً للستّة — ولو أُخذ منه لَصارت بطاقةُ
      // الصيدليّة متجراً عامّاً في شاشةٍ يعرفها المستعمل.
      final out = VerticalCatalog.parse([
        {'value': 'pharmacy', 'short_label': 'صيدلية', 'icon': null},
      ]);

      expect(out.single.icon, Icons.local_pharmacy);
      expect(out.single.color, const Color(0xFF7B1FA2));
    });

    test('أيقونةٌ لا يعرفها التطبيقُ تُرسَم متجراً ولا تنهار', () {
      final out = VerticalCatalog.parse([
        {'value': 'x_shop', 'short_label': 'محلّ', 'icon': 'rocket_launch'},
      ]);

      expect(out.single.icon, Icons.storefront);
    });

    test('لونٌ مشوَّهٌ لا يُسقط البطاقة', () {
      for (final bad in ['', 'zzz', '#12', null]) {
        final out = VerticalCatalog.parse([
          {'value': 'x_shop', 'short_label': 'محلّ', 'color': bad},
        ]);

        expect(out.single.color, const Color(0xFF00A651));
      }
    });

    // **وردٌّ فارغٌ لا يُقفل بابَ التسجيل** — شاشةٌ بلا بطاقاتٍ تمنع
    // إنشاءَ الحساب أصلاً، وهو أسوأ من قائمةٍ ناقصةٍ مؤقّتاً.
    test('الردُّ الفارغُ أو المشوَّهُ يُرجَع إلى الستّة', () {
      for (final bad in [null, <dynamic>[], 'nonsense', 42]) {
        expect(VerticalCatalog.parse(bad).length, VerticalCatalog.builtIn.length);
      }
    });

    test('صفٌّ بلا رمزٍ يُتخطّى ولا يُنتج بطاقةً بلا هويّة', () {
      final out = VerticalCatalog.parse([
        {'short_label': 'بلا رمز'},
        {'value': 'bakery', 'short_label': 'مخبز'},
      ]);

      expect(out.length, 1);
      expect(out.single.code, 'bakery');
    });
  });
}
