import 'package:flutter/material.dart';
import 'package:get/get_utils/src/extensions/internacionalization.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-EMPTY-STATE-001 — الحالة الفارغة الموحّدة.
///
/// كانت صورة PNG قديمة (مجلّد رمادي عليه ×) موروثة من القالب الأصلي: لونها
/// خارج هوية البراند وتوحي بالعطل لا بالفراغ. صارت شارة مبنيّة بالكود بألوان
/// أميال، مع عنوان ووصف اختياري وزرّ إجراء اختياري.
class NoDataFoundWidget extends StatelessWidget {
  final bool? fromHome;
  final String? title;

  /// سطر توضيحي أسفل العنوان (اختياري).
  final String? subtitle;

  /// أيقونة الحالة — الافتراضي «صندوق فارغ» لا علامة خطأ.
  final IconData icon;

  /// زرّ إجراء اختياري (مثلاً: «حدّث» أو «ابدأ أول عملية»).
  final String? actionLabel;
  final VoidCallback? onAction;

  const NoDataFoundWidget({
    super.key,
    this.fromHome = false,
    this.title,
    this.subtitle,
    this.icon = Icons.inbox_rounded,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return fromHome!
        ? noDataWidget(context)
        : SizedBox(
            height: MediaQuery.of(context).size.height * 0.6,
            child: noDataWidget(context));
  }

  Widget noDataWidget(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 24),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            // شارة دائرية بلون البراند الخفيف
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                color: AmyalColors.primary.withValues(alpha: 0.07),
                shape: BoxShape.circle,
              ),
              child: Icon(icon,
                  size: 42, color: AmyalColors.primary.withValues(alpha: 0.55)),
            ),
            const SizedBox(height: Dimensions.paddingSizeLarge),
            Text(
              title ?? 'no_data_found'.tr,
              textAlign: TextAlign.center,
              style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeLarge,
                color: const Color(0xFF1A2433),
              ),
            ),
            if (subtitle != null) ...[
              const SizedBox(height: Dimensions.paddingSizeExtraSmall),
              Text(
                subtitle!,
                textAlign: TextAlign.center,
                style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeDefault,
                  color: AmyalColors.textSecondary,
                  height: 1.5,
                ),
              ),
            ],
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: Dimensions.paddingSizeLarge),
              OutlinedButton(
                onPressed: onAction,
                style: OutlinedButton.styleFrom(
                  foregroundColor: AmyalColors.primary,
                  side: const BorderSide(color: AmyalColors.primary, width: 1.3),
                  padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 12),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
                child: Text(actionLabel!,
                    style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeDefault)),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
