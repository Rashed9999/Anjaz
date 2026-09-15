import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-ACCOUNT-SECURITY-001 — **أمانُ الحساب: الكلمةُ والرمز.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمنُ الذي دُفع.** قال صاحبُ المشروع: «آخرُ خطوةٍ في التسجيل هي
/// إدخالُ رمز PIN وكلمة المرور، **ويبدو أنّ رمز PIN هو كلمةُ المرور** —
/// إذن كيف يمكن تغييرُ كلمة المرور بعد تسجيل الدخول؟ **لا يوجد طريقة**».
///
/// وقِيس فكان الاثنان صحيحين: التسجيلُ يكتب كلمةَ المرور في `transaction_pin`
/// نفسِه، **ولا مسارَ في المشروع كلِّه يغيّر كلمةَ المرور لمن دخل**. وشاشةُ
/// `change_pin` القديمة للعميل وحدَه، وتغيّر الرمزَ ولا تمسّ الكلمة.
///
/// **فهذه بابٌ واحدٌ للاثنين**، تقول أوّلاً أيّهما ما زال مربوطاً بالآخر.
///
/// يظهر في : التطبيق ← الملفّ الشخصيّ ← «أمان الحساب» — للأنواع الثلاثة.
/// وفي لوحة الإدارة: لا — قرارُ صاحب الحساب في حسابه.
class AccountSecurityScreen extends StatefulWidget {
  const AccountSecurityScreen({super.key});

  @override
  State<AccountSecurityScreen> createState() => _AccountSecurityScreenState();
}

class _AccountSecurityScreenState extends State<AccountSecurityScreen> {
  static const _base = '/api/v1/amial/me/security';

  final ApiClient _api = Get.find<ApiClient>();

  bool _loading = true;
  bool _busy = false;
  String _loadError = '';
  Map<String, dynamic> _status = const {};

  final _curPass = TextEditingController();
  final _newPass = TextEditingController();
  final _newPass2 = TextEditingController();

  final _curPin = TextEditingController();
  final _newPin = TextEditingController();
  final _newPin2 = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_curPass, _newPass, _newPass2, _curPin, _newPin, _newPin2]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = '';
    });
    try {
      final r = await _api.getData(_base);
      if (!mounted) return;
      if (r.statusCode == 200 && r.body?['success'] == true) {
        setState(() => _status =
            Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map));
      } else {
        setState(() => _loadError = _msg(r, 'تعذّر قراءة حالة الأمان'));
      }
    } catch (_) {
      if (mounted) setState(() => _loadError = 'لا اتصال بالخادم');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _msg(dynamic r, String fallback) {
    final m = r.body is Map ? r.body['message'] : null;
    return (m is String && m.trim().isNotEmpty) ? m : fallback;
  }

  void _snack(String text, {bool error = false}) {
    if (!mounted || text.trim().isEmpty) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(text),
      backgroundColor: error ? AmialColors.danger : AmialColors.success,
      duration: const Duration(seconds: 5),
    ));
  }

  // ── تغييرُ كلمة المرور ───────────────────────────────────────────────

  Future<void> _submitPassword() async {
    if (_newPass.text != _newPass2.text) {
      _snack('التأكيدُ لا يطابق الكلمةَ الجديدة', error: true);
      return;
    }
    if (_newPass.text.length < 4) {
      _snack('كلمةُ المرور أربعةُ محارفَ فأكثر', error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final r = await _api.postData('$_base/password', {
        'current_password': _curPass.text,
        'new_password': _newPass.text,
        'new_password_confirmation': _newPass2.text,
      });
      if (!mounted) return;

      final ok = r.statusCode == 200 && r.body?['success'] == true;
      _snack(_msg(r, ok ? 'غُيّرت كلمةُ المرور' : 'تعذّر التغيير'), error: !ok);

      if (ok) {
        for (final c in [_curPass, _newPass, _newPass2]) {
          c.clear();
        }
        await _load();
      }
    } catch (_) {
      _snack('لا اتصال بالخادم', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ── اختيارُ رمز التحويل ──────────────────────────────────────────────

  Future<void> _submitPin() async {
    if (_newPin.text != _newPin2.text) {
      _snack('التأكيدُ لا يطابق الرمزَ الجديد', error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final r = await _api.postData('$_base/pin', {
        'current': _curPin.text,
        'new_pin': _newPin.text,
        'new_pin_confirmation': _newPin2.text,
      });
      if (!mounted) return;

      final ok = r.statusCode == 200 && r.body?['success'] == true;
      _snack(_msg(r, ok ? 'اختيرَ الرمز' : 'تعذّر التغيير'), error: !ok);

      if (ok) {
        for (final c in [_curPin, _newPin, _newPin2]) {
          c.clear();
        }
        await _load();
      }
    } catch (_) {
      _snack('لا اتصال بالخادم', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ── البناء ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('أمان الحساب')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_loadError.isNotEmpty) _loadErrorCard(),
                  if (_status['pin_equals_password'] == true) _linkedNotice(),
                  _passwordCard(),
                  const SizedBox(height: 16),
                  _pinCard(),
                  const SizedBox(height: 32),
                ],
              ),
            ),
    );
  }

  Widget _loadErrorCard() {
    return Card(
      color: AmialColors.dangerSurface,
      child: ListTile(
        leading: const Icon(Icons.error_outline_rounded, color: AmialColors.danger),
        title: Text(_loadError),
        trailing: TextButton(onPressed: _load, child: const Text('إعادة')),
      ),
    );
  }

  /// **اللافتةُ تقول الحقيقةَ ولا تُجمّلها** — ومن لا يعرف أنّ رمزَه هو
  /// كلمةُ مروره لا يبحث عن تغييره.
  Widget _linkedNotice() {
    return Card(
      key: const Key('security-pin-equals-password'),
      color: AmialColors.warningSurface,
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.link_off_rounded, color: AmialColors.warning),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              (_status['notice'] as String?) ??
                  'رمزُ التحويل هو كلمةُ مرورك نفسُها — اختر رمزاً مستقلّاً.',
              style: const TextStyle(fontSize: 13, height: 1.5),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _passwordCard() {
    return _card(
      title: 'كلمة المرور',
      subtitle: 'التي تدخل بها إلى حسابك.',
      icon: Icons.password_rounded,
      children: [
        _field(_curPass, 'كلمة المرور الحالية',
            key: const Key('security-current-password')),
        _field(_newPass, 'كلمة المرور الجديدة',
            key: const Key('security-new-password')),
        _field(_newPass2, 'تأكيد الجديدة'),
        const SizedBox(height: 12),
        _button('تغيير كلمة المرور', _submitPassword,
            key: const Key('security-save-password')),
      ],
    );
  }

  Widget _pinCard() {
    final chosen = _status['pin_was_chosen'] == true;

    return _card(
      title: 'رمز التحويل',
      subtitle: chosen
          ? 'رمزٌ مستقلٌّ يُطلب عند تحريك المال.'
          : 'لم يُختَر بعد — وهو الآن كلمةُ مرورك. أدخلها في «الرمز الحالي».',
      icon: Icons.pin_rounded,
      children: [
        _field(_curPin, 'الرمز الحالي',
            key: const Key('security-current-pin')),
        _field(_newPin, 'الرمز الجديد (٤ إلى ٦ أرقام)',
            key: const Key('security-new-pin'), digitsOnly: true, maxLength: 6),
        _field(_newPin2, 'تأكيد الرمز', digitsOnly: true, maxLength: 6),
        const SizedBox(height: 12),
        _button('حفظ الرمز', _submitPin, key: const Key('security-save-pin')),
      ],
    );
  }

  Widget _card({
    required String title,
    required String subtitle,
    required IconData icon,
    required List<Widget> children,
  }) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(icon, color: AmialColors.primary),
            const SizedBox(width: 8),
            Text(title,
                style: const TextStyle(
                    fontSize: 16, fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 4),
          Text(subtitle,
              style: const TextStyle(
                  fontSize: 12.5, color: AmialColors.textMuted, height: 1.4)),
          const SizedBox(height: 12),
          ...children,
        ]),
      ),
    );
  }

  Widget _field(TextEditingController c, String label,
      {Key? key, bool digitsOnly = false, int? maxLength}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextField(
        key: key,
        controller: c,
        obscureText: true,
        keyboardType: digitsOnly ? TextInputType.number : TextInputType.text,
        inputFormatters:
            digitsOnly ? [FilteringTextInputFormatter.digitsOnly] : null,
        maxLength: maxLength,
        decoration: InputDecoration(
          labelText: label,
          counterText: '',
          border: const OutlineInputBorder(),
          isDense: true,
        ),
      ),
    );
  }

  Widget _button(String label, Future<void> Function() onTap, {Key? key}) {
    return SizedBox(
      width: double.infinity,
      child: FilledButton(
        key: key,
        onPressed: _busy ? null : onTap,
        child: _busy
            ? const SizedBox(
                width: 18, height: 18,
                child: CircularProgressIndicator(strokeWidth: 2))
            : Text(label),
      ),
    );
  }
}
