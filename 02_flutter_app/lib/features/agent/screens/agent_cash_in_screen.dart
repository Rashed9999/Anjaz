import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/controllers/agent_controller.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-AGENT-APP-001 (v1.6)
///
/// Cash-In: الوكيل يستلم كاش من العميل ويُضيف للمحفظة الإلكترونية.
/// تتم العملية:
///   - الوكيل يخصم من محفظته
///   - العميل يستلم في محفظته
class AgentCashInScreen extends StatefulWidget {
  const AgentCashInScreen({super.key});

  @override
  State<AgentCashInScreen> createState() => _AgentCashInScreenState();
}

class _AgentCashInScreenState extends State<AgentCashInScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _pinCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  bool _obscurePin = true;
  bool _customerVerified = false;
  String? _customerName;
  bool _isCheckingCustomer = false;

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    _pinCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _checkCustomer() async {
    if (_phoneCtrl.text.trim().length < 6) return;
    setState(() {
      _isCheckingCustomer = true;
      _customerVerified = false;
      _customerName = null;
    });
    final result = await Get.find<AgentController>()
        .checkCustomer(_phoneCtrl.text.trim());
    setState(() {
      _isCheckingCustomer = false;
      if (result != null) {
        _customerVerified = true;
        final f = result['f_name']?.toString() ?? '';
        final l = result['l_name']?.toString() ?? '';
        _customerName = '$f $l'.trim().isEmpty ? 'عميل' : '$f $l'.trim();
      } else {
        _customerVerified = false;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('العميل غير موجود')),
        );
      }
    });
  }

  /// مسح QR العميل لتعبئة رقمه تلقائياً (AMIAL-QR-001 v1.8)
  Future<void> _scanCustomerQr() async {
    final result = await Get.to(() => const QrScannerScreen(
          title: 'مسح رمز العميل',
        ));
    if (result == null || result is! String) return;

    // الـ QR قد يحمل: amyalpay://user?phone=+967... أو رقم مباشر
    String phone = result;
    final uri = Uri.tryParse(result);
    if (uri != null && uri.queryParameters.containsKey('phone')) {
      phone = uri.queryParameters['phone']!;
    }
    _phoneCtrl.text = phone;
    await _checkCustomer();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (!_customerVerified) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تحقق من العميل أولاً')),
      );
      return;
    }

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تأكيد العملية'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('العميل: $_customerName'),
            const SizedBox(height: 4),
            Text('رقم الجوال: ${_phoneCtrl.text}'),
            const SizedBox(height: 4),
            Text('المبلغ: ${_amountCtrl.text} ر.س',
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    color: AmyalColors.primary,
                    fontSize: 16)),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: AmyalColors.primary),
            child: const Text('تأكيد', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    final ctrl = Get.find<AgentController>();
    final success = await ctrl.cashIn(
      customerPhone: _phoneCtrl.text.trim(),
      amount: _amountCtrl.text.trim(),
      pin: _pinCtrl.text.trim(),
      note: _noteCtrl.text.trim().isEmpty ? null : _noteCtrl.text.trim(),
    );

    if (!mounted) return;
    if (success) {
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.check_circle, color: Color(0xFF10B981), size: 56),
          title: const Text('تمت العملية'),
          content: Text(
            'تم إيداع ${_amountCtrl.text} ر.س للعميل $_customerName',
            textAlign: TextAlign.center,
          ),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.pop(ctx);
                Get.back();
              },
              child: const Text('حسناً'),
            ),
          ],
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ctrl.lastError.value),
          backgroundColor: AmyalColors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('إيداع للعميل'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // ====== Info banner ======
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withOpacity(0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.info_outline, color: AmyalColors.primary),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'سيتم خصم المبلغ من محفظتك وإضافته لمحفظة العميل بعد استلام الكاش منه.',
                        style: TextStyle(fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ====== Scan QR button ======
              OutlinedButton.icon(
                onPressed: _scanCustomerQr,
                icon: const Icon(Icons.qr_code_scanner),
                label: const Text('مسح رمز العميل (QR)'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AmyalColors.primary,
                  side: const BorderSide(color: AmyalColors.primary),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
              const SizedBox(height: 16),

              // ====== Customer phone ======
              TextFormField(
                controller: _phoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: InputDecoration(
                  labelText: 'رقم جوال العميل *',
                  hintText: '+967700000000',
                  prefixIcon: const Icon(Icons.phone),
                  suffixIcon: _isCheckingCustomer
                      ? const Padding(
                          padding: EdgeInsets.all(12),
                          child: SizedBox(
                            width: 16, height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        )
                      : IconButton(
                          icon: const Icon(Icons.search),
                          tooltip: 'تحقق',
                          onPressed: _checkCustomer,
                        ),
                  border: const OutlineInputBorder(),
                ),
                validator: (v) => (v == null || v.length < 6) ? 'رقم غير صحيح' : null,
                onChanged: (_) {
                  if (_customerVerified) {
                    setState(() {
                      _customerVerified = false;
                      _customerName = null;
                    });
                  }
                },
              ),

              if (_customerName != null && _customerVerified) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: const Color(0xFF10B981).withOpacity(0.1),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.check_circle,
                          color: Color(0xFF10B981), size: 18),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text('العميل: $_customerName',
                            style: const TextStyle(
                                fontSize: 13, fontWeight: FontWeight.w600)),
                      ),
                    ],
                  ),
                ),
              ],

              const SizedBox(height: 16),

              // ====== Amount ======
              TextFormField(
                controller: _amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[\d.]')),
                ],
                decoration: const InputDecoration(
                  labelText: 'المبلغ (ر.س) *',
                  prefixIcon: Icon(Icons.attach_money),
                  border: OutlineInputBorder(),
                ),
                validator: (v) {
                  final n = double.tryParse(v ?? '');
                  if (n == null || n <= 0) return 'مبلغ غير صحيح';
                  return null;
                },
              ),
              const SizedBox(height: 16),

              // ====== Note (optional) ======
              TextFormField(
                controller: _noteCtrl,
                maxLength: 200,
                decoration: const InputDecoration(
                  labelText: 'ملاحظة (اختياري)',
                  prefixIcon: Icon(Icons.notes),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),

              // ====== Transaction PIN ======
              TextFormField(
                controller: _pinCtrl,
                obscureText: _obscurePin,
                keyboardType: TextInputType.number,
                maxLength: 6,
                decoration: InputDecoration(
                  labelText: 'رمز الأمان (PIN) *',
                  prefixIcon: const Icon(Icons.lock_outline),
                  suffixIcon: IconButton(
                    icon: Icon(_obscurePin ? Icons.visibility_off : Icons.visibility),
                    onPressed: () => setState(() => _obscurePin = !_obscurePin),
                  ),
                  border: const OutlineInputBorder(),
                ),
                validator: (v) {
                  if (v == null || v.length < 4) return 'PIN قصير';
                  return null;
                },
              ),

              const SizedBox(height: 20),

              Obx(() {
                final ctrl = Get.find<AgentController>();
                return ElevatedButton.icon(
                  onPressed: ctrl.isSubmitting.value ? null : _submit,
                  icon: ctrl.isSubmitting.value
                      ? const SizedBox(
                          width: 18, height: 18,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Icon(Icons.arrow_downward),
                  label: const Text('إيداع المبلغ للعميل',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 16),
                  ),
                );
              }),
            ],
          ),
        ),
      ),
    );
  }
}
