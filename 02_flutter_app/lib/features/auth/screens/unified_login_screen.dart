import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-UNIFIED-AUTH-001 (v1.5)
///
/// شاشة تسجيل دخول موحدة لكل الأدوار:
///   - عميل (Customer): هوية + هاتف + كلمة مرور
///   - تاجر (Merchant): رقم تاجر + هاتف + كلمة مرور + رقم POS اختياري
///   - وكيل (Agent): رقم وكيل + كلمة مرور → OTP
class UnifiedLoginScreen extends StatefulWidget {
  const UnifiedLoginScreen({super.key});

  @override
  State<UnifiedLoginScreen> createState() => _UnifiedLoginScreenState();
}

class _UnifiedLoginScreenState extends State<UnifiedLoginScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      body: SafeArea(
        child: Column(
          children: [
            // ====== Header ======
            Container(
              padding: const EdgeInsets.fromLTRB(20, 40, 20, 20),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [AmyalColors.primary, Color(0xFF1A56C2)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
              ),
              child: const Column(
                children: [
                  Icon(Icons.account_balance_wallet,
                      color: Colors.white, size: 56),
                  SizedBox(height: 12),
                  Text(
                    'أميال باي',
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 28,
                        fontWeight: FontWeight.bold),
                  ),
                  SizedBox(height: 4),
                  Text(
                    'دفع سريع وآمن',
                    style: TextStyle(color: Colors.white70, fontSize: 13),
                  ),
                ],
              ),
            ),

            // ====== Tabs ======
            Container(
              color: Colors.white,
              child: TabBar(
                controller: _tabController,
                indicatorColor: AmyalColors.primary,
                labelColor: AmyalColors.primary,
                unselectedLabelColor: AmyalColors.textMuted,
                tabs: const [
                  Tab(icon: Icon(Icons.person), text: 'عميل'),
                  Tab(icon: Icon(Icons.store), text: 'تاجر'),
                  Tab(icon: Icon(Icons.business_center), text: 'وكيل'),
                ],
              ),
            ),

            // ====== Tab Views ======
            Expanded(
              child: TabBarView(
                controller: _tabController,
                children: const [
                  _CustomerLoginTab(),
                  _MerchantLoginTab(),
                  _AgentLoginTab(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Customer Login Tab
// ============================================================
class _CustomerLoginTab extends StatefulWidget {
  const _CustomerLoginTab();

  @override
  State<_CustomerLoginTab> createState() => _CustomerLoginTabState();
}

class _CustomerLoginTabState extends State<_CustomerLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _nationalIdCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _nationalIdCtrl.dispose();
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final success = await ctrl.loginCustomer(
      nationalId: _nationalIdCtrl.text.trim(),
      phone: _phoneCtrl.text.trim(),
      password: _passwordCtrl.text,
    );
    if (success && mounted) {
      ctrl.navigateToHomeForRole();
    } else if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('تسجيل دخول العميل',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            TextFormField(
              controller: _nationalIdCtrl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'رقم الهوية *',
                prefixIcon: Icon(Icons.badge_outlined),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 5) ? 'رقم هوية غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'رقم الجوال *',
                hintText: '+967700000000',
                prefixIcon: Icon(Icons.phone_outlined),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'رقم غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _passwordCtrl,
              obscureText: _obscurePassword,
              decoration: InputDecoration(
                labelText: 'كلمة المرور *',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                ),
                border: const OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'كلمة المرور 6 أحرف على الأقل' : null,
            ),
            const SizedBox(height: 20),
            Obx(() {
              final ctrl = Get.find<UnifiedAuthController>();
              return ElevatedButton(
                onPressed: ctrl.isSubmitting.value ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: ctrl.isSubmitting.value
                    ? const SizedBox(
                        height: 20, width: 20,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : const Text('دخول', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              );
            }),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Merchant Login Tab
// ============================================================
class _MerchantLoginTab extends StatefulWidget {
  const _MerchantLoginTab();

  @override
  State<_MerchantLoginTab> createState() => _MerchantLoginTabState();
}

class _MerchantLoginTabState extends State<_MerchantLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _merchantNumCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _posNumCtrl = TextEditingController();
  bool _obscurePassword = true;
  bool _isPosLogin = false;

  @override
  void dispose() {
    _merchantNumCtrl.dispose();
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    _posNumCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final success = await ctrl.loginMerchant(
      merchantNumber: _merchantNumCtrl.text.trim(),
      phone: _phoneCtrl.text.trim(),
      password: _passwordCtrl.text,
      posNumber: _isPosLogin ? _posNumCtrl.text.trim() : null,
    );
    if (success && mounted) {
      ctrl.navigateToHomeForRole();
    } else if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('تسجيل دخول التاجر',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            TextFormField(
              controller: _merchantNumCtrl,
              decoration: const InputDecoration(
                labelText: 'رقم التاجر *',
                prefixIcon: Icon(Icons.store),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 3) ? 'رقم تاجر غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'رقم الجوال *',
                prefixIcon: Icon(Icons.phone_outlined),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'رقم غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _passwordCtrl,
              obscureText: _obscurePassword,
              decoration: InputDecoration(
                labelText: 'كلمة المرور *',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                ),
                border: const OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'كلمة المرور قصيرة' : null,
            ),
            const SizedBox(height: 16),
            CheckboxListTile(
              title: const Text('دخول كموظف نقطة بيع'),
              subtitle: const Text('سجل بـ POS number',
                  style: TextStyle(fontSize: 11)),
              value: _isPosLogin,
              activeColor: AmyalColors.primary,
              onChanged: (v) => setState(() => _isPosLogin = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              dense: true,
            ),
            if (_isPosLogin)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: TextFormField(
                  controller: _posNumCtrl,
                  decoration: const InputDecoration(
                    labelText: 'رقم نقطة البيع *',
                    hintText: 'POS-001',
                    prefixIcon: Icon(Icons.point_of_sale),
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (!_isPosLogin) return null;
                    return (v == null || v.isEmpty) ? 'مطلوب' : null;
                  },
                ),
              ),
            const SizedBox(height: 20),
            Obx(() {
              final ctrl = Get.find<UnifiedAuthController>();
              return ElevatedButton(
                onPressed: ctrl.isSubmitting.value ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: ctrl.isSubmitting.value
                    ? const SizedBox(
                        height: 20, width: 20,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : const Text('دخول', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              );
            }),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Agent Login Tab (2-step with OTP)
// ============================================================
class _AgentLoginTab extends StatefulWidget {
  const _AgentLoginTab();

  @override
  State<_AgentLoginTab> createState() => _AgentLoginTabState();
}

class _AgentLoginTabState extends State<_AgentLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _agentNumCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _otpCtrl = TextEditingController();
  bool _obscurePassword = true;
  bool _otpStep = false;
  String _maskedPhone = '';

  @override
  void dispose() {
    _agentNumCtrl.dispose();
    _passwordCtrl.dispose();
    _otpCtrl.dispose();
    super.dispose();
  }

  Future<void> _submitStep1() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final result = await ctrl.loginAgentStep1(
      agentNumber: _agentNumCtrl.text.trim(),
      password: _passwordCtrl.text,
    );
    if (result != null && mounted) {
      setState(() {
        _otpStep = true;
        _maskedPhone = result;
      });
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  Future<void> _submitOtp() async {
    if (_otpCtrl.text.length != 6) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('الرمز 6 أرقام')),
      );
      return;
    }
    final ctrl = Get.find<UnifiedAuthController>();
    final success = await ctrl.loginAgentStep2(_otpCtrl.text.trim());
    if (success && mounted) {
      ctrl.navigateToHomeForRole();
    } else if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('تسجيل دخول الوكيل',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            if (!_otpStep) ...[
              // ====== Step 1 ======
              TextFormField(
                controller: _agentNumCtrl,
                decoration: const InputDecoration(
                  labelText: 'رقم الوكيل *',
                  prefixIcon: Icon(Icons.business_center),
                  border: OutlineInputBorder(),
                ),
                validator: (v) => (v == null || v.length < 3) ? 'رقم وكيل غير صحيح' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _passwordCtrl,
                obscureText: _obscurePassword,
                decoration: InputDecoration(
                  labelText: 'كلمة المرور *',
                  prefixIcon: const Icon(Icons.lock_outline),
                  suffixIcon: IconButton(
                    icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                    onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                  ),
                  border: const OutlineInputBorder(),
                ),
                validator: (v) => (v == null || v.length < 6) ? 'كلمة المرور قصيرة' : null,
              ),
              const SizedBox(height: 20),
              Obx(() {
                final ctrl = Get.find<UnifiedAuthController>();
                return ElevatedButton(
                  onPressed: ctrl.isSubmitting.value ? null : _submitStep1,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 20, width: 20,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Text('إرسال رمز التحقق',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                );
              }),
            ] else ...[
              // ====== Step 2: OTP ======
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withOpacity(0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Column(
                  children: [
                    const Icon(Icons.sms, color: AmyalColors.primary, size: 32),
                    const SizedBox(height: 8),
                    Text('تم إرسال رمز التحقق إلى $_maskedPhone',
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 12)),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _otpCtrl,
                keyboardType: TextInputType.number,
                maxLength: 6,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 24, fontWeight: FontWeight.bold, letterSpacing: 8),
                decoration: const InputDecoration(
                  labelText: 'رمز التحقق (6 أرقام)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),
              Obx(() {
                final ctrl = Get.find<UnifiedAuthController>();
                return ElevatedButton(
                  onPressed: ctrl.isSubmitting.value ? null : _submitOtp,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 20, width: 20,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Text('تأكيد ودخول',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                );
              }),
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => setState(() {
                  _otpStep = false;
                  _otpCtrl.clear();
                }),
                child: const Text('رجوع'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
