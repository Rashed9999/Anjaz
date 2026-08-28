import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/helper/amial_crash_reporter.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/auth/screens/role_router.dart';
import 'package:amial_pay/features/auth/screens/account_review_screen.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/data/api/secure_storage_helper.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amial_pay/features/auth/controllers/session_guard.dart';

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
    String? employeeCode,
  }) async {
    return _execute({
      'role': 'merchant',
      'merchant_number': merchantNumber,
      'phone': phone,
      'password': password,
      if (employeeCode != null && employeeCode.isNotEmpty) 'employee_code': employeeCode,
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
        await _persistLastLogin(meta);
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
        await _persistLastLogin(meta);
        currentRole.value = (meta['role'] ?? '').toString();
        // AMIAL-SESSION-GUARD-001: تصفير الحارس بعد دخول ناجح، وإلا حُسبت
        // مغادرة ما قبل الدخول على الجلسة الجديدة فتُغلق فوراً.
        SessionGuard.instance.reset();
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

    // AMIAL-CRASH-001: هنا تمرّ كل مسارات الدخول — العميل والتاجر والوكيل
    // والمدير — فالربط يقع مرّة واحدة بدل أربع.
    //
    // المعرّف هو المعرّف الداخلي لا رقم الهاتف ولا رقم الحساب: يكفي للبحث
    // في لوحة الإدارة عند التحقيق في عطل، ولا يدلّ على صاحبه لمن يقرأ لوحة
    // الأعطال. ورقم الهاتف يُنقّى من كل نصّ يُرفع (scrub) فلا يصل بطريق آخر.
    final user = (meta['user'] is Map) ? meta['user'] as Map : const {};
    await AmialCrashReporter.identify(
      account: user['id']?.toString(),
      role: (meta['role'] ?? '').toString(),
      zone: user['zone_code']?.toString(),
    );
  }

  // AMIAL-SEC-LOGIN-001: حفظ «آخر تسجيل دخول» محلياً — يعود من الخادم في الـ meta
  // (وقت + IP لآخر دخول سابق). نعرضه في شاشة الدخول التالية ليكتشف المستخدم أي
  // دخول غير مصرّح به. مفاتيح prefs ثابتة.
  static const String _kLastLoginAt = 'amial_last_login_at';
  static const String _kLastLoginIp = 'amial_last_login_ip';
  static const String _kLastLoginZone = 'amial_last_login_zone';

  Future<void> _persistLastLogin(Map meta) async {
    try {
      final ll = meta['last_login'];
      if (ll is Map) {
        final prefs = Get.find<SharedPreferences>();
        await prefs.setString(_kLastLoginAt, (ll['at'] ?? '').toString());
        await prefs.setString(_kLastLoginIp, (ll['ip'] ?? '').toString());
        await prefs.setString(_kLastLoginZone, (ll['zone'] ?? '').toString());
      }
    } catch (_) {/* غير حرج */}
  }

  // AMIAL-LOGIN-UI-003: «المستخدم الأخير» على هذا الجهاز — الاسم ورقم الجوال
  // ونوع الحساب. تعرضها شاشة الدخول لتُحيّي المستخدم باسمه وتُعبّئ رقمه، بدل
  // شاشة لا تعرف من يقف أمامها. لا تُحفظ كلمة المرور هنا إطلاقاً — البصمة
  // وحدها تحفظ بيانات الاعتماد وفي التخزين الآمن.
  static const String _kLastUserName = 'amial_last_user_name';
  static const String _kLastUserPhone = 'amial_last_user_phone';
  static const String _kLastUserKind = 'amial_last_user_kind';

  /// يحفظ هوية آخر مستخدم دخل بنجاح (بلا أي بيانات اعتماد).
  static Future<void> rememberLastUser({
    required String name,
    required String phone,
    required String kind,
  }) async {
    try {
      final prefs = Get.find<SharedPreferences>();
      await prefs.setString(_kLastUserName, name);
      await prefs.setString(_kLastUserPhone, phone);
      await prefs.setString(_kLastUserKind, kind);
    } catch (_) {/* غير حرج */}
  }

  /// يقرأ هوية آخر مستخدم — أو null إن لم يسبق دخول على هذا الجهاز.
  static ({String name, String phone, String kind})? readLastUser() {
    try {
      final prefs = Get.find<SharedPreferences>();
      final phone = prefs.getString(_kLastUserPhone) ?? '';
      final name = prefs.getString(_kLastUserName) ?? '';
      if (phone.isEmpty && name.isEmpty) return null;
      return (
        name: name,
        phone: phone,
        kind: prefs.getString(_kLastUserKind) ?? 'customer',
      );
    } catch (_) {
      return null;
    }
  }

  /// يمسح المستخدم الأخير — يُستدعى من «لست أنت؟» في شاشة الدخول.
  static Future<void> forgetLastUser() async {
    try {
      final prefs = Get.find<SharedPreferences>();
      await prefs.remove(_kLastUserName);
      await prefs.remove(_kLastUserPhone);
      await prefs.remove(_kLastUserKind);
    } catch (_) {/* غير حرج */}
  }

  /// يقرأ آخر تسجيل دخول محفوظ (at, ip, zone) — أو null إن كانت أوّل مرة.
  static ({String at, String ip, String zone})? readLastLogin() {
    try {
      final prefs = Get.find<SharedPreferences>();
      final at = prefs.getString(_kLastLoginAt) ?? '';
      if (at.isEmpty) return null;
      return (
        at: at,
        ip: prefs.getString(_kLastLoginIp) ?? '',
        zone: prefs.getString(_kLastLoginZone) ?? '',
      );
    } catch (_) {
      return null;
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
