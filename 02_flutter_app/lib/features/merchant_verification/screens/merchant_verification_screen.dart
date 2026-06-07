import 'dart:io';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/merchant_verification/controllers/merchant_verification_controller.dart';

/// AMIAL-MERCHANT-VERIFY-001 — شاشة توثيق التاجر.
class MerchantVerificationScreen extends StatefulWidget {
  const MerchantVerificationScreen({super.key});

  @override
  State<MerchantVerificationScreen> createState() => _MerchantVerificationScreenState();
}

class _MerchantVerificationScreenState extends State<MerchantVerificationScreen> {
  late final MerchantVerificationController c;
  final _businessName = TextEditingController();
  final _crNumber = TextEditingController();
  final _category = TextEditingController();
  final _city = TextEditingController();
  final _address = TextEditingController();
  final _contactPhone = TextEditingController();

  final Map<String, File> _files = {};
  final _picker = ImagePicker();

  @override
  void initState() {
    super.initState();
    c = Get.find<MerchantVerificationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.load());
  }

  @override
  void dispose() {
    for (final ctrl in [_businessName, _crNumber, _category, _city, _address, _contactPhone]) {
      ctrl.dispose();
    }
    super.dispose();
  }

  Future<void> _pickImage(String docKey) async {
    final img = await _picker.pickImage(source: ImageSource.gallery, maxWidth: 1600, imageQuality: 85);
    if (img != null) setState(() => _files[docKey] = File(img.path));
  }

  Future<void> _captureImage(String docKey) async {
    final img = await _picker.pickImage(source: ImageSource.camera, maxWidth: 1600, imageQuality: 85);
    if (img != null) setState(() => _files[docKey] = File(img.path));
  }

  Future<void> _submit() async {
    if (_businessName.text.trim().isEmpty) {
      _snack('اسم النشاط مطلوب');
      return;
    }
    final required = ['id_card_front', 'id_card_back', 'commercial_register', 'store_photo'];
    for (final key in required) {
      if (!_files.containsKey(key)) {
        _snack('الوثيقة "${_docLabel(key)}" مطلوبة');
        return;
      }
    }

    final data = {
      'business_name': _businessName.text.trim(),
      if (_crNumber.text.isNotEmpty) 'commercial_register_number': _crNumber.text.trim(),
      if (_category.text.isNotEmpty) 'business_category': _category.text.trim(),
      if (_city.text.isNotEmpty) 'city': _city.text.trim(),
      if (_address.text.isNotEmpty) 'address': _address.text.trim(),
      if (_contactPhone.text.isNotEmpty) 'contact_phone': _contactPhone.text.trim(),
    };

    final ok = await c.submit(data: data, files: _files);
    if (!mounted) return;
    if (ok) {
      Get.snackbar('تم', 'سنراجع طلبك خلال 24-48 ساعة',
          backgroundColor: Colors.green.shade100, colorText: Colors.green.shade800,
          duration: const Duration(seconds: 4));
      setState(() {});
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل التقديم' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red));

  String _docLabel(String key) => {
    'id_card_front': 'وجه الهوية الأمامي',
    'id_card_back': 'وجه الهوية الخلفي',
    'commercial_register': 'السجل التجاري',
    'store_photo': 'صورة المحل',
    'address_proof': 'إثبات العنوان',
    'profession_license': 'رخصة المهنة',
    'optional_document': 'مستند إضافي',
  }[key] ?? key;

  Color _statusColor(String? s) => switch (s) {
    'verified' => Colors.green,
    'pending_review' => AmyalColors.yellowDark,
    'rejected' => AmyalColors.red,
    'resubmission_required' => Colors.orange,
    'verification_suspended' => Colors.grey,
    _ => Colors.blueGrey,
  };

  String _statusLabel(String? s) => switch (s) {
    'verified' => '✓ موثَّق',
    'pending_review' => 'قيد المراجعة',
    'rejected' => 'مرفوض',
    'resubmission_required' => 'يلزم إعادة رفع',
    'verification_suspended' => 'موقوف',
    'unverified' => 'غير موثَّق',
    _ => s ?? '—',
  };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('توثيق المتجر'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Obx(() {
        if (c.isLoading.value && c.status.value == null) {
          return const Center(child: CircularProgressIndicator());
        }
        final s = c.status.value;
        final profileStatus = s?['profile_status']?.toString() ?? 'unverified';
        final currentReq = s?['current_request'] as Map?;
        final adminNote = currentReq?['admin_note']?.toString();

        // إن كان verified فعلاً، أعرض رسالة فقط
        if (profileStatus == 'verified') {
          return _verifiedView(s);
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // بطاقة الحالة
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: _statusColor(profileStatus).withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _statusColor(profileStatus)),
              ),
              child: Row(children: [
                Icon(Icons.info_outline, color: _statusColor(profileStatus)),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('الحالة: ${_statusLabel(profileStatus)}',
                      style: TextStyle(color: _statusColor(profileStatus), fontWeight: FontWeight.bold)),
                  if (adminNote != null && adminNote.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text('ملاحظة الإدارة: $adminNote', style: const TextStyle(fontSize: 12)),
                  ],
                ])),
              ]),
            ),

            const SizedBox(height: 24),
            const Text('بيانات المتجر', textAlign: TextAlign.right,
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            _textField(_businessName, 'اسم النشاط *'),
            _textField(_crNumber, 'رقم السجل التجاري'),
            _textField(_category, 'نوع النشاط (مطعم، صيدلية...)'),
            _textField(_city, 'المدينة'),
            _textField(_address, 'العنوان التفصيلي', maxLines: 2),
            _textField(_contactPhone, 'رقم تواصل المالك', keyboardType: TextInputType.phone),

            const SizedBox(height: 24),
            const Text('الوثائق المطلوبة', textAlign: TextAlign.right,
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            const Text('* وثائق إلزامية للتقديم', textAlign: TextAlign.right,
                style: TextStyle(fontSize: 11, color: Colors.grey)),
            const SizedBox(height: 8),

            _docCard('id_card_front', required: true),
            _docCard('id_card_back', required: true),
            _docCard('commercial_register', required: true),
            _docCard('store_photo', required: true),
            _docCard('address_proof'),
            _docCard('profession_license'),
            _docCard('optional_document'),

            const SizedBox(height: 24),
            Obx(() => FilledButton.icon(
              onPressed: c.isSubmitting.value ? null : _submit,
              icon: c.isSubmitting.value
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.send),
              label: Text(profileStatus == 'resubmission_required' ? 'إعادة تقديم' : 'تقديم الطلب'),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(52),
              ),
            )),
            const SizedBox(height: 12),
            Text('ستراجع الإدارة طلبك خلال 24-48 ساعة عمل',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                textAlign: TextAlign.center),
          ]),
        );
      }),
    );
  }

  Widget _verifiedView(Map? s) {
    final req = s?['current_request'] as Map?;
    final tier = s?['tier']?.toString() ?? 'standard';

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Container(
            width: 100, height: 100,
            decoration: const BoxDecoration(color: Colors.green, shape: BoxShape.circle),
            child: const Icon(Icons.verified, color: Colors.white, size: 60),
          ),
          const SizedBox(height: 20),
          const Text('متجرك موثَّق', style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          if (req != null)
            Text(req['business_name']?.toString() ?? '',
                style: const TextStyle(fontSize: 16, color: AmyalColors.primary)),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            decoration: BoxDecoration(
              color: tier == 'gold' ? AmyalColors.yellow : AmyalColors.primary,
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              tier == 'gold' ? '⭐ موثَّق ذهبي' : tier == 'premium' ? 'موثَّق محسّن' : 'موثَّق',
              style: TextStyle(
                color: tier == 'gold' ? Colors.black87 : Colors.white,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _textField(TextEditingController ctrl, String label, {int maxLines = 1, TextInputType? keyboardType}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: TextField(
        controller: ctrl,
        textAlign: TextAlign.right,
        maxLines: maxLines,
        keyboardType: keyboardType,
        decoration: InputDecoration(
          labelText: label,
          filled: true,
          fillColor: Colors.white,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
        ),
      ),
    );
  }

  Widget _docCard(String key, {bool required = false}) {
    final file = _files[key];
    final has = file != null;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: has ? Colors.green : Colors.grey.shade300, width: has ? 2 : 1),
      ),
      child: Row(children: [
        // معاينة
        if (has)
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: Image.file(file, width: 50, height: 50, fit: BoxFit.cover),
          )
        else
          Container(
            width: 50, height: 50,
            decoration: BoxDecoration(color: Colors.grey.shade100, borderRadius: BorderRadius.circular(8)),
            child: const Icon(Icons.image_outlined, color: Colors.grey),
          ),
        const SizedBox(width: 12),
        // العنوان
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            if (required) const Text('*', style: TextStyle(color: AmyalColors.red, fontWeight: FontWeight.bold)),
            const SizedBox(width: 4),
            Text(_docLabel(key), style: const TextStyle(fontWeight: FontWeight.bold)),
          ]),
          if (has)
            const Text('تم الرفع', style: TextStyle(color: Colors.green, fontSize: 11))
          else
            Text(required ? 'مطلوب' : 'اختياري',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
        ])),
        const SizedBox(width: 8),
        // الأزرار
        IconButton(
          icon: const Icon(Icons.photo_library, color: AmyalColors.primary),
          onPressed: () => _pickImage(key),
          tooltip: 'من المعرض',
        ),
        IconButton(
          icon: const Icon(Icons.camera_alt, color: AmyalColors.primary),
          onPressed: () => _captureImage(key),
          tooltip: 'بالكاميرا',
        ),
      ]),
    );
  }
}
