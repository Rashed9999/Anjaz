import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';

/// AMIAL-FUEL-002 — إدارة شركات الوقود + البطاقات + السداد.
class FuelCompaniesScreen extends StatefulWidget {
  const FuelCompaniesScreen({super.key});

  @override
  State<FuelCompaniesScreen> createState() => _FuelCompaniesScreenState();
}

class _FuelCompaniesScreenState extends State<FuelCompaniesScreen> {
  late final FuelStationController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCompanies());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('حسابات الشركات'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        onPressed: _addCompanyDialog,
        icon: const Icon(Icons.add),
        label: const Text('شركة جديدة'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.companies.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        if (c.companies.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(Icons.business, size: 64, color: Colors.grey.shade400),
                const SizedBox(height: 16),
                const Text('لا توجد شركات بعد',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                Text('أضِف الشركة الأولى لبدء الدفع الآجل',
                    style: TextStyle(color: Colors.grey.shade600)),
              ]),
            ),
          );
        }
        return ListView.builder(
          padding: const EdgeInsets.all(16),
          itemCount: c.companies.length,
          itemBuilder: (_, i) => _companyCard(c.companies[i]),
        );
      }),
    );
  }

  Widget _companyCard(Map<String, dynamic> company) {
    final balance = double.tryParse('${company['current_balance']}') ?? 0;
    final limit = double.tryParse('${company['credit_limit']}') ?? 0;
    final percent = limit > 0 ? (balance / limit) : 0.0;
    final isWarning = percent > 0.8;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            width: 44, height: 44,
            decoration: BoxDecoration(
              color: AmyalColors.primary.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.business, color: AmyalColors.primary),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(company['company_name'] ?? '',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            if (company['contact_person'] != null)
              Text('${company['contact_person']}',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
          ])),
        ]),
        const SizedBox(height: 10),
        // شريط الدَّيْن
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text('${balance.toStringAsFixed(0)} ر.ي',
              style: TextStyle(fontWeight: FontWeight.bold,
                  color: isWarning ? AmyalColors.red : AmyalColors.primary, fontSize: 16)),
          Text('الدَّيْن الحالي', style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
        ]),
        if (limit > 0) ...[
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: percent.clamp(0, 1),
              minHeight: 6,
              backgroundColor: Colors.grey.shade200,
              color: isWarning ? AmyalColors.red : Colors.green,
            ),
          ),
          const SizedBox(height: 2),
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('حد ${limit.toStringAsFixed(0)}',
                style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
            Text('${(percent * 100).toStringAsFixed(0)}%',
                style: TextStyle(fontSize: 10, color: Colors.grey.shade700, fontWeight: FontWeight.bold)),
          ]),
        ],
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: OutlinedButton.icon(
            onPressed: () => _showCardsSheet(company),
            icon: const Icon(Icons.credit_card, size: 16),
            label: const Text('البطاقات', style: TextStyle(fontSize: 12)),
            style: OutlinedButton.styleFrom(side: const BorderSide(color: AmyalColors.primary)),
          )),
          const SizedBox(width: 6),
          Expanded(child: FilledButton.icon(
            onPressed: balance > 0 ? () => _paymentDialog(company) : null,
            icon: const Icon(Icons.payments, size: 16),
            label: const Text('سداد', style: TextStyle(fontSize: 12)),
            style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
          )),
        ]),
      ]),
    );
  }

  void _addCompanyDialog() {
    final name = TextEditingController();
    final contact = TextEditingController();
    final phone = TextEditingController();
    final creditLimit = TextEditingController();
    final monthlyLimit = TextEditingController();

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: const Text('إضافة شركة'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: name, decoration: const InputDecoration(labelText: 'اسم الشركة *')),
          const SizedBox(height: 8),
          TextField(controller: contact, decoration: const InputDecoration(labelText: 'الشخص المسؤول')),
          const SizedBox(height: 8),
          TextField(controller: phone, keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'الهاتف')),
          const SizedBox(height: 8),
          TextField(controller: creditLimit, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'حد الائتمان', suffixText: 'ر.ي')),
          const SizedBox(height: 8),
          TextField(controller: monthlyLimit, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'الحد الشهري', suffixText: 'ر.ي')),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (name.text.isEmpty) return;
            final ok = await c.addCompany({
              'company_name': name.text.trim(),
              if (contact.text.isNotEmpty) 'contact_person': contact.text.trim(),
              if (phone.text.isNotEmpty) 'contact_phone': phone.text.trim(),
              if (creditLimit.text.isNotEmpty) 'credit_limit': creditLimit.text,
              if (monthlyLimit.text.isNotEmpty) 'monthly_limit': monthlyLimit.text,
            });
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red),
            );
            }
          },
          child: const Text('إضافة'),
        )),
      ],
    ));
  }

  void _paymentDialog(Map<String, dynamic> company) {
    final amount = TextEditingController();
    final note = TextEditingController();

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text('سداد ${company['company_name']}'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('الدَّيْن الحالي: ${company['current_balance']} ر.ي',
            style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.red)),
        const SizedBox(height: 12),
        TextField(
          controller: amount,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          autofocus: true,
          decoration: const InputDecoration(labelText: 'مبلغ السداد *', suffixText: 'ر.ي'),
        ),
        const SizedBox(height: 8),
        TextField(controller: note, decoration: const InputDecoration(labelText: 'ملاحظة (اختياري)')),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
          onPressed: c.isSubmitting.value ? null : () async {
            if (amount.text.isEmpty) return;
            final ok = await c.recordCompanyPayment(company['id'], amount.text, note: note.text);
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
              Get.snackbar('تم', 'تم تسجيل السداد',
                  backgroundColor: Colors.green.shade100, colorText: Colors.green.shade800);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red),
              );
            }
          },
          child: const Text('سداد'),
        )),
      ],
    ));
  }

  void _showCardsSheet(Map<String, dynamic> company) {
    c.loadCards(company['id']);
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: 0.7,
        maxChildSize: 0.95,
        minChildSize: 0.5,
        expand: false,
        builder: (_, scroll) => Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Expanded(child: Text('بطاقات ${company['company_name']}',
                  style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold))),
              FilledButton.icon(
                onPressed: () { Navigator.pop(context); _addCardDialog(company); },
                icon: const Icon(Icons.add, size: 16),
                label: const Text('جديدة'),
              ),
            ]),
            const SizedBox(height: 12),
            Expanded(child: Obx(() {
              if (c.cards.isEmpty) {
                return Center(
                  child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Icon(Icons.credit_card_off, size: 50, color: Colors.grey.shade400),
                    const SizedBox(height: 8),
                    Text('لا توجد بطاقات لهذه الشركة',
                        style: TextStyle(color: Colors.grey.shade600)),
                  ]),
                );
              }
              return ListView.builder(
                controller: scroll,
                itemCount: c.cards.length,
                itemBuilder: (_, i) => _cardTile(c.cards[i]),
              );
            })),
          ]),
        ),
      ),
    );
  }

  Widget _cardTile(Map<String, dynamic> card) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AmyalColors.background,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(children: [
        Container(
          width: 40, height: 40,
          decoration: BoxDecoration(
            color: card['is_active'] == true ? AmyalColors.primary : Colors.grey,
            borderRadius: BorderRadius.circular(8),
          ),
          child: const Icon(Icons.credit_card, color: Colors.white, size: 20),
        ),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('#${card['card_number']}', style: const TextStyle(fontWeight: FontWeight.bold)),
          if (card['driver_name'] != null)
            Text('السائق: ${card['driver_name']}',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          if (card['vehicle_plate'] != null)
            Text('السيارة: ${card['vehicle_plate']}',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          if ((card['daily_limit'] ?? 0).toString() != '0' || (card['monthly_limit'] ?? 0).toString() != '0')
            Text('حد يومي: ${card['daily_limit']} • شهري: ${card['monthly_limit']}',
                style: TextStyle(color: Colors.orange.shade700, fontSize: 10)),
        ])),
      ]),
    );
  }

  void _addCardDialog(Map<String, dynamic> company) {
    final number = TextEditingController();
    final label = TextEditingController();
    final plate = TextEditingController();
    final driver = TextEditingController();
    final daily = TextEditingController();
    final monthly = TextEditingController();

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text('بطاقة جديدة - ${company['company_name']}'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: number, decoration: const InputDecoration(labelText: 'رقم البطاقة *')),
          const SizedBox(height: 8),
          TextField(controller: label, decoration: const InputDecoration(labelText: 'وصف (اختياري)')),
          const SizedBox(height: 8),
          TextField(controller: plate, decoration: const InputDecoration(labelText: 'لوحة السيارة')),
          const SizedBox(height: 8),
          TextField(controller: driver, decoration: const InputDecoration(labelText: 'اسم السائق')),
          const SizedBox(height: 8),
          TextField(controller: daily, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'الحد اليومي (0 = بلا حد)', suffixText: 'ر.ي')),
          const SizedBox(height: 8),
          TextField(controller: monthly, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'الحد الشهري (0 = بلا حد)', suffixText: 'ر.ي')),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () async {
            if (number.text.isEmpty) return;
            final ok = await c.addCard(company['id'], {
              'card_number': number.text.trim(),
              if (label.text.isNotEmpty) 'card_label': label.text.trim(),
              if (plate.text.isNotEmpty) 'vehicle_plate': plate.text.trim(),
              if (driver.text.isNotEmpty) 'driver_name': driver.text.trim(),
              if (daily.text.isNotEmpty) 'daily_limit': daily.text,
              if (monthly.text.isNotEmpty) 'monthly_limit': monthly.text,
            });
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red),
            );
            }
          },
          child: const Text('إضافة'),
        ),
      ],
    ));
  }
}
