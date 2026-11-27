import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Merchant records and capabilities survive the phase-one freeze.
/// Only the shared reports route belongs in the customer app.
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

    return RegExp(r'^\s*GetPage\(name: (\w+)', multiLine: true)
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

  test('merchant-only screens are absent from customer navigation', () {
    final merchant = backendCapabilityScreens();
    expect(merchant, containsAll(['/products', '/cashier']));
    expect(merchant.intersection(registeredCapabilityRoutes()), {'/reports'});
  });
}
