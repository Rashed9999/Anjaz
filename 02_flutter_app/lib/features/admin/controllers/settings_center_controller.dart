import 'package:get/get.dart';
import 'package:amyal_pay/features/admin/domain/repositories/admin_repo.dart';

/// AMIAL-SETTINGS-CENTER-001 — متحكّم مركز الإعدادات الموحّد.
class SettingsCenterController extends GetxController implements GetxService {
  final AdminRepo repo;
  SettingsCenterController({required this.repo});

  // SMS
  final RxList<Map<String, dynamic>> smsProviders = <Map<String, dynamic>>[].obs;
  // إشعارات واتساب
  final RxBool notifEnabled = false.obs;
  final RxList<String> notifTypes = <String>[].obs; // فارغة = all
  final RxList<String> knownTypes = <String>[].obs;
  // تواصل
  final RxMap<String, String> contact = <String, String>{}.obs;
  // رسوم
  final RxList<Map<String, dynamic>> feeSchemes = <Map<String, dynamic>>[].obs;
  final RxList<String> feeCodes = <String>[].obs;
  final RxList<String> feeTypes = <String>[].obs;
  final RxList<String> feeBearers = <String>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastMessage = ''.obs;

  bool _ok(Response r) => r.statusCode == 200 && r.body is Map && r.body['success'] == true;

  Future<void> loadAll() async {
    isLoading.value = true;
    lastError.value = '';
    try {
      await Future.wait([_loadSms(), _loadNotifications(), _loadContact(), _loadFees()]);
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> _loadSms() async {
    final r = await repo.smsConfig();
    if (_ok(r)) {
      smsProviders.assignAll(((r.body['meta']['providers'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map)));
    }
  }

  Future<void> _loadNotifications() async {
    final r = await repo.notificationsConfig();
    if (_ok(r)) {
      final meta = r.body['meta'] as Map;
      notifEnabled.value = meta['enabled'] == true;
      final t = meta['types'];
      notifTypes.assignAll(t is List ? t.map((e) => e.toString()) : const <String>[]);
      knownTypes.assignAll(((meta['known_types'] ?? []) as List).map((e) => e.toString()));
    }
  }

  Future<void> _loadContact() async {
    final r = await repo.contactConfig();
    if (_ok(r)) {
      contact.assignAll(Map<String, String>.from(
          (r.body['meta']['contact'] as Map).map((k, v) => MapEntry(k.toString(), v.toString()))));
    }
  }

  Future<void> _loadFees() async {
    final r = await repo.feesList();
    if (_ok(r)) {
      final meta = r.body['meta'] as Map;
      feeSchemes.assignAll(((meta['schemes'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map)));
      feeCodes.assignAll(((meta['codes'] ?? []) as List).map((e) => e.toString()));
      feeTypes.assignAll(((meta['fee_types'] ?? []) as List).map((e) => e.toString()));
      feeBearers.assignAll(((meta['bearers'] ?? []) as List).map((e) => e.toString()));
    }
  }

  Future<bool> saveSms(String provider, bool status, Map<String, dynamic> config) =>
      _submit(() => repo.saveSmsProvider(provider, status, config), after: _loadSms);

  Future<bool> saveNotifications() => _submit(() => repo.saveNotificationsConfig(
        notifEnabled.value,
        notifTypes.isEmpty ? 'all' : notifTypes.toList(),
      ));

  Future<bool> saveContact(Map<String, dynamic> data) =>
      _submit(() => repo.saveContactConfig(data), after: _loadContact);

  Future<bool> createFee(Map<String, dynamic> scheme) =>
      _submit(() => repo.createFee(scheme), after: _loadFees);

  Future<Map<String, dynamic>?> simulateFee(Map<String, dynamic> scheme, String amount) async {
    try {
      final r = await repo.simulateFee(scheme, amount);
      if (_ok(r)) return Map<String, dynamic>.from(r.body['meta']['simulation'] as Map);
      lastError.value = _msg(r) ?? 'فشل المحاكاة';
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    }
    return null;
  }

  Future<bool> deactivateFee(int id) =>
      _submit(() => repo.deactivateFee(id), after: _loadFees);

  Future<bool> _submit(Future<Response> Function() action, {Future<void> Function()? after}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
      if (_ok(r)) {
        lastMessage.value = (r.body['message'] ?? 'تم').toString();
        if (after != null) await after();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل العملية';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}
