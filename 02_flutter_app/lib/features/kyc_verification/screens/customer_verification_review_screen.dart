import 'dart:io';

import 'package:amial_pay/features/kyc_verification/domain/customer_verification_level.dart';
import 'package:flutter/material.dart';

class VerificationReviewRow {
  final String label;
  final String value;

  const VerificationReviewRow(this.label, this.value);
}

class VerificationReviewDocument {
  final String label;
  final String path;

  const VerificationReviewDocument({
    required this.label,
    required this.path,
  });
}

/// AMIAL-KYC-REVIEW-001
///
/// آخر خطوة قبل إرسال أي طلب توثيق للعميل.
/// نفس القالب يُستخدم لكل الحالات، بينما تتغيّر البيانات والمستندات:
/// - موثق جزئيا: إثبات السكن.
/// - موثق بهوية: وجه الهوية + ظهرها.
/// - موثق: سيلفي حديث/إثبات ملكية.
///
/// ما يظهر هنا هو ما يؤرشفه الخادم كلقطة ثابتة بعد التأكيد، ليكون قابلاً
/// للطباعة من لوحة الإدارة والاحتفاظ به في الأرشيف الورقي.
class CustomerVerificationReviewScreen extends StatefulWidget {
  final int targetTier;
  final List<VerificationReviewRow> rows;
  final List<VerificationReviewDocument> documents;
  final Future<bool> Function() onConfirm;

  const CustomerVerificationReviewScreen({
    super.key,
    required this.targetTier,
    required this.rows,
    required this.documents,
    required this.onConfirm,
  });

  @override
  State<CustomerVerificationReviewScreen> createState() =>
      _CustomerVerificationReviewScreenState();
}

class _CustomerVerificationReviewScreenState
    extends State<CustomerVerificationReviewScreen> {
  bool _busy = false;
  bool _declarationAccepted = false;

  @override
  Widget build(BuildContext context) {
    final state = CustomerVerificationLevel.fromTier(widget.targetTier);

    return Scaffold(
      backgroundColor: const Color(0xFFF6F8FB),
      appBar: AppBar(
        title: const Text('مراجعة طلب التوثيق'),
      ),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: state.color.withValues(alpha: 0.10),
                        borderRadius: BorderRadius.circular(18),
                        border: Border.all(
                          color: state.color.withValues(alpha: 0.35),
                        ),
                      ),
                      child: Row(
                        children: [
                          Container(
                            width: 14,
                            height: 14,
                            decoration: BoxDecoration(
                              color: state.color,
                              shape: BoxShape.circle,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'طلب التوثيق',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: Color(0xFF667386),
                                  ),
                                ),
                                const SizedBox(height: 3),
                                Text(
                                  state.label,
                                  style: const TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(13),
                      decoration: BoxDecoration(
                        color: const Color(0xFFEAF3FF),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: const Color(0xFFBED8F7)),
                      ),
                      child: const Text(
                        'راجع البيانات جيداً. عند الضغط على «تأكيد وإرسال للتوثيق» '
                        'يحفظ أميال لقطة مؤرشفة مطابقة لما أكدته هنا، ويمكن للإدارة '
                        'طباعتها مع المستندات والاحتفاظ بها ضمن الأرشيف الورقي.',
                        style: TextStyle(
                          fontSize: 12.5,
                          height: 1.55,
                          color: Color(0xFF24527A),
                        ),
                      ),
                    ),
                    const SizedBox(height: 14),
                    _sectionTitle('بيانات الطلب', Icons.fact_check_outlined),
                    const SizedBox(height: 8),
                    _dataTable(),
                    const SizedBox(height: 16),
                    _sectionTitle(
                      'المستندات المطلوبة لهذه الحالة',
                      Icons.folder_copy_outlined,
                    ),
                    const SizedBox(height: 8),
                    ...widget.documents.map(_documentCard),
                    if (widget.documents.isEmpty)
                      Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: const Color(0xFFE1E6ED)),
                        ),
                        child: const Text(
                          'لا يوجد مستند جديد مطلوب في هذه الخطوة.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Color(0xFF667386)),
                        ),
                      ),
                    const SizedBox(height: 10),
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _declarationAccepted,
                      onChanged: _busy
                          ? null
                          : (value) => setState(
                                () => _declarationAccepted = value == true,
                              ),
                      controlAffinity: ListTileControlAffinity.leading,
                      title: const Text(
                        'أقر بأن البيانات المعروضة صحيحة، وأن المستندات المرفقة تخصني، وأوافق على إرسالها للمراجعة والتوثيق.',
                        style: TextStyle(
                          fontSize: 12.5,
                          height: 1.5,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'لن نطلب منك إعادة رفع مستند سبق اعتماده في حالة توثيق أدنى.',
                      style: TextStyle(
                        fontSize: 11.5,
                        height: 1.5,
                        color: Color(0xFF667386),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(
                  top: BorderSide(color: Color(0xFFE4E8EE)),
                ),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: _busy ? null : () => Navigator.of(context).pop(false),
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size.fromHeight(52),
                      ),
                      child: const Text('تعديل البيانات'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      onPressed:
                          _busy || !_declarationAccepted ? null : _confirm,
                      style: FilledButton.styleFrom(
                        minimumSize: const Size.fromHeight(52),
                      ),
                      child: _busy
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Text(
                              'تأكيد وإرسال للتوثيق',
                              style: TextStyle(fontWeight: FontWeight.w800),
                            ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _confirm() async {
    setState(() => _busy = true);
    try {
      final ok = await widget.onConfirm();
      if (ok && mounted) {
        Navigator.of(context).pop(true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _sectionTitle(String title, IconData icon) => Row(
        children: [
          Icon(icon, size: 20, color: const Color(0xFF1F5E66)),
          const SizedBox(width: 8),
          Text(
            title,
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      );

  Widget _dataTable() {
    final rows = widget.rows
        .where((row) => row.value.trim().isNotEmpty)
        .toList();

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFDCE3EA)),
      ),
      child: Column(
        children: List.generate(rows.length, (index) {
          final row = rows[index];
          return Container(
            decoration: BoxDecoration(
              border: index == rows.length - 1
                  ? null
                  : const Border(
                      bottom: BorderSide(color: Color(0xFFE4E8ED)),
                    ),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  width: 132,
                  padding: const EdgeInsets.all(12),
                  color: const Color(0xFF2D6F73),
                  alignment: Alignment.centerRight,
                  child: Text(
                    row.label,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 12.5,
                    ),
                  ),
                ),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    alignment: Alignment.centerRight,
                    child: Text(
                      row.value,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: Color(0xFF24303D),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _documentCard(VerificationReviewDocument document) {
    final file = File(document.path);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFDCE3EA)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            document.label,
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 9),
          ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Image.file(
              file,
              height: 230,
              fit: BoxFit.contain,
              errorBuilder: (_, __, ___) => Container(
                height: 120,
                alignment: Alignment.center,
                color: const Color(0xFFF2F5F8),
                child: const Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.insert_drive_file_outlined, size: 34),
                    SizedBox(height: 6),
                    Text('تم اختيار المستند'),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
