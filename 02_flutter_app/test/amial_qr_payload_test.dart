import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/features/payments/domain/amial_qr_payload.dart';

/// AMIAL-QR-UNIFIED-001 — تحليل رموز أميال باي.
///
/// الاختبار هنا ممكن أصلاً لأن التحليل فُصل عن الكاميرا والواجهة. حين كان
/// داخل `processImage` لم يكن يُختبر — ولذلك بقي عطلان فيه بلا اكتشاف:
/// صيغة طلب الدفع مجهولة لديه، و`jsonDecode` بلا حماية.
void main() {
  group('طلب الدفع (المبلغ يحدّده البائع)', () {
    test('يُقرأ رمز الكاشير ويُستخرج منه الرمز القصير', () {
      final p = AmialQrPayload.parse(
          jsonEncode({'t': 'amial_pr', 'code': 'AB12CD'}));

      expect(p.kind, AmialQrKind.paymentRequest);
      expect(p.requestCode, 'AB12CD');
      expect(p.isPaymentRequest, isTrue);
    });

    test('طلب دفع بلا رمز قصير ليس طلب دفع', () {
      // لو عُومل كطلب دفع لانتقل المستخدم إلى شاشة تستعلم عن رمز فارغ.
      final p = AmialQrPayload.parse(jsonEncode({'t': 'amial_pr'}));
      expect(p.kind, AmialQrKind.unknown);
    });
  });

  group('رموز الهوية (المبلغ يكتبه المستخدم)', () {
    String identity(String type) =>
        jsonEncode({'name': 'أحمد', 'phone': '777123456', 'type': type, 'image': 'x.png'});

    test('عميل', () {
      final p = AmialQrPayload.parse(identity('customer'));
      expect(p.kind, AmialQrKind.customer);
      expect(p.phone, '777123456');
      expect(p.name, 'أحمد');
    });

    test('وكيل', () {
      expect(AmialQrPayload.parse(identity('agent')).kind, AmialQrKind.agent);
    });

    test('تاجر', () {
      expect(AmialQrPayload.parse(identity('merchant')).kind, AmialQrKind.merchant);
    });

    /// الرمز القديم كان يشترط الحقول الأربعة معاً، فرمزٌ بلا صورة يُهمَل
    /// بصمت رغم أن الهاتف — وهو كل ما يلزم — موجود فيه.
    test('الهاتف وحده يكفي: رمز بلا صورة ولا اسم يبقى صالحاً', () {
      final p = AmialQrPayload.parse(
          jsonEncode({'phone': '777123456', 'type': 'customer'}));

      expect(p.kind, AmialQrKind.customer);
      expect(p.phone, '777123456');
    });

    test('نوع مجهول يُعامَل معاملة العميل — أضيق الاحتمالات صلاحيةً', () {
      final p = AmialQrPayload.parse(
          jsonEncode({'phone': '777123456', 'type': 'wizard'}));
      expect(p.kind, AmialQrKind.customer);
    });
  });

  group('رقم هاتف مجرّد', () {
    test('رمز نصّه رقم يمني', () {
      final p = AmialQrPayload.parse('777123456');
      expect(p.kind, AmialQrKind.phone);
      expect(p.phone, '777123456');
    });

    test('يُنظَّف من الفواصل وبادئة +', () {
      expect(AmialQrPayload.parse('+967 777-123-456').phone, '967777123456');
    });

    /// الرمز العاري لا يقول ما هو، فلا يُقبل إلا بشكل يمنيّ صريح. القاعدة
    /// المتساهلة (6–15 رقماً) كانت تبتلع باركود المنتجات فتعرض على المستخدم
    /// تحويلاً إلى رقم لا وجود له. (كشفه اختبار الباركود أدناه.)
    test('أرقام ليست على شكل هاتف يمني تُرفض', () {
      for (final raw in ['6281234567890', '123456', '00000000000000']) {
        expect(AmialQrPayload.parse(raw).kind, AmialQrKind.unknown,
            reason: '«\$raw» ليس رقماً يمنياً');
      }
    });
  });

  group('لا يرمي أبداً', () {
    /// هذا هو الاختبار الذي كان غيابه يُميت الماسح: `jsonDecode` بلا حماية
    /// يرمي على أي رمز ليس JSON، والقفل لا يُرفع، فلا يعمل الماسح ثانيةً
    /// حتى يُغلق التطبيق. ورموز غير أميال تُمسح كل يوم.
    test('باركود منتج ورمز موقع ونصّ عشوائي', () {
      for (final raw in [
        '6281234567890',                 // باركود EAN — أرقام أكثر من 15
        'https://example.com/promo',
        'مرحبا',
        '{ليس JSON',
        '[]',
        '',
        null,
      ]) {
        expect(AmialQrPayload.parse(raw).kind, AmialQrKind.unknown,
            reason: 'الرمز «$raw» يجب أن يكون مجهولاً لا أن يرمي');
      }
    });

    test('JSON صالح بلا حقول نعرفها', () {
      expect(AmialQrPayload.parse(jsonEncode({'foo': 'bar'})).kind,
          AmialQrKind.unknown);
    });
  });
}
