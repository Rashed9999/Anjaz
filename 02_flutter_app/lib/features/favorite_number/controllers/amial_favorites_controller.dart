import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-FAVORITES-001 — أنواع المفضّلة.
class FavKind {
  static const contact = 'contact';
  static const account = 'account';
  static const operation = 'operation';
  static const merchant = 'merchant';

  static const labels = {
    contact: 'جهات الاتصال',
    account: 'أرقام الحسابات',
    operation: 'عمليات محفوظة',
    merchant: 'تجّار',
  };
}

class FavoriteItem {
  FavoriteItem({
    required this.id,
    required this.kind,
    required this.value,
    required this.label,
    this.metadata = const {},
  });

  final int id;
  final String kind;
  final String value;
  final String label;
  final Map<String, dynamic> metadata;

  factory FavoriteItem.fromJson(Map<String, dynamic> j) => FavoriteItem(
        id: (j['id'] ?? 0) as int,
        kind: (j['kind'] ?? FavKind.contact).toString(),
        value: (j['value'] ?? '').toString(),
        label: (j['label'] ?? '').toString(),
        metadata: j['metadata'] is Map
            ? Map<String, dynamic>.from(j['metadata'] as Map)
            : const {},
      );
}

/// حالة المفضّلة في التطبيق.
///
/// **لماذا ذاكرة محلّية للحالة:** النجمة تظهر في قوائم فيها عشرات العناصر.
/// سؤال الخادم عن كل عنصر يعني عشرات النداءات لرسم شاشة واحدة. نسأل مرّة
/// عن كل القيم المعروضة (`check`) ونحتفظ بالنتيجة، ثم نُحدّثها تفاؤلياً
/// عند الضغط ونتراجع إن فشل النداء — الضغطة يجب أن تُرى فوراً.
class AmialFavoritesController extends GetxController {
  final _api = Get.find<ApiClient>();

  /// القيم المفضّلة لكل نوع — للاستعلام السريع أثناء بناء القوائم.
  final RxMap<String, Set<String>> _favored = <String, Set<String>>{}.obs;

  final RxList<FavoriteItem> items = <FavoriteItem>[].obs;
  final RxBool loading = false.obs;

  bool isFavorite(String kind, String value) =>
      _favored[kind]?.contains(_norm(kind, value)) ?? false;

  /// تطبيع الهاتف محلّياً بنفس منطق الخادم — وإلا اختلفت النجمة عن الحقيقة
  /// حين يُعرض الرقم بصيغة والقيمة محفوظة بأخرى.
  String _norm(String kind, String value) {
    final v = value.trim();
    if (kind != FavKind.contact) return v;

    var digits = v.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.startsWith('00')) digits = digits.substring(2);
    if (digits.startsWith('967')) digits = digits.substring(3);
    digits = digits.replaceFirst(RegExp(r'^0+'), '');
    return '967$digits';
  }

  Future<void> loadAll() async {
    loading.value = true;
    try {
      final r = await _api.getData('/api/v1/amial/favorites');
      final data = (r.body is Map) ? r.body['data'] : null;
      if (data is List) {
        items.assignAll(
          data.map((e) => FavoriteItem.fromJson(Map<String, dynamic>.from(e as Map))),
        );
        _favored.clear();
        for (final f in items) {
          _favored.putIfAbsent(f.kind, () => <String>{}).add(f.value);
        }
        _favored.refresh();
      }
    } catch (_) {
      // المفضّلة تحسينية — لا توقف الشاشة التي تستضيفها.
    } finally {
      loading.value = false;
    }
  }

  /// يسأل عن حالة عدّة قيم دفعةً واحدة قبل رسم قائمة.
  Future<void> primeStatuses(String kind, List<String> values) async {
    if (values.isEmpty) return;
    try {
      final r = await _api.postData('/api/v1/amial/favorites/check', {
        'kind': kind,
        'values': values,
      });
      final data = (r.body is Map) ? r.body['data'] : null;
      if (data is List) {
        _favored[kind] = data.map((e) => e.toString()).toSet();
        _favored.refresh();
      }
    } catch (_) {}
  }

  /// يبدّل الحالة. يعيد `true` إن صارت مفضّلة.
  Future<bool?> toggle({
    required String kind,
    required String value,
    String? label,
    Map<String, dynamic>? metadata,
  }) async {
    final key = _norm(kind, value);
    final wasFavorite = isFavorite(kind, value);

    // تحديث تفاؤلي: الضغطة تُرى فوراً، ونتراجع إن رفض الخادم.
    _apply(kind, key, !wasFavorite);

    try {
      final r = await _api.postData('/api/v1/amial/favorites/toggle', {
        'kind': kind,
        'value': value,
        if (label != null) 'label': label,
        if (metadata != null) 'metadata': metadata,
      });

      final body = r.body;
      if (body is Map && body['success'] == true) {
        final nowFav = body['favorited'] == true;
        _apply(kind, key, nowFav);
        return nowFav;
      }

      _apply(kind, key, wasFavorite);
      Get.snackbar('المفضّلة',
          (body is Map ? body['message'] : null)?.toString() ?? 'تعذّر التعديل');
      return null;
    } catch (_) {
      _apply(kind, key, wasFavorite);
      return null;
    }
  }

  Future<void> remove(int id) async {
    try {
      await _api.deleteData('/api/v1/amial/favorites/$id');
      items.removeWhere((f) => f.id == id);
      _favored.clear();
      for (final f in items) {
        _favored.putIfAbsent(f.kind, () => <String>{}).add(f.value);
      }
      _favored.refresh();
    } catch (_) {}
  }

  void _apply(String kind, String key, bool favored) {
    final set = _favored.putIfAbsent(kind, () => <String>{});
    favored ? set.add(key) : set.remove(key);
    _favored.refresh();
  }
}
