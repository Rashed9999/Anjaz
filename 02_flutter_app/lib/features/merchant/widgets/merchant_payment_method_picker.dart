import 'package:flutter/material.dart';

import 'package:amial_pay/theme/amial_colors.dart';

/// طرق الدفع التي يجب أن تتطابق في كل واجهة بيع.
///
/// لا يعني هذا أن كل قطاع يفقد وسائله الخاصة: محطة الوقود تستطيع إضافة
/// «حساب شركة»، والتجزئة تستطيع إضافة «مختلط». لكنه يمنع اختلاف معنى
/// النقد أو أميال باي أو الآجل بين شاشة وأخرى.
class MerchantPaymentOption {
  const MerchantPaymentOption({
    required this.value,
    required this.label,
    required this.description,
    required this.icon,
    this.recommended = false,
  });

  final String value;
  final String label;
  final String description;
  final IconData icon;
  final bool recommended;

  static const cash = MerchantPaymentOption(
    value: 'cash',
    label: 'نقد',
    description: 'يُسجَّل في درج النقد',
    icon: Icons.payments_outlined,
  );

  static const amialPay = MerchantPaymentOption(
    value: 'amial_pay',
    label: 'أميال باي',
    description: 'العميل يدفع عبر QR من محفظته',
    icon: Icons.qr_code_2,
    recommended: true,
  );

  static const credit = MerchantPaymentOption(
    value: 'credit',
    label: 'آجل',
    description: 'يُقيَّد ديناً على حساب العميل',
    icon: Icons.calendar_month_outlined,
  );

  static const mixed = MerchantPaymentOption(
    value: 'mixed',
    label: 'مختلط',
    description: 'جزء نقداً وجزء عبر أميال باي',
    icon: Icons.call_split,
  );

  static const companyCard = MerchantPaymentOption(
    value: 'company_card',
    label: 'حساب شركة',
    description: 'يُقيَّد ضمن رصيد الشركة وحدّها',
    icon: Icons.business_outlined,
  );
}

enum MerchantPaymentPickerLayout { cards, grid, chips }

/// واجهة اختيار مشتركة لوسائل الدفع الأساسية.
///
/// كل قطاع يحتفظ بمسار تنفيذه الحقيقي بعد الاختيار؛ هذه الأداة توحّد
/// المعنى والترتيب والنصوص فقط، فلا يصبح زر «آجل» ديناً في شاشة وخياراً
/// شكلياً في أخرى.
class MerchantPaymentMethodPicker extends StatelessWidget {
  const MerchantPaymentMethodPicker({
    super.key,
    required this.options,
    required this.selectedValue,
    required this.onChanged,
    this.layout = MerchantPaymentPickerLayout.grid,
    this.showDescriptions = true,
  });

  final List<MerchantPaymentOption> options;
  final String selectedValue;
  final ValueChanged<String>? onChanged;
  final MerchantPaymentPickerLayout layout;
  final bool showDescriptions;

  @override
  Widget build(BuildContext context) {
    if (options.isEmpty) return const SizedBox.shrink();

    return switch (layout) {
      MerchantPaymentPickerLayout.cards => Column(
          children: options.map(_card).toList(growable: false),
        ),
      MerchantPaymentPickerLayout.grid => LayoutBuilder(
          builder: (context, constraints) {
            final columns = options.length <= 2 ? options.length : 2;
            const gap = 8.0;
            final width = (constraints.maxWidth - (gap * (columns - 1))) /
                columns;
            return Wrap(
              spacing: gap,
              runSpacing: gap,
              children: options
                  .map((option) => SizedBox(width: width, child: _gridCard(option)))
                  .toList(growable: false),
            );
          },
        ),
      MerchantPaymentPickerLayout.chips => Wrap(
          spacing: 8,
          runSpacing: 8,
          children: options.map(_chip).toList(growable: false),
        ),
    };
  }

  Widget _card(MerchantPaymentOption option) {
    final selected = selectedValue == option.value;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: selected ? AmialColors.primary : Colors.white,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onChanged == null ? null : () => onChanged!(option.value),
          borderRadius: BorderRadius.circular(14),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: selected ? AmialColors.primary : AmialColors.border,
                width: selected ? 2 : 1,
              ),
            ),
            child: Row(children: [
              Icon(option.icon,
                  color: selected ? Colors.white : AmialColors.primary),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Text(option.label,
                          style: TextStyle(
                            color: selected ? Colors.white : AmialColors.textPrimary,
                            fontWeight: FontWeight.w800,
                          )),
                      if (option.recommended) ...[
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 7, vertical: 2),
                          decoration: BoxDecoration(
                            color: selected
                                ? Colors.white.withValues(alpha: .18)
                                : AmialColors.warningSurface,
                            borderRadius: BorderRadius.circular(99),
                          ),
                          child: Text('موصى به',
                              style: TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                                color: selected
                                    ? Colors.white
                                    : AmialColors.yellowDark,
                              )),
                        ),
                      ],
                    ]),
                    if (showDescriptions) ...[
                      const SizedBox(height: 2),
                      Text(option.description,
                          style: TextStyle(
                            fontSize: 12,
                            color: selected
                                ? Colors.white.withValues(alpha: .86)
                                : AmialColors.textSecondary,
                          )),
                    ],
                  ],
                ),
              ),
              Icon(
                selected
                    ? Icons.check_circle_rounded
                    : Icons.radio_button_unchecked_rounded,
                color: selected ? Colors.white : AmialColors.textMuted,
              ),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _gridCard(MerchantPaymentOption option) {
    final selected = selectedValue == option.value;
    return Material(
      color: selected ? AmialColors.primary : Colors.white,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onChanged == null ? null : () => onChanged!(option.value),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          height: showDescriptions ? 86 : 68,
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: selected ? AmialColors.primary : AmialColors.border,
              width: selected ? 2 : 1,
            ),
          ),
          child: Row(children: [
            Icon(option.icon,
                color: selected ? Colors.white : AmialColors.primary, size: 21),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(option.label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: selected ? Colors.white : AmialColors.textPrimary,
                        fontWeight: FontWeight.w800,
                      )),
                  if (showDescriptions) ...[
                    const SizedBox(height: 3),
                    Text(option.description,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 10.5,
                          height: 1.2,
                          color: selected
                              ? Colors.white.withValues(alpha: .86)
                              : AmialColors.textSecondary,
                        )),
                  ],
                ],
              ),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _chip(MerchantPaymentOption option) {
    final selected = selectedValue == option.value;
    return ChoiceChip(
      avatar: Icon(option.icon,
          size: 17, color: selected ? Colors.white : AmialColors.primary),
      label: Text(option.label),
      selected: selected,
      onSelected: onChanged == null ? null : (_) => onChanged!(option.value),
      selectedColor: AmialColors.primary,
      labelStyle: TextStyle(
        color: selected ? Colors.white : AmialColors.textPrimary,
        fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
      ),
    );
  }
}
