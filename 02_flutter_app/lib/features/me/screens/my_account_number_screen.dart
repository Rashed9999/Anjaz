import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/me/domain/me_repo.dart';

/// AMIAL-ME-001 — شاشة "رقم حسابي" مع نسخ + مشاركة.
class MyAccountNumberScreen extends StatefulWidget {
  const MyAccountNumberScreen({super.key});

  @override
  State<MyAccountNumberScreen> createState() => _MyAccountNumberScreenState();
}

class _MyAccountNumberScreenState extends State<MyAccountNumberScreen> {
  late final MeController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<MeController>();
    if (c.me.value == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => c.load());
    }
  }

  void _copy(String value, String label) {
    Clipboard.setData(ClipboardData(text: value));
    Get.snackbar('تم النسخ', '$label نُسخ إلى الحافظة',
        backgroundColor: Colors.green.shade100, colorText: Colors.green.shade800,
        snackPosition: SnackPosition.BOTTOM, duration: const Duration(seconds: 2));
  }

  /// تنسيق رقم 8 أرقام بفواصل (XX-XX-XXXX) لقراءة أسهل
  String _formatAccountNumber(String raw) {
    final clean = raw.replaceAll(RegExp(r'\s+'), '');
    if (clean.length != 8) return raw;
    return '${clean.substring(0, 2)} ${clean.substring(2, 4)} ${clean.substring(4)}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('رقم حسابي'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.me.value == null) {
          return const Center(child: CircularProgressIndicator());
        }
        final m = c.me.value;
        if (m == null) {
          return RefreshIndicator(
            onRefresh: c.load,
            child: ListView(children: const [
              SizedBox(height: 200),
              Center(child: Text('فشل تحميل البيانات. اسحب للتحديث.')),
            ]),
          );
        }
        final acc = (m['account_number'] ?? '').toString();
        final name = (m['name'] ?? '').toString();
        final phone = (m['phone'] ?? '').toString();

        if (acc.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(Icons.account_balance_wallet_outlined, size: 80, color: Colors.grey.shade400),
                const SizedBox(height: 16),
                const Text('لا يوجد رقم حساب لهذا الحساب بعد',
                    style: TextStyle(fontSize: 16), textAlign: TextAlign.center),
                const SizedBox(height: 8),
                Text('قد يستغرق التعيين دقائق بعد التسجيل',
                    style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
              ]),
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: c.load,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(20),
            child: Column(children: [
              // ====== بطاقة الرقم الرئيسية ======
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(28),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmyalColors.primary, AmyalColors.primaryDark],
                    begin: Alignment.topLeft, end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Column(children: [
                  Row(children: [
                    const Icon(Icons.account_balance_wallet, color: AmyalColors.yellow, size: 26),
                    const SizedBox(width: 8),
                    const Text('رقم حساب أميال باي', style: TextStyle(color: Colors.white70, fontSize: 13)),
                    const Spacer(),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: AmyalColors.yellow,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Text('AMYAL',
                          style: TextStyle(color: Colors.black87, fontSize: 10, fontWeight: FontWeight.bold)),
                    ),
                  ]),
                  const SizedBox(height: 28),
                  SelectableText(
                    _formatAccountNumber(acc),
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 38,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 4,
                      fontFeatures: [FontFeature.tabularFigures()],
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 20),
                  if (name.isNotEmpty)
                    Text(name, style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w500)),
                  if (phone.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(phone, style: const TextStyle(color: Colors.white70, fontSize: 12)),
                  ],
                ]),
              ),

              const SizedBox(height: 20),

              // ====== أزرار نسخ + مشاركة ======
              Row(children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () => _copy(acc, 'رقم الحساب'),
                    icon: const Icon(Icons.copy),
                    label: const Text('نسخ'),
                    style: FilledButton.styleFrom(
                      backgroundColor: AmyalColors.yellow,
                      foregroundColor: Colors.black87,
                      minimumSize: const Size.fromHeight(52),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _copy('رقم حسابي في أميال باي: $acc', 'نص المشاركة'),
                    icon: const Icon(Icons.share, size: 20),
                    label: const Text('مشاركة'),
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size.fromHeight(52),
                      side: const BorderSide(color: AmyalColors.primary),
                      foregroundColor: AmyalColors.primary,
                    ),
                  ),
                ),
              ]),

              const SizedBox(height: 24),

              // ====== ملاحظات هامّة ======
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Row(children: [
                    const Spacer(),
                    const Text('استخدامات رقم الحساب', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
                    const SizedBox(width: 8),
                    Icon(Icons.info_outline, color: AmyalColors.primary, size: 20),
                  ]),
                  const SizedBox(height: 12),
                  _bulletItem('استقبل تحويلات داخلية برقم حسابك بدل الهاتف.'),
                  _bulletItem('شاركه بأمان — لا يكشف رصيدك ولا بياناتك.'),
                  _bulletItem('استخدمه لاسترداد حسابك إن فقدت الرقم.'),
                ]),
              ),
            ]),
          ),
        );
      }),
    );
  }

  Widget _bulletItem(String text) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          margin: const EdgeInsets.only(top: 7),
          width: 6, height: 6,
          decoration: const BoxDecoration(color: AmyalColors.yellow, shape: BoxShape.circle),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(text, textAlign: TextAlign.right,
              style: TextStyle(fontSize: 13, color: Colors.grey.shade700, height: 1.4)),
        ),
      ]),
    );
  }
}
