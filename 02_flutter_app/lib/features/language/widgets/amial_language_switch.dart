import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/language/controllers/localization_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-I18N-002 — مبدّل اللغة المُصغّر.
///
/// كانت اللغة تُختار من **صفحة كاملة** (شعار 120 بكسل + شبكة 2×2 + زرّ تأكيد)
/// من أجل خيارين اثنين فقط: العربية والإنجليزية. هذا ليس تصميماً محترفاً —
/// المحترف زرّ صغير يفتح قائمة قصيرة وتتبدّل اللغة فوراً في مكانها.
///
/// الاستخدام:
///   • `AmialLanguageChip()` — زرّ مضغوط (لشاشة الدخول: على خلفية داكنة).
///   • `AmialLanguageSheet.open()` — لفتح القائمة من أي مكان (صفّ الإعدادات).
class AmialLanguageSheet {
  AmialLanguageSheet._();

  /// يفتح قائمة اللغات ويطبّق الاختيار فوراً بلا تنقّل ولا زرّ تأكيد.
  static Future<void> open(BuildContext context) {
    final c = Get.find<LocalizationController>();
    final current = c.locale.languageCode;

    return showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (ctx) => SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 10),
            Container(
              width: 42,
              height: 4,
              decoration: BoxDecoration(
                color: AmialColors.border,
                borderRadius: BorderRadius.circular(4),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              'select_a_language'.tr,
              style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433)),
            ),
            const SizedBox(height: 12),
            ...AppConstants.languages.map((lang) {
              final selected = lang.languageCode == current;
              return ListTile(
                onTap: () {
                  c.setLanguage(
                      Locale(lang.languageCode!, lang.countryCode));
                  c.setSelectIndex(
                      AppConstants.languages.indexOf(lang));
                  Navigator.of(ctx).pop();
                },
                leading: Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: AmialColors.primary
                        .withValues(alpha: selected ? 0.14 : 0.06),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    lang.languageCode!.toUpperCase(),
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                      color: selected
                          ? AmialColors.primary
                          : AmialColors.textMuted,
                    ),
                  ),
                ),
                title: Text(
                  lang.languageName ?? '',
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                    color: selected
                        ? AmialColors.primary
                        : const Color(0xFF1A2433),
                  ),
                ),
                trailing: selected
                    ? const Icon(Icons.check_circle_rounded,
                        color: AmialColors.primary, size: 22)
                    : null,
              );
            }),
            const SizedBox(height: 10),
          ],
        ),
      ),
    );
  }
}

/// زرّ لغة مضغوط: «العربية ▾». `onDark` لاستعماله فوق خلفية داكنة.
class AmialLanguageChip extends StatelessWidget {
  final bool onDark;
  const AmialLanguageChip({super.key, this.onDark = false});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<LocalizationController>(builder: (c) {
      final name = AppConstants.languages
          .firstWhere(
            (l) => l.languageCode == c.locale.languageCode,
            orElse: () => AppConstants.languages.first,
          )
          .languageName;

      final fg = onDark ? Colors.white : AmialColors.primary;

      return InkWell(
        onTap: () => AmialLanguageSheet.open(context),
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
          decoration: BoxDecoration(
            color: onDark
                ? Colors.white.withValues(alpha: 0.15)
                : AmialColors.primary.withValues(alpha: 0.07),
            borderRadius: BorderRadius.circular(20),
            border: Border.all(
                color: onDark
                    ? Colors.white.withValues(alpha: 0.28)
                    : AmialColors.primary.withValues(alpha: 0.22)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.translate_rounded, size: 15, color: fg),
              const SizedBox(width: 6),
              Text(name ?? '',
                  style: TextStyle(
                      fontSize: 12.5, fontWeight: FontWeight.w600, color: fg)),
              const SizedBox(width: 2),
              Icon(Icons.keyboard_arrow_down_rounded, size: 17, color: fg),
            ],
          ),
        ),
      );
    });
  }
}
