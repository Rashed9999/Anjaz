import 'package:get/get.dart';
import 'package:amyal_pay/features/admin/domain/repositories/admin_repo.dart';

/// AMIAL-WHATSAPP-OTP-001 — متحكّم إعدادات قناة واتساب (لوحة الأدمن).
class WhatsappSettingsController extends GetxController implements GetxService {
  final AdminRepo repo;
  WhatsappSettingsController({required this.repo});

  /// قائمة المزوّدين: [{provider, enabled, config:{...}}]
  final RxList<Map<String, dynamic>> providers = <Map<String, dynamic>>[].obs;
  final RxString channelPreference = 'whatsapp_first'.obs;
  final RxList<String> channels = <String>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastMessage = ''.obs;

  bool _ok(Response r) => r.statusCode == 200 && r.body is Map && r.body['success'] == true;

  Future<void> load() async {
    try {
      isLoading.value = true;
      lastError.value = '';
      final r = await repo.whatsappConfig();
      if (_ok(r)) {
        final meta = Map<String, dynamic>.from(r.body['meta'] as Map);
        providers.assignAll(((meta['providers'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)));
        channelPreference.value = (meta['channel_preference'] ?? 'whatsapp_first').toString();
        channels.assignAll(((meta['channels'] ?? []) as List).map((e) => e.toString()));
      } else {
        lastError.value = _msg(r) ?? 'تعذّر تحميل الإعدادات';
      }
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> saveProvider(String provider, bool status, Map<String, dynamic> config) async {
    return _submit(() => repo.saveWhatsappProvider(provider, status, config), reload: true);
  }

  Future<bool> setChannel(String value) async {
    final ok = await _submit(() => repo.setWhatsappChannel(value));
    if (ok) channelPreference.value = value;
    return ok;
  }

  Future<bool> testSend(String phone, {String? message}) async {
    return _submit(() => repo.whatsappTest(phone, message: message));
  }

  Future<bool> _submit(Future<Response> Function() action, {bool reload = false}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      lastMessage.value = '';
      final r = await action();
      if (_ok(r)) {
        lastMessage.value = (r.body['message'] ?? 'تم').toString();
        if (reload) await load();
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
