import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:amyal_pay/features/safe_payment/domain/repositories/safe_payment_repo.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SAFEPAY-EVIDENCE-001 — رفع الأدلّة.
///
/// ورقة واحدة يستعملها الطرفان: البائع لإثبات الشحن والتسليم، والمشتري
/// لإسناد دعواه. توحيدها يجعل تجربة الإثبات واحدة — ومن رأى النافذة مرّة
/// عرفها في كل مرحلة.
///
/// **قرارات مقصودة في الواجهة:**
///   - المعاينة قبل الرفع: من يرى ما اختاره لا يرفع صورة خاطئة ثم يكتشف
///     أنه لا يستطيع حذفها (الأدلّة لا تُحذف بعد الرفع — عمداً).
///   - تحذير صريح بعدم إمكان الحذف قبل الضغط: أن يُفاجأ المستخدم بذلك
///     بعد الرفع خيانةٌ لتوقّعه.
///   - الحدّ خمسة معروض كعدّاد لا كخطأ بعد المحاولة.
class EvidencePickerSheet extends StatefulWidget {
  const EvidencePickerSheet({
    super.key,
    required this.ulid,
    required this.stage,
    required this.title,
    required this.hint,
  });

  final String ulid;
  final String stage;
  final String title;
  final String hint;

  /// يفتح الورقة ويعيد `true` إن رُفع شيء.
  static Future<bool> open(
    BuildContext context, {
    required String ulid,
    required String stage,
    required String title,
    required String hint,
  }) async {
    final result = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => EvidencePickerSheet(
        ulid: ulid, stage: stage, title: title, hint: hint,
      ),
    );

    return result ?? false;
  }

  @override
  State<EvidencePickerSheet> createState() => _EvidencePickerSheetState();
}

class _EvidencePickerSheetState extends State<EvidencePickerSheet> {
  static const _max = 5;

  final List<File> _files = [];
  final _note = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _pick({required bool camera}) async {
    if (_files.length >= _max) return;

    try {
      final picker = ImagePicker();
      if (camera) {
        final shot = await picker.pickImage(
          source: ImageSource.camera, imageQuality: 82, maxWidth: 1920);
        if (shot != null) setState(() => _files.add(File(shot.path)));
        return;
      }

      final picked = await picker.pickMultiImage(imageQuality: 82, maxWidth: 1920);
      if (picked.isEmpty) return;

      setState(() {
        for (final x in picked) {
          if (_files.length < _max) _files.add(File(x.path));
        }
      });
    } catch (_) {
      setState(() => _error = 'تعذّر فتح الصور. تأكّد من الأذونات.');
    }
  }

  Future<void> _submit() async {
    if (_files.isEmpty) {
      setState(() => _error = 'اختر صورة واحدة على الأقل');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final r = await Get.find<SafePaymentRepo>().uploadEvidence(
        ulid: widget.ulid,
        stage: widget.stage,
        files: _files,
        note: _note.text.trim().isEmpty ? null : _note.text.trim(),
      );

      final ok = r.statusCode == 200 &&
          (r.body is Map && r.body['success'] == true);

      if (!ok) {
        setState(() {
          _busy = false;
          _error = (r.body is Map ? r.body['message'] : null)?.toString()
              ?? 'تعذّر رفع الأدلّة';
        });
        return;
      }

      if (mounted) Navigator.of(context).pop(true);
    } catch (_) {
      setState(() {
        _busy = false;
        _error = 'تعذّر الاتصال. حاول مرة أخرى.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;

    return Padding(
      padding: EdgeInsets.only(bottom: bottom),
      child: Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 22),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 42, height: 4,
                  decoration: BoxDecoration(
                    color: AmyalColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 16),

              Text(widget.title,
                  style: const TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.bold,
                      color: AmyalColors.textPrimary)),
              const SizedBox(height: 6),
              Text(widget.hint,
                  style: const TextStyle(
                      fontSize: 12.5,
                      height: 1.7,
                      color: AmyalColors.textSecondary)),
              const SizedBox(height: 14),

              // التحذير قبل الرفع لا بعده.
              Container(
                padding: const EdgeInsets.all(11),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF8E1),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AmyalColors.yellowDark.withValues(alpha: 0.4)),
                ),
                child: const Row(children: [
                  Icon(Icons.lock_outline_rounded, size: 18, color: Color(0xFFCFA300)),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'الأدلّة لا يمكن حذفها بعد رفعها — هذا ما يجعلها دليلاً '
                      'يُعتدّ به عند النزاع. راجع الصور قبل الإرسال.',
                      style: TextStyle(fontSize: 11.5, height: 1.6),
                    ),
                  ),
                ]),
              ),
              const SizedBox(height: 16),

              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _busy || _files.length >= _max
                        ? null
                        : () => _pick(camera: true),
                    icon: const Icon(Icons.photo_camera_outlined, size: 19),
                    label: const Text('كاميرا'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _busy || _files.length >= _max
                        ? null
                        : () => _pick(camera: false),
                    icon: const Icon(Icons.photo_library_outlined, size: 19),
                    label: const Text('المعرض'),
                  ),
                ),
              ]),

              const SizedBox(height: 10),
              Text('${_files.length} من $_max',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.bold,
                    color: _files.length >= _max
                        ? AmyalColors.yellowDark
                        : AmyalColors.textSecondary,
                  )),

              if (_files.isNotEmpty) ...[
                const SizedBox(height: 12),
                SizedBox(
                  height: 92,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: _files.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 8),
                    itemBuilder: (_, i) => Stack(children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: Image.file(_files[i],
                            width: 92, height: 92, fit: BoxFit.cover),
                      ),
                      // الحذف متاح قبل الرفع فقط — وهذا هو الفرق.
                      Positioned(
                        top: 2, left: 2,
                        child: InkWell(
                          onTap: _busy ? null : () => setState(() => _files.removeAt(i)),
                          child: Container(
                            decoration: const BoxDecoration(
                                color: Colors.black54, shape: BoxShape.circle),
                            padding: const EdgeInsets.all(3),
                            child: const Icon(Icons.close,
                                size: 14, color: Colors.white),
                          ),
                        ),
                      ),
                    ]),
                  ),
                ),
              ],

              const SizedBox(height: 14),
              TextField(
                controller: _note,
                enabled: !_busy,
                maxLength: 300,
                maxLines: 2,
                decoration: const InputDecoration(
                  labelText: 'ملاحظة (اختيارية)',
                  hintText: 'مثال: تسليم أمام محل الهاشمي الساعة 5 عصراً',
                  border: OutlineInputBorder(),
                ),
              ),

              if (_error != null) ...[
                const SizedBox(height: 6),
                Text(_error!,
                    style: const TextStyle(color: AmyalColors.red, fontSize: 12.5)),
              ],

              const SizedBox(height: 14),
              SizedBox(
                height: 50,
                child: ElevatedButton.icon(
                  onPressed: _busy ? null : _submit,
                  icon: _busy
                      ? const SizedBox(
                          width: 18, height: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.upload_rounded),
                  label: Text(_busy ? 'جارٍ الرفع…' : 'رفع الأدلّة'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
