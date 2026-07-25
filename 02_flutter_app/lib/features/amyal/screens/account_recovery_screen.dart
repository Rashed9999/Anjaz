import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:pin_code_fields/pin_code_fields.dart';
import 'package:amyal_pay/features/amyal/domain/models/amyal_models.dart';
import 'package:amyal_pay/features/amyal/domain/repositories/amyal_repo.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMYAL-RECOVERY-001 (v0.7-D)
///
/// AccountRecoveryScreen — wizard 3 خطوات لـ self-service phone change:
///   Step 1: إدخال الرقم الجديد
///   Step 2: إدخال OTPs للقديم والجديد
///   Step 3: إدخال PIN لتأكيد التغيير
///
/// Step 4 (آلي): انتظار security_hold (24h) قبل تطبيق التغيير.
///
/// **لـ lost-phone flow:** شاشة منفصلة (`LostPhoneRecoveryScreen`).
class AccountRecoveryScreen extends StatefulWidget {
  const AccountRecoveryScreen({super.key});

  @override
  State<AccountRecoveryScreen> createState() => _AccountRecoveryScreenState();
}

class _AccountRecoveryScreenState extends State<AccountRecoveryScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _otpOldCtrl = TextEditingController();
  final _otpNewCtrl = TextEditingController();
  final _pinCtrl = TextEditingController();

  int _step = 0; // 0..2
  bool _loading = false;
  String? _error;
  AmyalRecoveryRequest? _request;

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _otpOldCtrl.dispose();
    _otpNewCtrl.dispose();
    _pinCtrl.dispose();
    super.dispose();
  }

  Future<void> _initiate() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await Get.find<AmyalRepo>().initiateSelfRecovery(
        newPhone: _phoneCtrl.text.trim(),
      );
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        setState(() {
          _request = AmyalRecoveryRequest.fromJson(
            (r.body['meta'] ?? {}) as Map<String, dynamic>,
          );
          _step = 1;
        });
      } else {
        setState(() => _error = (r.body is Map ? r.body['message'] : null) ?? 'فشلت العملية');
      }
    } catch (e) {
      setState(() => _error = 'خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _verifyOtp() async {
    if (_otpOldCtrl.text.length != 6 || _otpNewCtrl.text.length != 6) {
      setState(() => _error = 'كلا الرمزين يجب أن يكونا 6 أرقام');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await Get.find<AmyalRepo>().verifyRecoveryOtp(
        ulid: _request!.requestUlid,
        otpOld: _otpOldCtrl.text,
        otpNew: _otpNewCtrl.text,
      );
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        setState(() => _step = 2);
      } else {
        setState(() => _error =
            (r.body is Map ? r.body['message'] : null) ?? 'رمز غير صحيح أو منتهي');
      }
    } catch (e) {
      setState(() => _error = 'خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _complete() async {
    if (_pinCtrl.text.length < 4) {
      setState(() => _error = 'PIN يجب أن يكون 4 أرقام على الأقل');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await Get.find<AmyalRepo>().completeRecovery(
        ulid: _request!.requestUlid,
        pin: _pinCtrl.text,
      );
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        if (mounted) _showSuccessDialog();
      } else {
        setState(() => _error =
            (r.body is Map ? r.body['message'] : null) ?? 'PIN غير صحيح');
      }
    } catch (e) {
      setState(() => _error = 'خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _showSuccessDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        backgroundColor: Colors.white,
        icon: const Icon(Icons.check_circle,
            color: AmyalColors.primary, size: 56),
        title: const Text(
          'تم قبول طلبك',
          textAlign: TextAlign.center,
          style: TextStyle(color: AmyalColors.primary),
        ),
        content: const Text(
          'سيتم تطبيق التغيير بعد 24 ساعة من المراجعة الأمنية. '
          'يتم تعطيل حسابك مؤقتاً خلال هذه الفترة.',
          textAlign: TextAlign.center,
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.of(context).pop();
              Navigator.of(context).pop();
            },
            child: const Text('حسناً'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('استرداد الحساب'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _buildStepIndicator(),
            const SizedBox(height: 24),
            if (_error != null)
              Container(
                margin: const EdgeInsets.only(bottom: 16),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AmyalColors.red.withValues(alpha: 0.1),
                  border: Border.all(color: AmyalColors.red),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.warning_amber, color: AmyalColors.red),
                    const SizedBox(width: 8),
                    Expanded(child: Text(_error!,
                        style: const TextStyle(color: AmyalColors.red))),
                  ],
                ),
              ),
            _buildCurrentStep(),
          ],
        ),
      ),
    );
  }

  Widget _buildStepIndicator() {
    return Row(
      children: List.generate(3, (i) {
        final active = i <= _step;
        return Expanded(
          child: Column(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: active ? AmyalColors.primary : AmyalColors.border,
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: Text(
                    '${i + 1}',
                    style: const TextStyle(
                        color: Colors.white, fontWeight: FontWeight.bold),
                  ),
                ),
              ),
              const SizedBox(height: 4),
              Text(
                ['الرقم الجديد', 'OTP', 'تأكيد PIN'][i],
                style: TextStyle(
                  fontSize: 11,
                  color: active ? AmyalColors.primary : AmyalColors.textMuted,
                  fontWeight: active ? FontWeight.bold : FontWeight.normal,
                ),
              ),
            ],
          ),
        );
      }),
    );
  }

  Widget _buildCurrentStep() {
    switch (_step) {
      case 0:
        return _buildStepEnterPhone();
      case 1:
        return _buildStepEnterOtps();
      case 2:
        return _buildStepEnterPin();
      default:
        return const SizedBox.shrink();
    }
  }

  Widget _buildStepEnterPhone() {
    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'أدخل رقم الهاتف الجديد',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          const Text(
            'سنرسل رمز تحقق إلى رقمك القديم والجديد. تحتاج كلا الرمزين لإكمال العملية.',
            style: TextStyle(color: AmyalColors.textSecondary),
          ),
          const SizedBox(height: 24),
          TextFormField(
            controller: _phoneCtrl,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(
              labelText: 'الرقم الجديد',
              prefixIcon: const Icon(Icons.phone),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(8),
              ),
            ),
            validator: (v) {
              if (v == null || v.trim().length < 7) {
                return 'رقم غير صحيح';
              }
              return null;
            },
          ),
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: _loading ? null : _initiate,
            style: _buttonStyle(),
            child: _loading
                ? const SizedBox(
                    height: 20, width: 20,
                    child: CircularProgressIndicator(
                        color: Colors.white, strokeWidth: 2))
                : const Text('إرسال OTP'),
          ),
        ],
      ),
    );
  }

  Widget _buildStepEnterOtps() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'أدخل رمزَي التحقق',
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 24),
        const Text('OTP من الرقم القديم:'),
        const SizedBox(height: 8),
        PinCodeTextField(
          appContext: context,
          length: 6,
          controller: _otpOldCtrl,
          keyboardType: TextInputType.number,
          pinTheme: _pinTheme(),
          onChanged: (_) {},
        ),
        const SizedBox(height: 16),
        const Text('OTP من الرقم الجديد:'),
        const SizedBox(height: 8),
        PinCodeTextField(
          appContext: context,
          length: 6,
          controller: _otpNewCtrl,
          keyboardType: TextInputType.number,
          pinTheme: _pinTheme(),
          onChanged: (_) {},
        ),
        const SizedBox(height: 24),
        ElevatedButton(
          onPressed: _loading ? null : _verifyOtp,
          style: _buttonStyle(),
          child: _loading
              ? const SizedBox(
                  height: 20, width: 20,
                  child: CircularProgressIndicator(
                      color: Colors.white, strokeWidth: 2))
              : const Text('تحقق'),
        ),
      ],
    );
  }

  Widget _buildStepEnterPin() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'تأكيد PIN الحساب',
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 8),
        const Text(
          'أدخل رمز PIN الخاص بحسابك لإتمام العملية.',
          style: TextStyle(color: AmyalColors.textSecondary),
        ),
        const SizedBox(height: 24),
        PinCodeTextField(
          appContext: context,
          length: 4,
          controller: _pinCtrl,
          keyboardType: TextInputType.number,
          obscureText: true,
          pinTheme: _pinTheme(),
          onChanged: (_) {},
        ),
        const SizedBox(height: 24),
        ElevatedButton(
          onPressed: _loading ? null : _complete,
          style: _buttonStyle(),
          child: _loading
              ? const SizedBox(
                  height: 20, width: 20,
                  child: CircularProgressIndicator(
                      color: Colors.white, strokeWidth: 2))
              : const Text('إتمام التغيير'),
        ),
      ],
    );
  }

  PinTheme _pinTheme() => PinTheme(
        shape: PinCodeFieldShape.box,
        borderRadius: BorderRadius.circular(8),
        fieldHeight: 48,
        fieldWidth: 40,
        activeColor: AmyalColors.primary,
        selectedColor: AmyalColors.primary,
        inactiveColor: AmyalColors.border,
        activeFillColor: Colors.white,
        selectedFillColor: Colors.white,
        inactiveFillColor: Colors.white,
      );

  ButtonStyle _buttonStyle() => ElevatedButton.styleFrom(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(vertical: 14),
        shape:
            RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      );
}
