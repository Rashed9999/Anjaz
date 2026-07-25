import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/admin/controllers/whatsapp_settings_controller.dart';

/// AMIAL-WHATSAPP-OTP-001 — شاشة إعدادات قناة واتساب (لوحة الأدمن).
///
/// تتيح: تفعيل/إعداد كل مزوّد، ضبط ترتيب القناة (واتساب أولاً/SMS أولاً…)،
/// وإرسال رسالة تجريبية للتحقّق. الأسرار تظهر مُقنّعة ولا تُكتب فوقها إلا بقيمة جديدة.
class WhatsappSettingsScreen extends StatefulWidget {
  const WhatsappSettingsScreen({super.key});

  @override
  State<WhatsappSettingsScreen> createState() => _WhatsappSettingsScreenState();
}

class _WhatsappSettingsScreenState extends State<WhatsappSettingsScreen> {
  late final WhatsappSettingsController c;

  // الحقول المعروضة لكل مزوّد
  static const Map<String, List<String>> _fields = {
    'meta_cloud': ['access_token', 'phone_number_id', 'template_name', 'lang_code'],
    'twilio': ['sid', 'token', 'from', 'otp_template'],
    '360dialog': ['api_key', 'template_name', 'lang_code'],
    'wati': ['api_endpoint', 'access_token', 'template_name', 'broadcast_name'],
    'ultramsg': ['instance_id', 'token', 'otp_template'],
  };

  static const Map<String, String> _channelLabels = {
    'whatsapp_first': 'واتساب أولاً ثم SMS',
    'sms_first': 'SMS أولاً ثم واتساب',
    'whatsapp_only': 'واتساب فقط',
    'sms_only': 'SMS فقط',
  };

  final _testPhone = TextEditingController();
  final _testMsg = TextEditingController();

  @override
  void initState() {
    super.initState();
    c = Get.find<WhatsappSettingsController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.load());
  }

  @override
  void dispose() {
    _testPhone.dispose();
    _testMsg.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('إعدادات واتساب'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.providers.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        return RefreshIndicator(
          onRefresh: c.load,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _channelCard(),
              const SizedBox(height: 16),
              const Text('المزوّدون', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              ...c.providers.map(_providerCard),
              const SizedBox(height: 16),
              _testCard(),
            ],
          ),
        );
      }),
    );
  }

  Widget _channelCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('ترتيب القناة', style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          Obx(() => DropdownButton<String>(
                isExpanded: true,
                value: c.channels.contains(c.channelPreference.value) ? c.channelPreference.value : null,
                items: c.channels
                    .map((v) => DropdownMenuItem(value: v, child: Text(_channelLabels[v] ?? v)))
                    .toList(),
                onChanged: c.isSubmitting.value
                    ? null
                    : (v) async {
                        if (v == null) return;
                        final ok = await c.setChannel(v);
                        _snack(ok ? 'تم ضبط القناة' : c.lastError.value, ok);
                      },
              )),
        ]),
      ),
    );
  }

  Widget _providerCard(Map<String, dynamic> p) {
    final provider = p['provider'].toString();
    final enabled = p['enabled'] == true;
    final config = Map<String, dynamic>.from((p['config'] ?? {}) as Map);
    final fields = _fields[provider] ?? config.keys.toList();
    final controllers = {for (final f in fields) f: TextEditingController(text: (config[f] ?? '').toString())};
    final enabledRx = enabled.obs;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ExpansionTile(
        leading: Icon(Icons.chat, color: enabled ? Colors.green : Colors.grey),
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
          const SizedBox(height: 8),
          Obx(() => SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
                  onPressed: c.isSubmitting.value
                      ? null
                      : () async {
                          final cfg = {for (final f in fields) f: controllers[f]!.text.trim()};
                          final ok = await c.saveProvider(provider, enabledRx.value, cfg);
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

  Widget _testCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('إرسال تجريبي', style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          TextField(
            controller: _testPhone,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'رقم الهاتف (+967...)', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _testMsg,
            decoration: const InputDecoration(
                labelText: 'رسالة (اختياري — فارغ = OTP تجريبي)', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 8),
          Obx(() => SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: c.isSubmitting.value
                      ? null
                      : () async {
                          if (_testPhone.text.trim().isEmpty) {
                            _snack('أدخل رقم الهاتف', false);
                            return;
                          }
                          final ok = await c.testSend(_testPhone.text.trim(),
                              message: _testMsg.text.trim().isEmpty ? null : _testMsg.text.trim());
                          _snack(ok ? (c.lastMessage.value) : c.lastError.value, ok);
                        },
                  icon: const Icon(Icons.send),
                  label: const Text('إرسال تجريبي'),
                ),
              )),
        ]),
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
