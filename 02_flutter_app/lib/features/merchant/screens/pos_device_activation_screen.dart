import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/pos_device_identity.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// شاشة جهاز الكاشير فقط: لا تسجّل موظفاً ولا تدخل بحساب التاجر.
class PosDeviceActivationScreen extends StatefulWidget {
  const PosDeviceActivationScreen({super.key});

  @override
  State<PosDeviceActivationScreen> createState() => _PosDeviceActivationScreenState();
}

class _PosDeviceActivationScreenState extends State<PosDeviceActivationScreen> {
  final _code = TextEditingController();
  final _api = Get.find<ApiClient>();
  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _activate() async {
    final code = _code.text.replaceAll(RegExp(r'\s'), '');
    if (!RegExp(r'^\d{8}$').hasMatch(code)) {
      setState(() => _error = 'أدخل رمز التفعيل المكوّن من 8 أرقام.');
      return;
    }
    setState(() { _loading = true; _error = null; });
    final uuid = await PosDeviceIdentity.get();
    if (uuid == null) {
      if (mounted) setState(() { _loading = false; _error = 'تعذّر إنشاء هوية آمنة لهذا الجهاز.'; });
      return;
    }
    try {
      final r = await _api.postData('/api/v1/amial/pos-devices/activate', {
        'activation_code': code,
        'device_uuid': uuid,
        'platform': Theme.of(context).platform.name,
      });
      if (!mounted) return;
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('تم تفعيل الجهاز'),
            content: const Text('يمكن الآن لأي موظف مخوّل تسجيل الدخول بحسابه على هذا الجهاز.'),
            actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('متابعة'))],
          ),
        );
        if (mounted) Navigator.pop(context);
      } else {
        setState(() => _error = r.body is Map
            ? (r.body['message']?.toString() ?? 'تعذّر تفعيل الجهاز.')
            : 'تعذّر تفعيل الجهاز.');
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذّر الاتصال بالخادم.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(title: const Text('تفعيل جهاز نقطة البيع')),
    body: Center(child: SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Card(child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.point_of_sale_outlined, size: 54, color: AmialColors.primary),
          const SizedBox(height: 14),
          const Text('فعّل هذا الجهاز', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          const Text('من حساب التاجر: أجهزة نقاط البيع ← إضافة جهاز ← إنشاء رمز تفعيل. لا تستخدم حساب الموظف أو كلمة مروره هنا.', textAlign: TextAlign.center),
          const SizedBox(height: 18),
          TextField(
            controller: _code,
            keyboardType: TextInputType.number,
            maxLength: 8,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 24, letterSpacing: 5, fontWeight: FontWeight.bold),
            decoration: const InputDecoration(labelText: 'رمز التفعيل', border: OutlineInputBorder()),
          ),
          if (_error != null) Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: AmialColors.red)),
          ),
          const SizedBox(height: 16),
          SizedBox(width: double.infinity, child: FilledButton(
            onPressed: _loading ? null : _activate,
            child: Text(_loading ? 'جارٍ التفعيل…' : 'تفعيل الجهاز'),
          )),
        ]),
      )),
    )),
  );
}
