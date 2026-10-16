import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// قناة الدعم الحقيقية. تقرأ من إعداد الدعم المخصص، لا من بيانات شركة
/// قديمة أو فارغة في config، وتعرض سبباً واضحاً إن تعذر فتح تطبيق الاتصال.
class SupportScreen extends StatefulWidget {
  const SupportScreen({super.key});

  @override
  State<SupportScreen> createState() => _SupportScreenState();
}

class _SupportScreenState extends State<SupportScreen> {
  String _phone = '';
  String _whatsapp = '';
  String _email = '';
  bool _loading = true;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _loadContact();
  }

  Future<void> _loadContact() async {
    setState(() {
      _loading = true;
      _failed = false;
    });

    final config = Get.find<SplashController>().configModel;
    var phone = (config?.companyPhone ?? '').trim();
    var email = (config?.companyEmail ?? '').trim();
    var whatsapp = '';

    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/support-contact');
      final body = response.body;
      final meta = body is Map ? body['meta'] : null;
      final contact = meta is Map ? meta['contact'] : null;
      if (response.statusCode == 200 && contact is Map) {
        final serverPhone = contact['phone_number']?.toString().trim() ?? '';
        final serverEmail = contact['support_email']?.toString().trim() ?? '';
        phone = serverPhone.isEmpty ? phone : serverPhone;
        email = serverEmail.isEmpty ? email : serverEmail;
        whatsapp = contact['whatsapp_number']?.toString().trim() ?? '';
      } else {
        _failed = true;
      }
    } catch (_) {
      _failed = true;
    }

    if (!mounted) return;
    setState(() {
      _phone = phone;
      _email = email;
      _whatsapp = whatsapp;
      _loading = false;
    });
  }

  Future<void> _open(Uri uri, String label) async {
    final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (opened || !mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('تعذر فتح $label على هذا الجهاز'),
        backgroundColor: AmialColors.red,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('الدعم والمساعدة')),
      body: RefreshIndicator(
        onRefresh: _loadContact,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            const SizedBox(height: 12),
            Center(
              child: Container(
                width: 88,
                height: 88,
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.08),
                  shape: BoxShape.circle,
                ),
                child: const Icon(
                  Icons.support_agent_rounded,
                  size: 44,
                  color: AmialColors.primary,
                ),
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'كيف يمكننا مساعدتك؟',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            const Text(
              'اختر وسيلة التواصل المناسبة. لا تُعرض أي وسيلة غير قابلة للاستخدام.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AmialColors.textSecondary),
            ),
            const SizedBox(height: 28),
            if (_loading)
              const Center(child: CircularProgressIndicator())
            else ...[
              if (_whatsapp.isNotEmpty)
                _contactCard(
                  icon: Icons.chat_rounded,
                  label: 'واتساب الدعم',
                  value: _whatsapp,
                  filled: true,
                  onTap: () => _open(
                    Uri.parse(
                      'https://wa.me/' +
                          _whatsapp.replaceAll(RegExp(r'[^0-9]'), ''),
                    ),
                    'واتساب',
                  ),
                ),
              if (_whatsapp.isNotEmpty) const SizedBox(height: 12),
              if (_phone.isNotEmpty)
                _contactCard(
                  icon: Icons.phone_rounded,
                  label: 'اتصال بالدعم',
                  value: _phone,
                  filled: _whatsapp.isEmpty,
                  onTap: () => _open(
                    Uri(scheme: 'tel', path: _phone),
                    'تطبيق الاتصال',
                  ),
                ),
              if (_phone.isNotEmpty && _email.isNotEmpty)
                const SizedBox(height: 12),
              if (_email.isNotEmpty)
                _contactCard(
                  icon: Icons.email_rounded,
                  label: 'بريد الدعم',
                  value: _email,
                  filled: false,
                  onTap: () => _open(
                    Uri(scheme: 'mailto', path: _email),
                    'البريد',
                  ),
                ),
              if (_phone.isEmpty && _email.isEmpty && _whatsapp.isEmpty)
                _emptyContact(),
              if (_failed) ...[
                const SizedBox(height: 18),
                TextButton.icon(
                  onPressed: _loadContact,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة المحاولة'),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }

  Widget _emptyContact() => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: const Text(
          'بيانات الدعم غير متاحة حالياً. أعد المحاولة لاحقاً.',
          textAlign: TextAlign.center,
          style: TextStyle(color: AmialColors.textSecondary),
        ),
      );

  Widget _contactCard({
    required IconData icon,
    required String label,
    required String value,
    required bool filled,
    required Future<void> Function() onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: filled ? AmialColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: filled ? AmialColors.primary : AmialColors.border,
          ),
        ),
        child: Row(children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: filled
                  ? Colors.white.withValues(alpha: 0.16)
                  : AmialColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: filled ? Colors.white : AmialColors.primary),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: filled ? Colors.white : AmialColors.textPrimary,
                  ),
                ),
                Text(
                  value,
                  textDirection: TextDirection.ltr,
                  style: TextStyle(
                    fontSize: 12,
                    color: filled ? Colors.white70 : AmialColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
          Icon(
            Icons.chevron_left_rounded,
            color: filled ? Colors.white70 : AmialColors.textMuted,
          ),
        ]),
      ),
    );
  }
}
