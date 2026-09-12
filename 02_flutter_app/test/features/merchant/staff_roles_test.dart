import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/features/merchant/models/staff_roles.dart';

/// AMIAL-STAFF-ROLES-001 — **حارسُ الوظائف.**
///
/// ══════════════════════════════════════════════════════════════════════
/// جدولُ الأدوار يبدو بيانات، **وأربعةُ أعطالٍ حقيقيّةٍ تسكنه**، وكلُّها
/// من صنفٍ واحد: **شاشةٌ تعرض بثقةٍ وتفعل الخطأ** — لا استثناءَ ولا
/// سطرَ في أيّ سجلّ.
void main() {
  group('وظائفُ الموظّفين', () {
    /// **① دورٌ في القائمة بلا صلاحيّاتٍ خلفه.**
    ///
    /// يُضاف «محاسب» إلى [StaffRoles.labels] ويُنسى في
    /// [StaffRoles.permissions]، **فتعرضه القائمةُ** ويختاره التاجر،
    /// و`preset == null` فتبقى صلاحيّاتُ الدور السابق صامتة. فيُنشأ
    /// «محاسب» صلاحيّاتُه صلاحيّاتُ مديرٍ ولا أحدَ يعلم.
    test('كلُّ دورٍ في القائمة له صلاحيّاتٌ ونصٌّ يشرحه', () {
      final orphans = StaffRoles.labels.keys
          .where((r) => r != StaffRoles.custom)
          .where((r) => !StaffRoles.permissions.containsKey(r))
          .toList();

      expect(orphans, isEmpty,
          reason: '**أدوارٌ تظهر في القائمة ولا صلاحيّاتِ لها:** $orphans\n'
              'فيختارها التاجرُ وتبقى صلاحيّاتُ الدور السابق صامتةً.');

      final unsaid = StaffRoles.labels.keys
          .where((r) => !StaffRoles.says.containsKey(r))
          .toList();

      expect(unsaid, isEmpty,
          reason: '**أدوارٌ لا تقول ماذا تُتيح:** $unsaid\n'
              'واسمُ الدور وحدَه لا يُعرّف التاجرَ ما يستطيعه موظّفُه.');
    });

    /// **② صلاحيّةٌ لا يعرفها الخادم.**
    ///
    /// خطأٌ مطبعيٌّ (`report` بدل `reports`) يمرّ في دارت بلا شكوى،
    /// ويُرسَل إلى `/merchant/staff` فيسقط أو — أسوأ — يُقبل ويُخزَّن
    /// صلاحيّةً لا يفحصها أحد.
    test('لا صلاحيّةَ خارج ما يقبله الخادم', () {
      for (final entry in StaffRoles.permissions.entries) {
        final unknown = entry.value
            .where((p) => !StaffRoles.allPermissions.containsKey(p))
            .toList();

        expect(unknown, isEmpty,
            reason: '**«${entry.key}» يمنح صلاحيّاتٍ لا وجودَ لها:** $unknown\n'
                'والمرجعُ ${StaffRoles.allPermissions.keys.toList()}');
      }
    });

    /// **③ والترقيةُ لا تنزع.**
    ///
    /// وهذا العطلُ بعينه أمسكه حارسُ الباقات من قبل: منحُ قدرةٍ في
    /// مستوىً دون الذي فوقه يجعل **الترقيةَ تُنقص**. فالتاجرُ يرفع
    /// كاشيراً إلى «مدير مناوبة» فيفقد الرجلُ قدرةً كانت له.
    test('كاشير ⊂ مناوبة ⊂ مدير', () {
      for (var i = 0; i + 1 < StaffRoles.ladder.length; i++) {
        final lower = StaffRoles.ladder[i];
        final upper = StaffRoles.ladder[i + 1];

        final lost = StaffRoles.permissions[lower]!
            .difference(StaffRoles.permissions[upper]!)
            .toList();

        expect(lost, isEmpty,
            reason: '**الترقيةُ من «$lower» إلى «$upper» تنزع:** $lost\n'
                'فالموظّفُ يُرقّى فيفقد ما كان يفعله أمس.');
      }
    });

    /// **④ و«مدير» يعني كلَّ شيء.**
    ///
    /// فدورٌ اسمُه «مدير» وينقصه بندٌ يجعل التاجرَ يبحث عن سبب امتناع
    /// شاشةٍ عن مديره — **ولا رسالةَ تقول له إنّ الدورَ ناقص**.
    test('«مدير» يشمل كلَّ الصلاحيّات', () {
      final missing = StaffRoles.allPermissions.keys
          .where((p) => !StaffRoles.permissions['manager']!.contains(p))
          .toList();

      expect(missing, isEmpty,
          reason: '**«مدير» ينقصه:** $missing');
    });

    /// **⑤ و«مخصّص» ليس دوراً — هو بابُ المربّعات.**
    ///
    /// فلو نُسب إليه طقمُ صلاحيّاتٍ لصار دوراً خامساً صامتاً، ولَما فتح
    /// المربّعاتِ أصلاً (الشاشةُ تفتحها بـ`role == custom` وحدَه).
    test('«مخصّص» بلا طقمٍ مسبق، والافتراضُ دورٌ حقيقيّ', () {
      expect(StaffRoles.permissions.containsKey(StaffRoles.custom), isFalse,
          reason: '«مخصّص» نُسب إليه طقمٌ — فصار دوراً خامساً صامتاً.');

      expect(StaffRoles.labels.containsKey(StaffRoles.defaultRole), isTrue,
          reason: 'الدورُ الافتراضيُّ ليس في القائمة — فتفتح الشاشةُ على '
              'قيمةٍ لا خيارَ لها، و`DropdownButtonFormField` يرمي.');

      expect(StaffRoles.defaultRole, isNot(StaffRoles.custom),
          reason: 'الافتراضُ «مخصّص» يفتح المربّعاتِ الخمسةَ لكلّ تاجر — '
              'وهو عينُ ما بُنيت هذه الشاشةُ لتنهيه.');
    });
  });
}
