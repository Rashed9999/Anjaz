import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/auth/screens/role_router.dart';
import 'package:amyal_pay/features/auth/screens/account_review_screen.dart';
import 'package:amyal_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amyal_pay/data/api/secure_storage_helper.dart';

/// AMIAL-UNIFIED-AUTH-001 (v1.5)
class UnifiedAuthRepo {
  final ApiClient apiClient;
  UnifiedAuthRepo({required this.apiClient});

  Future<Response> login(Map<String, dynamic> body) {
    return apiClient.postData('/api/v1/auth/login', body);
  }

  Future<Response> verifyOtp(String otpToken, String otpCode) {
    return apiClient.postData('/api/v1/auth/agent/verify-otp', {
      'otp_token': otpToken,
      'otp_code': otpCode,
    });
  }
}

class UnifiedAuthController extends GetxController implements GetxService {
  final UnifiedAuthRepo repo;
  UnifiedAuthController({required this.repo});

  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString currentRole = ''.obs;
  String? _pendingOtpToken;

  // AMIAL-VERIFY-GATE: حالة توثيق آخر دخول ناجح (يقرؤها التوجيه لفتح شاشة
  // «قيد المراجعة»/«مرفوض» بدل الرئيسية للحساب غير المعتمد).
  // pending_review | verified | rejected
  final RxString verificationState = 'verified'.obs;
  String _displayName = '';
  String get displayName => _displayName;

  // ===== Customer =====
  Future<bool> loginCustomer({
    required String nationalId,
    required String phone,
    required String password,
  }) async {
    return _execute({
      'role': 'customer',
      'national_id': nationalId,
      'phone': phone,
      'password': password,
    });
  }

  // ===== Merchant =====
  Future<bool> loginMerchant({
    required String merchantNumber,
    required String phone,
    required String password,
    String? posNumber,
  }) async {
    return _execute({
      'role': 'merchant',
      'merchant_number': merchantNumber,
      'phone': phone,
      'password': password,
      if (posNumber != null && posNumber.isNotEmpty) 'pos_number': posNumber,
    });
  }

  // ===== Admin (بريد + كلمة مرور) =====
  Future<bool> loginAdmin({
    required String email,
    required String password,
  }) async {
    return _execute({
      'role': 'admin',
      'email': email,
      'password': password,
    });
  }

  // ===== Agent Step 1 (returns masked_phone if OTP sent) =====
  Future<String?> loginAgentStep1({
    required String agentNumber,
    required String password,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.login({
        'role': 'agent',
        'agent_number': agentNumber,
        'password': password,
      });
      if (r.statusCode == 200 && r.body is Map && r.body['code'] == 'OTP_SENT') {
        final meta = r.body['meta'] ?? {};
        _pendingOtpToken = meta['otp_token'];
        return (meta['masked_phone'] ?? '').toString();
      }
      lastError.value = _failureMessage(r, 'فشل تسجيل الدخول');
      return null;
    } catch (e) {
      if (kDebugMode) debugPrint('agent step1: $e');
      lastError.value = 'خطأ في الشبكة';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ===== Agent Step 2 =====
  Future<bool> loginAgentStep2(String otpCode) async {
    if (_pendingOtpToken == null) {
      lastError.value = 'انتهت صلاحية الجلسة، أعد الإرسال';
      return false;
    }
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.verifyOtp(_pendingOtpToken!, otpCode);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = r.body['meta'] ?? {};
        await _saveAuth(meta);
        currentRole.value = (meta['role'] ?? 'agent').toString();
        _pendingOtpToken = null;
        // CRITICAL-001 — حمّل access بعد تسجيل الدخول الناجح
        try { await Get.find<AccessController>().load(); } catch (_) {}
        return true;
      }
      lastError.value = _failureMessage(r, 'رمز غير صحيح');
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ===== Common execute =====
  Future<bool> _execute(Map<String, dynamic> body) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.login(body);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = r.body['meta'] ?? {};
        await _saveAuth(meta);
        currentRole.value = (meta['role'] ?? '').toString();
        // AMIAL-VERIFY-GATE: التقط حالة التوثيق واسم المستخدم من الاستجابة
        final user = (meta['user'] is Map) ? meta['user'] as Map : const {};
        verificationState.value =
            (user['verification_state'] ?? 'verified').toString();
        _displayName = (user['name'] ?? '').toString();
        // CRITICAL-001 — حمّل access بعد تسجيل الدخول الناجح
        try { await Get.find<AccessController>().load(); } catch (_) {}
        return true;
      }
      lastError.value = _failureMessage(r, 'فشل تسجيل الدخول');
      return false;
    } catch (e) {
      if (kDebugMode) debugPrint('login: $e');
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> _saveAuth(Map meta) async {
    final token = meta['token']?.toString();
    if (token != null && token.isNotEmpty) {
      try {
        // AMIAL-FIX(POST-LOGIN): حرج — لا يكفي حفظ الرمز في المخزن الآمن؛ يجب
        // ضبطه في ترويسة ApiClient أيضاً، وإلّا خرجت كلّ طلبات الشاشة الرئيسية
        // بلا Authorization → 401 → ApiChecker يحوّل لشاشة PIN القديمة (6cash).
        // saveUserToken يفعل الأمرين معاً (updateHeader + المخزن الآمن).
        await Get.find<AuthController>().authRepo.saveUserToken(token);
      } catch (_) {
        // احتياط: لو تعذّر حلّ AuthController، اضبط الترويسة والمخزن مباشرةً.
        try { repo.apiClient.updateHeader(token); } catch (_) {}
        try { await SecureStorageHelper.instance.setToken(token); } catch (_) {}
      }
    }
  }

  /// توجيه للشاشة الرئيسية حسب الدور الحالي.
  /// AMIAL-PIN-GATE-001: بعد الدخول تظهر بوّابة رمز PIN قبل فتح الرئيسية.
  Future<void> navigateToHomeForRole() async {
    if (currentRole.value.isEmpty) return;

    // AMIAL-VERIFY-GATE: الحساب غير المعتمد (قيد المراجعة/مرفوض) لا يفتح
    // الرئيسية — يذهب لشاشة الحالة الصريحة بدل تجربة ناقصة صامتة. الأدمن
    // مستثنى (لا يخضع لتوثيق KYC).
    if (currentRole.value != 'admin' &&
        verificationState.value != 'verified') {
      Get.offAll(() => AccountReviewScreen(
            state: verificationState.value,
            userName: _displayName,
          ));
      return;
    }

    // AMIAL-ADMIN: مدير النظام يدخل بالبريد وكلمة المرور فقط — بوابة PIN
    // الرقمية (4 أرقام) للمعاملات المالية للعملاء/التجار/الوكلاء.
    if (currentRole.value != 'admin') {
      final ok = await askAmialPin(title: 'رمز الدخول للتطبيق');
      if (!ok) return; // بقي على شاشة الدخول
    }
    RoleRouter.navigateToHome(currentRole.value);
  }

  /// AMIAL-FIX(LOGIN): رسالة خطأ دقيقة بدل «فشل تسجيل الدخول» العامّة.
  /// تميّز بين: انقطاع الشبكة، VPN، تجاوز المحاولات، خطأ الخادم، ورسالة الخادم.
  String _failureMessage(Response r, String fallback) {
    // رسالة الخادم إن وُجدت (JSON فيه message) — الأدقّ دائماً
    try {
      if (r.body is Map && r.body['message'] != null) {
        final m = r.body['message'].toString();
        if (m.isNotEmpty) return m;
      }
    } catch (_) {}

    switch (r.statusCode) {
      case -1:
        return 'أوقف VPN ثم حاول مجدداً';
      case 1:
      case 0:
      case null:
        return 'تعذّر الاتصال بالخادم — تحقّق من اتصال الإنترنت';
      case 429:
        return 'محاولات كثيرة — انتظر قليلاً ثم أعد المحاولة';
      case 401:
        return 'بيانات الدخول غير صحيحة';
      case 422:
        return 'تحقّق من صحّة البيانات المُدخلة';
    }
    if (r.statusCode != null && r.statusCode! >= 500) {
      return 'خطأ مؤقّت في الخادم — أعد المحاولة بعد لحظات';
    }
    return fallback;
  }
}
