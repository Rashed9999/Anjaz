import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_sale_screen.dart';

/// مدخل موظف نقطة البيع، لا لوحة مالك المنشأة.
///
/// الخادم هو من يقيّد القدرات وصلاحيات الـ POS؛ هذه الطبقة تمنع تسريب
/// واجهات المالك أو واجهات العميل وتختار سير البيع المناسب للقطاع فقط.
class MerchantPosHomeScreen extends StatelessWidget {
  const MerchantPosHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();
    if (access.isFuel) return const FuelSaleScreen();
    if (access.isPharmacy) return const PharmacySaleScreen();

    // التجزئة والجملة والبيع السريع تستخدم الكاشير المشترك. المطعم لا
    // يفتح لوحة المالك من هنا إلى أن يكون له كاشير طلبات محدود الصلاحية.
    return const CashierPosScreen();
  }
}
