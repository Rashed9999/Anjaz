import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/favorite_number/controllers/amial_favorites_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/helper/amial_money.dart';

/// AMIAL-FAVORITES-001 — المفضّلة بكل أنواعها في شاشة واحدة.
///
/// الشاشة القديمة كانت للأرقام وحدها ومدفونة في الملف الشخصي. صارت تعرض
/// جهات الاتصال وأرقام الحسابات والتجّار والعمليات المحفوظة مجموعةً —
/// لأن المستخدم لا يفكّر بـ«نوع» ما حفظه، بل يفتح المفضّلة ليجده.
class AmialFavoritesScreen extends StatefulWidget {
  const AmialFavoritesScreen({super.key});

  @override
  State<AmialFavoritesScreen> createState() => _AmialFavoritesScreenState();
}

class _AmialFavoritesScreenState extends State<AmialFavoritesScreen> {
  late final AmialFavoritesController _c;

  @override
  void initState() {
    super.initState();
    _c = Get.isRegistered<AmialFavoritesController>()
        ? Get.find<AmialFavoritesController>()
        : Get.put(AmialFavoritesController(), permanent: true);
    _c.loadAll();
  }

  static const _order = [
    FavKind.contact,
    FavKind.account,
    FavKind.merchant,
    FavKind.operation,
  ];

  IconData _iconFor(String kind) => switch (kind) {
        FavKind.contact => Icons.person_outline_rounded,
        FavKind.account => Icons.account_balance_wallet_outlined,
        FavKind.merchant => Icons.storefront_outlined,
        _ => Icons.receipt_long_outlined,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('المفضّلة')),
      body: Obx(() {
        if (_c.loading.value && _c.items.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }

        if (_c.items.isEmpty) {
          return _empty();
        }

        final groups = <String, List<FavoriteItem>>{};
        for (final f in _c.items) {
          groups.putIfAbsent(f.kind, () => []).add(f);
        }

        final sections = _order.where(groups.containsKey).toList();

        return RefreshIndicator(
          color: AmialColors.primary,
          onRefresh: _c.loadAll,
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
            itemCount: sections.length,
            itemBuilder: (_, i) {
              final kind = sections[i];
              final list = groups[kind]!;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
                    child: Text(
                      '${FavKind.labels[kind] ?? kind}  (${list.length})',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                          color: AmialColors.textSecondary),
                    ),
                  ),
                  ...list.map((f) => _tile(kind, f)),
                ],
              );
            },
          ),
        );
      }),
    );
  }

  Widget _tile(String kind, FavoriteItem f) {
    // العملية المحفوظة تحمل مبلغها ونوعها في metadata — عرضهما هو ما
    // يجعلها مفيدة: «إيجار 50,000» لا رقماً من 12 خانة.
    final amount = f.metadata['amount'];
    final subtitle = <String>[
      if (f.metadata['type_label'] != null) '${f.metadata['type_label']}',
      if (amount != null) '${AmialMoney.fmt(amount.toString())} ر.ي',
      if (kind != FavKind.operation) f.value,
    ].where((s) => s.isNotEmpty).join(' • ');

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.border),
      ),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AmialColors.background,
          child: Icon(_iconFor(kind), color: AmialColors.primary, size: 20),
        ),
        title: Text(f.label,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: subtitle.isEmpty
            ? null
            : Text(subtitle,
                style: const TextStyle(
                    fontSize: 12, color: AmialColors.textSecondary)),
        trailing: IconButton(
          icon: const Icon(Icons.star_rounded, color: AmialColors.yellowDark),
          tooltip: 'إزالة من المفضّلة',
          onPressed: () => _c.remove(f.id),
        ),
      ),
    );
  }

  Widget _empty() => ListView(
        padding: const EdgeInsets.fromLTRB(28, 90, 28, 28),
        children: const [
          Icon(Icons.star_border_rounded,
              size: 64, color: AmialColors.textMuted),
          SizedBox(height: 14),
          Text('لا مفضّلة بعد',
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AmialColors.textPrimary)),
          SizedBox(height: 8),
          Text(
            'اضغط ⭐ بجانب أي رقم أو حساب أو تاجر أو عملية لتجدها هنا بسرعة '
            'في المرّة القادمة.',
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 13, height: 1.8, color: AmialColors.textSecondary),
          ),
        ],
      );
}
