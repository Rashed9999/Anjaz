import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-RECEIPT-SETTINGS-001 — تحميل/حفظ إعدادات فاتورة التاجر وتخزينها مؤقتاً.
///
/// تُحمّل مرّة وتُقرأ من كل شاشات الفاتورة الموحّدة. تُرجع افتراضيات آمنة قبل
/// أول تحميل حتى لا تتأخّر الفاتورة.
class ReceiptSettingsController extends GetxController {
  ReceiptSettingsController({ApiClient? api}) : _api = api ?? Get.find<ApiClient>();
  final ApiClient _api;

  static const _base = '/api/v1/amial/merchant/receipt-settings';

  final RxMap<String, dynamic> settings = <String, dynamic>{}.obs;
  final RxList<Map<String, dynamic>> currencies = <Map<String, dynamic>>[].obs;
  final RxBool isLoaded = false.obs;
  final RxBool isSaving = false.obs;

  static Map<String, dynamic> get defaults => {
        'header_note': '',
        'footer_note': 'شكراً لتعاملكم معنا',
        'phone': '',
        'address': '',
        'show_logo': true,
        'show_phone': true,
        'show_address': true,
        'paper_width': 80,
        'auto_print_receipts': false,
        'currency_label': 'ر.ي',
        'store_name': 'المتجر',
        'logo_url': null,
      };

  /// خريطة جاهزة للاستخدام (الإعدادات المحمّلة أو الافتراضيات).
  Map<String, dynamic> get effective =>
      {...defaults, ...settings};

  Future<void> load({bool force = false}) async {
    if (isLoaded.value && !force) return;
    try {
      final r = await _api.getData(_base);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        settings.assignAll(Map<String, dynamic>.from((meta['settings'] ?? {}) as Map));
        currencies.assignAll(((meta['currencies'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
        isLoaded.value = true;
      }
    } catch (_) {/* الافتراضيات تكفي */}
  }

  Future<bool> save(Map<String, dynamic> patch) async {
    try {
      isSaving.value = true;
      final r = await _api.postData(_base, patch);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final s = ((r.body['meta'] ?? {})['settings'] ?? {}) as Map;
        settings.assignAll(Map<String, dynamic>.from(s));
        // احتفظ برابط الشعار إن لم يعده الحفظ
        if (settings['logo_url'] == null && patch['logo_url'] != null) {
          settings['logo_url'] = patch['logo_url'];
        }
        isLoaded.value = true;
        return true;
      }
      return false;
    } catch (_) {
      return false;
    } finally {
      isSaving.value = false;
    }
  }

  Future<String?> uploadLogo(String base64) async {
    try {
      final r = await _api.postData('$_base/logo', {'logo': base64});
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final url = ((r.body['meta'] ?? {})['logo_url'])?.toString();
        if (url != null) settings['logo_url'] = url;
        return url;
      }
    } catch (_) {}
    return null;
  }
}
