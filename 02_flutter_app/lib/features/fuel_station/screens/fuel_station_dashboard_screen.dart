import 'package:flutter/material.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_owner_console_screen.dart';

/// بوابة توافق لمسار محطة الوقود القديم.
///
/// كانت هذه الشاشة تُظهر بطاقات «بيع سريع/ديون/تقرير اليوم» وتفتح كاشير
/// التجزئة؛ أي أن اسم المسار Fuel كان يقود إلى قطاعٍ آخر. لا يبقى لهذا
/// المسار محتوى مستقل: كل مدخل قديم يصل إلى مساحة تشغيل المحطة المبنية من
/// صلاحياتها، وبذلك لا تظهر شاشة تجزئة لحساب وقود عبر رابط محفوظ أو قدرة
/// قديمة في التطبيق.
class FuelStationDashboardScreen extends StatelessWidget {
  const FuelStationDashboardScreen({super.key});

  @override
  Widget build(BuildContext context) => const FuelOwnerConsoleScreen();
}
