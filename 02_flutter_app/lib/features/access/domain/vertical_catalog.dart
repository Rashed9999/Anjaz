import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/domain/repositories/access_repo.dart';

/// AMIAL-VERTICAL-COMPOSE-001 — **قائمةُ القطاعات تُسأل ولا تُحفَر.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمنُ الذي وُلدت منه:** كانت القطاعاتُ الستّةُ مكتوبةً بأيديها في
/// **موضعين** في التطبيق — بطاقاتُ «نوع النشاط التجاري»، وقائمةُ
/// التسجيل. فقطاعٌ تُنشئه الإدارةُ اليوم يعمل في الخادم كاملاً **ولا
/// يستطيع تاجرٌ اختيارَه**: لا بطاقةَ له في شاشةٍ ولا سطرَ في قائمة.
/// وهو نمطُ العطل الأكثر تكراراً في أميال باي — مبنيٌّ ولا يُوصَل إليه.
///
/// **والشكلُ يبقى محلّيّاً والمحتوى يأتي من الخادم:** الستّةُ المبنيّةُ
/// تحتفظ بأيقوناتها وألوانها كما هي اليوم (فلا تتغيّر شاشةٌ يعرفها
/// المستعمل)، وما يُضاف يُرسم بما أرسله الخادم.
///
/// **وانقطاعُ الشبكة لا يُقفل بابَ التسجيل:** يُرجَع إلى الستّة، لأنّ
/// شاشةً فارغةً تمنع إنشاءَ الحساب أسوأ من قائمةٍ ناقصةٍ مؤقّتاً.
class VerticalOption {
  const VerticalOption({
    required this.code,
    required this.label,
    required this.description,
    required this.icon,
    required this.color,
  });

  final String code;
  final String label;
  final String description;
  final IconData icon;
  final Color color;
}

class VerticalCatalog {
  /// **الشكلُ المحفوظ للستّة المبنيّة** — وهو ما تعرضه الشاشتان اليوم
  /// حرفاً بحرف، منقولٌ كما هو لئلّا تتغيّر شاشةٌ بلا سبب.
  static const Map<String, VerticalOption> builtIn = {
    'quick_sale': VerticalOption(
        code: 'quick_sale', label: 'بيع سريع', description: 'أسماك، خضار، بسطات',
        icon: Icons.shopping_basket, color: Color(0xFF2196F3)),
    'retail': VerticalOption(
        code: 'retail', label: 'تجزئة', description: 'بقالة، سوبرماركت',
        icon: Icons.storefront, color: Color(0xFF4CAF50)),
    'fuel': VerticalOption(
        code: 'fuel', label: 'محطة وقود', description: 'بنزين، ديزل',
        icon: Icons.local_gas_station, color: Color(0xFF1B5E20)),
    'pharmacy': VerticalOption(
        code: 'pharmacy', label: 'صيدلية', description: 'أدوية + Batches',
        icon: Icons.local_pharmacy, color: Color(0xFF7B1FA2)),
    'wholesale': VerticalOption(
        code: 'wholesale', label: 'جملة', description: 'فواتير + حسابات آجلة',
        icon: Icons.warehouse, color: Color(0xFFE65100)),
    'restaurant': VerticalOption(
        code: 'restaurant', label: 'مطعم', description: 'طاولات + طلبات',
        icon: Icons.restaurant, color: Color(0xFF795548)),
  };

  /// **أيقوناتٌ ثابتةٌ بأسمائها** — ولا يُقرأ اسمُ أيقونةٍ من الخادم
  /// ديناميكيّاً: بناءُ الإصدار يقتطع ما لا يُذكَر بالاسم
  /// (`--tree-shake-icons`)، فتُرسَم مربّعاتٌ فارغة.
  ///
  /// **والقائمةُ هي بعينُها قائمةُ اللوحة** — يحرس تطابقَهما
  /// `AdminDefinedVerticalGuardTest`، فأيقونةٌ تُختار هناك ولا تُعرف هنا
  /// تُرسَم متجراً عامّاً بلا أن يعلم من اختارها.
  static const Map<String, IconData> icons = {
    'storefront': Icons.storefront,
    'bakery_dining': Icons.bakery_dining,
    'checkroom': Icons.checkroom,
    'menu_book': Icons.menu_book,
    'build': Icons.build,
    'spa': Icons.spa,
    'devices': Icons.devices,
    'shopping_basket': Icons.shopping_basket,
  };

  static Color _color(String? hex) {
    final v = (hex ?? '').replaceAll('#', '').trim();
    if (v.length != 6 && v.length != 8) return const Color(0xFF00A651);
    final n = int.tryParse(v.length == 6 ? 'FF$v' : v, radix: 16);
    return n == null ? const Color(0xFF00A651) : Color(n);
  }

  static List<VerticalOption> fallback() => builtIn.values.toList();

  /// **الدمجُ منفصلٌ عن الجلب** — فيُختبَر بلا شبكةٍ ولا حاوية.
  ///
  /// وهو الموضعُ الذي إن أخطأ **سقط القطاعُ المُضاف بصمت**: قائمةٌ تصل
  /// من الخادم فيها سبعةٌ وتُعرَض ستّة، ولا خطأَ في أيّ سجلّ.
  static List<VerticalOption> parse(dynamic rows) {
    if (rows is! List || rows.isEmpty) return fallback();

    final out = <VerticalOption>[];

    for (final raw in rows) {
      if (raw is! Map) continue;

      final code = raw['value']?.toString() ?? '';
      if (code.isEmpty) continue;

      final local = builtIn[code];

      if (local != null) {
        // مبنيٌّ — يُعرَض بشكله المعروف ولا يُعاد رسمُه من الخادم.
        out.add(local);
        continue;
      }

      out.add(VerticalOption(
        code: code,
        label: raw['short_label']?.toString() ?? raw['label']?.toString() ?? code,
        description: raw['hint']?.toString() ?? '',
        icon: icons[raw['icon']?.toString() ?? ''] ?? Icons.storefront,
        color: _color(raw['color']?.toString()),
      ));
    }

    return out.isEmpty ? fallback() : out;
  }

  /// القطاعاتُ المعروضةُ للاختيار — من الخادم، وإلى الستّة عند تعذّره.
  static Future<List<VerticalOption>> load() async {
    try {
      final res = await Get.find<AccessRepo>().businessTypes();

      return parse(res.body?['meta']?['business_types']);
    } catch (_) {
      return fallback();
    }
  }
}
