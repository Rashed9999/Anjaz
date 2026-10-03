import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-FUEL-SHIFT-GATE-001 — كاشير الوقود لا يملأ عملية ثم يكتشف
/// عند التأكيد أن المحطة بلا وردية، ولا يتحول في المقابل إلى مشرف يفتحها.
void main() {
  final sale =
      File('lib/features/fuel_station/screens/fuel_sale_screen.dart');
  final gate =
      File('lib/features/fuel_station/widgets/fuel_shift_gate.dart');

  test('ملفات باب وردية الوقود موجودة ومتصلة بشاشة البيع', () {
    expect(sale.existsSync(), isTrue);
    expect(gate.existsSync(), isTrue);

    final src = sale.readAsStringSync();
    expect(src.contains('FuelShiftGate'), isTrue,
        reason: 'شاشة بيع الوقود تُفتح قبل التحقق من وردية المحطة');
    expect(src.contains("fuel_shift_gate.dart"), isTrue);
  });

  test('الباب يقرأ وردية المحطة من الخادم ولا يفتحها باسم الكاشير', () {
    final src = gate
        .readAsStringSync()
        .replaceAll(RegExp(r'//[^\n]*'), '');

    expect(
      src.contains(
          "'/api/v1/amial/merchant/fuel/shifts/current'"),
      isTrue,
      reason: 'الباب لا يقرأ الحالة الحقيقية للوردية',
    );

    expect(
      src.contains('/fuel/shifts/open'),
      isFalse,
      reason:
          'واجهة كاشير الوقود أصبحت تفتح وردية المحطة؛ هذا فعل المشرف/المدير',
    );

    expect(src.contains('_hasOpenShift'), isTrue);
    expect(src.contains('اطلب من مشرف الوردية أو مدير المحطة'), isTrue,
        reason: 'الرفض لا يشرح للكاشير كيف يعود للعمل');
  });
}
