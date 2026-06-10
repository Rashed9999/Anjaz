import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/admin/controllers/settings_center_controller.dart';
import 'package:amyal_pay/features/admin/screens/whatsapp_settings_screen.dart';

/// AMIAL-SETTINGS-CENTER-001 — مركز الإعدادات الموحّد (للأدمن غير المبرمج).
///
/// كل ما كان يتطلّب الدخول للباكند صار هنا بأزرار:
///   واتساب (شاشة مخصّصة) · مزوّدو SMS · إشعارات واتساب · بيانات التواصل · الرسوم.
class SettingsCenterScreen extends StatefulWidget {
  const SettingsCenterScreen({super.key});

  @override
  State<SettingsCenterScreen> createState() => _SettingsCenterScreenState();
}

class _SettingsCenterScreenState extends State<SettingsCenterScreen> {
  late final SettingsCenterController c;

  static const Map<String, String> _feeCodeLabels = {
    'SEND_MONEY': 'تحويل أموال', 'CASH_OUT': 'سحب', 'CASH_IN': 'إيداع',
    'MERCHANT_QR': 'دفع QR للتاجر', 'MERCHANT_POS': 'دفع POS',
    'SAFE_PAYMENT': 'دفع آمن', 'BILL_PAY': 'فواتير', 'SPLIT_BILL': 'تقسيم فاتورة',
    'REFUND': 'مرتجع', 'FAMILY_FUND_CONTRIB': 'صندوق عائلي',
  };

  @override
  void initState() {
    super.initState();
    c = Get.find<SettingsCenterController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAll());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('مركز الإعدادات'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Obx(() {
        if (c.isLoading.value && c.smsProviders.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        return RefreshIndicator(
          onRefresh: c.loadAll,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _navTile(Icons.chat, 'إعدادات واتساب', 'مزوّدو OTP + ترتيب القناة + إرسال تجريبي',
                  () => Get.to(() => const WhatsappSettingsScreen())),
              const SizedBox(height: 16),
              _sectionTitle('مزوّدو رسائل SMS'),
              ...c.smsProviders.map(_smsCard),
              const SizedBox(height: 16),
              _sectionTitle('إشعارات واتساب'),
              _notificationsCard(),
              const SizedBox(height: 16),
              _sectionTitle('بيانات التواصل والدعم'),
              _contactCard(),
              const SizedBox(height: 16),
              _sectionTitle('الرسوم ونسب الأرباح'),
              _feesCard(),
              const SizedBox(height: 24),
            ],
          ),
        );
      }),
    );
  }

  Widget _sectionTitle(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(t, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
      );

  Widget _navTile(IconData icon, String title, String subtitle, VoidCallback onTap) {
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AmyalColors.yellow.withValues(alpha: 0.25),
          child: Icon(icon, color: AmyalColors.primary),
        ),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text(subtitle, style: const TextStyle(fontSize: 12)),
        trailing: const Icon(Icons.chevron_left),
        onTap: onTap,
      ),
    );
  }

  // ---------------- SMS ----------------

  Widget _smsCard(Map<String, dynamic> p) {
    final provider = p['provider'].toString();
    final enabled = p['enabled'] == true;
    final fields = ((p['fields'] ?? []) as List).map((e) => e.toString()).toList();
    final config = Map<String, dynamic>.from((p['config'] ?? {}) as Map);
    final controllers = {for (final f in fields) f: TextEditingController(text: (config[f] ?? '').toString())};
    final enabledRx = enabled.obs;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ExpansionTile(
        leading: Icon(Icons.sms, color: enabled ? Colors.green : Colors.grey),
        title: Text(provider, style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text(enabled ? 'مُفعّل' : 'معطّل',
            style: TextStyle(color: enabled ? Colors.green : Colors.grey, fontSize: 12)),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        children: [
          Obx(() => SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('تفعيل المزوّد'),
                value: enabledRx.value,
                onChanged: (v) => enabledRx.value = v,
              )),
          ...fields.map((f) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: TextField(
                  controller: controllers[f],
                  decoration: InputDecoration(labelText: f, border: const OutlineInputBorder()),
                ),
              )),
          Obx(() => SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
                  onPressed: c.isSubmitting.value
                      ? null
                      : () async {
                          final cfg = {for (final f in fields) f: controllers[f]!.text.trim()};
                          final ok = await c.saveSms(provider, enabledRx.value, cfg);
                          _snack(ok ? 'تم الحفظ' : c.lastError.value, ok);
                        },
                  icon: const Icon(Icons.save),
                  label: const Text('حفظ'),
                ),
              )),
        ],
      ),
    );
  }

  // ---------------- إشعارات واتساب ----------------

  Widget _notificationsCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Obx(() => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('إرسال نسخة واتساب من الإشعارات'),
                subtitle: const Text('إيصالات وتنبيهات للمستخدمين عبر واتساب', style: TextStyle(fontSize: 12)),
                value: c.notifEnabled.value,
                onChanged: (v) => c.notifEnabled.value = v,
              ),
              if (c.notifEnabled.value) ...[
                const Text('الأنواع (فارغ = الكل):', style: TextStyle(fontSize: 13)),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: c.knownTypes
                      .map((t) => FilterChip(
                            label: Text(t, style: const TextStyle(fontSize: 11)),
                            selected: c.notifTypes.contains(t),
                            onSelected: (sel) =>
                                sel ? c.notifTypes.add(t) : c.notifTypes.remove(t),
                          ))
                      .toList(),
                ),
              ],
              const SizedBox(height: 8),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
                  onPressed: c.isSubmitting.value
                      ? null
                      : () async {
                          final ok = await c.saveNotifications();
                          _snack(ok ? 'تم الحفظ' : c.lastError.value, ok);
                        },
                  icon: const Icon(Icons.save),
                  label: const Text('حفظ'),
                ),
              ),
            ])),
      ),
    );
  }

  // ---------------- بيانات التواصل ----------------

  Widget _contactCard() {
    final wa = TextEditingController(text: c.contact['whatsapp_number'] ?? '');
    final ph = TextEditingController(text: c.contact['phone_number'] ?? '');
    final em = TextEditingController(text: c.contact['support_email'] ?? '');

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(children: [
          TextField(controller: wa, decoration: const InputDecoration(
              labelText: 'رقم واتساب الدعم (بدون +)', border: OutlineInputBorder())),
          const SizedBox(height: 8),
          TextField(controller: ph, decoration: const InputDecoration(
              labelText: 'هاتف الدعم', border: OutlineInputBorder())),
          const SizedBox(height: 8),
          TextField(controller: em, decoration: const InputDecoration(
              labelText: 'البريد الإلكتروني', border: OutlineInputBorder())),
          const SizedBox(height: 8),
          Obx(() => SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
                  onPressed: c.isSubmitting.value
                      ? null
                      : () async {
                          final ok = await c.saveContact({
                            'whatsapp_number': wa.text.trim(),
                            'phone_number': ph.text.trim(),
                            'support_email': em.text.trim(),
                          });
                          _snack(ok ? 'تم الحفظ' : c.lastError.value, ok);
                        },
                  icon: const Icon(Icons.save),
                  label: const Text('حفظ'),
                ),
              )),
        ]),
      ),
    );
  }

  // ---------------- الرسوم ----------------

  Widget _feesCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Obx(() => c.feeSchemes.isEmpty
              ? const Padding(
                  padding: EdgeInsets.all(8),
                  child: Text('لا توجد رسوم مفعّلة — كل العمليات مجانية حالياً',
                      style: TextStyle(color: AmyalColors.textSecondary, fontSize: 13)))
              : Column(children: c.feeSchemes.map(_feeTile).toList())),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
              onPressed: _showCreateFeeDialog,
              icon: const Icon(Icons.add),
              label: const Text('رسم جديد / تعديل نسبة'),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _feeTile(Map<String, dynamic> s) {
    final code = s['code'].toString();
    final label = _feeCodeLabels[code] ?? code;
    final type = s['fee_type'].toString();
    final desc = type == 'fixed'
        ? '${s['fixed_amount']} ثابت'
        : type == 'percent'
            ? '${s['percent_rate']}%'
            : '${s['percent_rate']}% + ${s['fixed_amount']}';

    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      title: Text('$label  (v${s['version']})', style: const TextStyle(fontWeight: FontWeight.bold)),
      subtitle: Text('الرسم: $desc — يتحمّله: ${s['bearer']}', style: const TextStyle(fontSize: 12)),
      trailing: IconButton(
        icon: const Icon(Icons.pause_circle, color: AmyalColors.red),
        tooltip: 'تعطيل (تصبح العملية مجانية)',
        onPressed: () async {
          final ok = await c.deactivateFee(s['id'] as int);
          _snack(ok ? 'تم التعطيل' : c.lastError.value, ok);
        },
      ),
    );
  }

  void _showCreateFeeDialog() {
    String code = c.feeCodes.isNotEmpty ? c.feeCodes.first : 'SEND_MONEY';
    String feeType = 'percent';
    String bearer = 'sender';
    final percent = TextEditingController(text: '0');
    final fixed = TextEditingController(text: '0');
    final agentPct = TextEditingController(text: '0');
    final simAmount = TextEditingController(text: '100');
    final simResult = RxnString();

    Map<String, dynamic> scheme() => {
          'code': code,
          'fee_type': feeType,
          'percent_rate': percent.text.trim(),
          'fixed_amount': fixed.text.trim(),
          'agent_commission_percent': agentPct.text.trim(),
          'bearer': bearer,
        };

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSt) => AlertDialog(
          title: const Text('رسم جديد'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<String>(
                initialValue: code,
                decoration: const InputDecoration(labelText: 'العملية'),
                items: c.feeCodes
                    .map((x) => DropdownMenuItem(value: x, child: Text(_feeCodeLabels[x] ?? x)))
                    .toList(),
                onChanged: (v) => setSt(() => code = v ?? code),
              ),
              DropdownButtonFormField<String>(
                initialValue: feeType,
                decoration: const InputDecoration(labelText: 'نوع الرسم'),
                items: const [
                  DropdownMenuItem(value: 'percent', child: Text('نسبة %')),
                  DropdownMenuItem(value: 'fixed', child: Text('مبلغ ثابت')),
                  DropdownMenuItem(value: 'percent_plus_fixed', child: Text('نسبة + ثابت')),
                ],
                onChanged: (v) => setSt(() => feeType = v ?? feeType),
              ),
              if (feeType != 'fixed')
                TextField(controller: percent, keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'النسبة %')),
              if (feeType != 'percent')
                TextField(controller: fixed, keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'المبلغ الثابت')),
              TextField(controller: agentPct, keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'حصة الوكيل % من الرسم')),
              DropdownButtonFormField<String>(
                initialValue: bearer,
                decoration: const InputDecoration(labelText: 'من يتحمّل الرسم'),
                items: const [
                  DropdownMenuItem(value: 'sender', child: Text('المرسِل')),
                  DropdownMenuItem(value: 'receiver', child: Text('المستلِم')),
                  DropdownMenuItem(value: 'merchant', child: Text('التاجر')),
                ],
                onChanged: (v) => setSt(() => bearer = v ?? bearer),
              ),
              const Divider(height: 24),
              Row(children: [
                Expanded(
                  child: TextField(controller: simAmount, keyboardType: TextInputType.number,
                      decoration: const InputDecoration(labelText: 'جرّب على مبلغ')),
                ),
                const SizedBox(width: 8),
                OutlinedButton(
                  onPressed: () async {
                    final r = await c.simulateFee(scheme(), simAmount.text.trim());
                    simResult.value = r == null
                        ? (c.lastError.value)
                        : 'الرسم: ${r['fee']} — يدفع: ${r['total_debit']} — يصل: ${r['net_credit']}';
                    setSt(() {});
                  },
                  child: const Text('محاكاة'),
                ),
              ]),
              Obx(() => simResult.value == null
                  ? const SizedBox.shrink()
                  : Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(simResult.value!,
                          style: const TextStyle(fontSize: 12, color: AmyalColors.primary)),
                    )),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
              onPressed: () async {
                final ok = await c.createFee(scheme());
                if (ctx.mounted) Navigator.pop(ctx);
                _snack(ok ? 'تم حفظ الرسم' : c.lastError.value, ok);
              },
              child: const Text('حفظ'),
            ),
          ],
        ),
      ),
    );
  }

  void _snack(String msg, bool ok) {
    if (!mounted || msg.isEmpty) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: ok ? Colors.green : AmyalColors.red,
    ));
  }
}
