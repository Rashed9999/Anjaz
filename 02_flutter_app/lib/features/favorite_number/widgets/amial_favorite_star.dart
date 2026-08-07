import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/favorite_number/controllers/amial_favorites_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-FAVORITES-001 — نجمة المفضّلة.
///
/// ودجت واحدة تُستعمل أينما وُجد ما يستحقّ التكرار: جهة اتصال، رقم حساب،
/// تاجر، عملية سابقة. توحيدها يجعل النجمة تعني الشيء نفسه في كل شاشة —
/// وهو شرط أن يفهمها المستخدم بلا شرح.
///
/// تُحدَّث تفاؤلياً: الضغطة تُرى فوراً ثم تُصحَّح إن رفض الخادم. الانتظار
/// على الشبكة قبل تلوين النجمة يجعلها تبدو معطّلة على اتصال بطيء.
class AmialFavoriteStar extends StatelessWidget {
  const AmialFavoriteStar({
    super.key,
    required this.kind,
    required this.value,
    this.label,
    this.metadata,
    this.size = 22,
    this.showFeedback = true,
  });

  final String kind;
  final String value;
  final String? label;
  final Map<String, dynamic>? metadata;
  final double size;
  final bool showFeedback;

  @override
  Widget build(BuildContext context) {
    // لا نُسقط الشاشة إن لم يُسجَّل المتحكّم بعد — المفضّلة تحسينية.
    if (!Get.isRegistered<AmialFavoritesController>()) {
      Get.put(AmialFavoritesController(), permanent: true);
    }
    final c = Get.find<AmialFavoritesController>();

    return Obx(() {
      final isFav = c.isFavorite(kind, value);

      return IconButton(
        tooltip: isFav ? 'إزالة من المفضّلة' : 'إضافة إلى المفضّلة',
        visualDensity: VisualDensity.compact,
        padding: EdgeInsets.zero,
        constraints: BoxConstraints(minWidth: size + 14, minHeight: size + 14),
        icon: Icon(
          isFav ? Icons.star_rounded : Icons.star_border_rounded,
          size: size,
          color: isFav ? AmialColors.yellowDark : AmialColors.textMuted,
        ),
        onPressed: () async {
          final result = await c.toggle(
            kind: kind, value: value, label: label, metadata: metadata,
          );
          if (result != null && showFeedback && context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(result ? 'أُضيفت إلى المفضّلة' : 'أُزيلت من المفضّلة'),
              duration: const Duration(milliseconds: 1400),
            ));
          }
        },
      );
    });
  }
}
