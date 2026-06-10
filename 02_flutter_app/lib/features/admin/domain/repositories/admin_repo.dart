import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// CRITICAL-001-ADMIN — Repo لوحة الإدارة.
class AdminRepo extends GetxService {
  final ApiClient apiClient;
  AdminRepo({required this.apiClient});

  static const _base = '/api/v1/amial/admin';

  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  Future<Response> listMerchants({
    String? search, String? plan, String? businessType,
    String? verificationStatus, int page = 1,
  }) {
    final q = <String, dynamic>{'per_page': '20'};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (plan != null && plan.isNotEmpty) q['plan'] = plan;
    if (businessType != null && businessType.isNotEmpty) q['business_type'] = businessType;
    if (verificationStatus != null && verificationStatus.isNotEmpty) q['verification_status'] = verificationStatus;
    return apiClient.getData('$_base/merchants', query: q);
  }

  Future<Response> pendingVariances() => apiClient.getData('$_base/variances/pending');

  Future<Response> resolveVariance(int id, String resolution, String? note) =>
      apiClient.postData('$_base/variances/$id/resolve', {
        'resolution': resolution,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  Future<Response> verifyMerchant(int id, String action, String? note) =>
      apiClient.postData('$_base/merchants/$id/verify', {
        'action': action,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  // إعادة استخدام endpoints القديمة من AccessController
  Future<Response> updateMerchantPlan(int id, String plan, {String? expiresAt, String? notes}) =>
      apiClient.postData('$_base/merchants/$id/plan', {
        'plan': plan,
        'expires_at': ?expiresAt,
        'notes': ?notes,
      });

  // AMIAL-WHATSAPP-OTP-001 — إعدادات قناة واتساب
  Future<Response> whatsappConfig() => apiClient.getData('$_base/whatsapp/config');

  Future<Response> saveWhatsappProvider(String provider, bool status, Map<String, dynamic> config) =>
      apiClient.postData('$_base/whatsapp/provider', {
        'provider': provider,
        'status': status,
        'config': config,
      });

  Future<Response> setWhatsappChannel(String value) =>
      apiClient.postData('$_base/whatsapp/channel', {'value': value});

  Future<Response> whatsappTest(String phone, {String? message}) =>
      apiClient.postData('$_base/whatsapp/test', {
        'phone': phone,
        if (message != null && message.isNotEmpty) 'message': message,
      });

  // AMIAL-SETTINGS-CENTER-001 — مركز الإعدادات الموحّد
  Future<Response> smsConfig() => apiClient.getData('$_base/settings/sms');

  Future<Response> saveSmsProvider(String provider, bool status, Map<String, dynamic> config) =>
      apiClient.postData('$_base/settings/sms/provider',
          {'provider': provider, 'status': status, 'config': config});

  Future<Response> notificationsConfig() => apiClient.getData('$_base/settings/notifications');

  Future<Response> saveNotificationsConfig(bool enabled, dynamic types) =>
      apiClient.postData('$_base/settings/notifications', {'enabled': enabled, 'types': types});

  Future<Response> contactConfig() => apiClient.getData('$_base/settings/contact');

  Future<Response> saveContactConfig(Map<String, dynamic> contact) =>
      apiClient.postData('$_base/settings/contact', contact);

  Future<Response> feesList() => apiClient.getData('$_base/settings/fees');

  Future<Response> createFee(Map<String, dynamic> scheme) =>
      apiClient.postData('$_base/settings/fees', scheme);

  Future<Response> simulateFee(Map<String, dynamic> scheme, String amount) =>
      apiClient.postData('$_base/settings/fees/simulate', {'scheme': scheme, 'amount': amount});

  Future<Response> deactivateFee(int id) =>
      apiClient.postData('$_base/settings/fees/$id/deactivate', {});
}
