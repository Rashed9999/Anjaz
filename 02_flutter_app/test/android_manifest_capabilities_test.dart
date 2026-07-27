import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-PDF-OPEN-001 — قدرات يعلنها البيان ولا تظهر في شيفرة Dart.
///
/// **الصنف الذي يمنعه هذا الملفّ:**
/// عطلٌ لا أثر له في أي سطر Dart، ولا في الخادم، ولا في تصيير الـ PDF —
/// كلّها تنجح. يقع في إعلانٍ ناقص داخل AndroidManifest.
///
/// منذ أندرويد 11 (API 30) لا يرى التطبيق حزم الجهاز الأخرى إلا ما يُعلنه
/// في `<queries>`. وبلا إعلان قارئ PDF يعود resolveActivity فارغاً، فيُنزَّل
/// الإيصال ويُحفَظ بنجاح ثم لا يُفتح. والمستخدم لا يرى إلا صمتاً.
///
/// اختباراتُ الوحدة لا تكشف هذا لأن الشيفرة سليمة. الفحص الصحيح أن يُسأل:
/// ما الذي يحتاجه النظام إعلاناً، وهل أُعلن؟
void main() {
  const manifestPath = 'android/app/src/main/AndroidManifest.xml';

  late String manifest;

  setUpAll(() {
    manifest = File(manifestPath).readAsStringSync();
  });

  group('رؤية الحزم (queries)', () {
    test('البيان يُعلن كتلة queries', () {
      expect(manifest, contains('<queries>'),
          reason: 'بدونها لا يرى التطبيق أي تطبيق آخر على أندرويد 11+');
    });

    test('يُعلن فتح ملفّات PDF — وإلّا لم يُفتح الإيصال بعد تنزيله', () {
      expect(manifest, contains('application/pdf'));
    });

    test('يُعلن المشاركة — وهي المخرج حين لا يوجد قارئ PDF', () {
      expect(manifest, contains('android.intent.action.SEND'));
    });

    test('يُعلن الاتصال والرسائل وفتح الروابط', () {
      for (final action in const [
        'android.intent.action.DIAL',
        'android.intent.action.SENDTO',
        'android.intent.action.VIEW',
      ]) {
        expect(manifest, contains(action), reason: 'ناقص: $action');
      }
    });
  });

  group('الأذونات التي يعتمد عليها التطبيق', () {
    test('الموقع — تحديد المحافظة تلقائياً', () {
      expect(manifest, contains('ACCESS_COARSE_LOCATION'));
      expect(manifest, contains('ACCESS_FINE_LOCATION'));
    });

    test('الكاميرا — مسح رموز الدفع', () {
      expect(manifest, contains('android.permission.CAMERA'));
    });

    test('الإنترنت', () {
      expect(manifest, contains('android.permission.INTERNET'));
    });
  });
}
