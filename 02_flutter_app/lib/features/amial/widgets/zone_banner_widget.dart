import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/amial/controllers/amial_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-ZONE-001 (v0.7-D)
///
/// ZoneBannerWidget — يعرض banner أعلى الشاشة لو المستخدم في read-only mode
/// (خارج SOUTH).
///
/// الاستخدام في أي شاشة:
///   ```dart
///   Scaffold(
///     body: Column(
///       children: [
///         const ZoneBannerWidget(),
///         Expanded(child: yourContent),
///       ],
///     ),
///   );
///   ```
///
/// لو المستخدم can_transact = true، الـ widget يعيد SizedBox.shrink (لا يظهر شيء).
class ZoneBannerWidget extends StatelessWidget {
  final EdgeInsets margin;

  const ZoneBannerWidget({
    super.key,
    this.margin = EdgeInsets.zero,
  });

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final ctrl = Get.find<AmialController>();
      final policy = ctrl.sessionPolicy.value;

      // لا banner لو لا policy بعد أو المستخدم يقدر يعامل
      if (policy == null || policy.canTransact) {
        return const SizedBox.shrink();
      }

      final message = policy.bannerMessage ??
          'العمليات المالية متاحة في الجنوب فقط حالياً.';

      return Container(
        margin: margin,
        width: double.infinity,
        decoration: BoxDecoration(
          color: AmialColors.yellow.withValues(alpha: 0.95),
          border: Border(
            bottom: BorderSide(color: AmialColors.yellowDark, width: 1),
          ),
        ),
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: AmialColors.primary,
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.visibility_outlined,
                    size: 16,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'وضع القراءة فقط',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                          color: AmialColors.primary,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        message,
                        style: TextStyle(
                          fontSize: 12,
                          color: AmialColors.primary.withValues(alpha: 0.85),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    });
  }
}
