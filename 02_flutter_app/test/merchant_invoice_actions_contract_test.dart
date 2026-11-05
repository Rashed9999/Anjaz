import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-MERCHANT-INVOICE-ACTIONS-001
///
/// لا تعود فواتير القطاعات إلى ثلاثة أزرار متباينة: كل سند بيع مكتمل يجب
/// أن يقدّم الطباعة الحرارية، مشاركة واتساب، وتنزيل PDF من المكوّن المشترك.
/// هذا حارس مصدر لأنّ انهيار الربط يظهر قبل وجود جهاز طابعة أو API في الاختبار.
void main() {
  final actions = File('lib/features/merchant/widgets/merchant_invoice_actions.dart');
  final retailReceipt = File('lib/features/merchant/screens/cashier_receipt_screen.dart');
  final pharmacySale = File('lib/features/pharmacy/screens/pharmacy_sale_screen.dart');
  final fuelReceipt = File('lib/features/fuel_station/screens/fuel_receipt_screen.dart');
  final wholesale = File('lib/features/wholesale/screens/wholesale_workflow_screens.dart');

  test('مكوّن إجراءات الفاتورة الموحد موجود ويعرض الأفعال الثلاثة', () {
    expect(actions.existsSync(), isTrue);
    final src = actions.readAsStringSync();
    for (final label in const ['طباعة حرارية', 'مشاركة الفاتورة عبر واتساب', 'تنزيل PDF']) {
      expect(src, contains(label));
    }
  });

  test('التجزئة والصيدلية تستخدمان إيصال البيع الموحد', () {
    expect(retailReceipt.readAsStringSync(), contains('MerchantInvoiceActions'));
    expect(pharmacySale.readAsStringSync(), contains('CashierReceiptScreen'),
        reason: 'فاتورة الصيدلية لا يجوز أن تسقط إلى شاشة بلا إجراءات موحدة.');
  });

  test('الوقود والجملة لا يفقدان إجراءات الفاتورة الموحدة', () {
    for (final file in [fuelReceipt, wholesale]) {
      expect(file.readAsStringSync(), contains('MerchantInvoiceActions'),
          reason: 'القطاع ${file.path} لا يستخدم أفعال الفاتورة المشتركة.');
    }
  });

  test('فاتورة الجملة لا تفقد مسار محفظة المالك وQR والتحصيل الآجل', () {
    final src = wholesale.readAsStringSync();
    expect(src, contains("'amial_pay'"));
    expect(src, contains("'credit'"));
    expect(src, contains('createInvoicePaymentRequest'));
  });
}
