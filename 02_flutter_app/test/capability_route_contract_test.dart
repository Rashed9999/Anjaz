import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-ENTITLEMENTS-ROUTES-001
///
/// The capability manifest is produced by Laravel, while navigation is owned
/// by Flutter. Neither compiler can see the other project, so this source
/// contract prevents the expensive failure where a merchant is told a service
/// is available and only learns at tap-time that its screen has no route.
void main() {
  final registry = File(
      '../01_backend/app/Support/Access/CapabilityRegistry.php');
  final routeHelper = File('lib/helper/route_helper.dart');

  Set<String> backendCapabilityScreens() => RegExp(r"->screen\('([^']+)'\)")
      .allMatches(registry.readAsStringSync())
      .map((m) => m.group(1)!)
      .toSet();

  Set<String> registeredCapabilityRoutes() {
    final src = routeHelper.readAsStringSync();
    final constants = <String, String>{
      for (final m in RegExp(
              r"static const String (\w+) = '(/[^']+)'" )
          .allMatches(src))
        m.group(1)!: m.group(2)!,
    };

    return RegExp(r'GetPage\(name: (\w+)')
        .allMatches(src)
        .map((m) => constants[m.group(1)!])
        .whereType<String>()
        .toSet();
  }

  test('مصدرَا عقد الخدمات موجودان', () {
    expect(registry.existsSync(), isTrue,
        reason: 'سجل القدرات الخلفي غير موجود: ${registry.path}');
    expect(routeHelper.existsSync(), isTrue,
        reason: 'سجل مسارات التطبيق غير موجود: ${routeHelper.path}');
  });

  test('كل خدمة معلنة ذات شاشة تسجل مساراً قابلاً للتنقل', () {
    final backend = backendCapabilityScreens();
    final mobile = registeredCapabilityRoutes();

    expect(backend, isNotEmpty,
        reason: 'لم يُستخرج أي مسار من CapabilityRegistry؛ تغيّرت الصياغة');

    final missing = backend.difference(mobile).toList()..sort();
    expect(missing, isEmpty,
        reason: 'خدمات يعلنها الخادم بلا شاشة Flutter مسجلة:\n'
            '${missing.join('\n')}\n\n'
            'أضف GetPage حقيقياً أو أزل screen من القدرة حتى لا تعد الواجهة '
            'بخدمة لا يستطيع العميل فتحها.');
  });
}
