import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-GOVERNORATES-001 — قائمة المحافظات اليمنية.
///
/// المصدر الخادم (`/api/v1/amial/geo/governorates`) لا ثابت في التطبيق:
/// نطاق التشغيل يتغيّر مع خريطة السيطرة، وقائمة مطبوعة داخل APK تعني أن
/// كل تغيير يحتاج إصداراً جديداً ينتظره المستخدمون أسابيع.
///
/// نسخة احتياطية محلّية لأسماء المحافظات وحدها — بلا علامة نطاق الخدمة،
/// تلك يقرّرها الخادم وحده — كي لا يتعطّل التسجيل عند انقطاع الشبكة.
class GovernoratePicker extends StatefulWidget {
  const GovernoratePicker({
    super.key,
    required this.label,
    required this.value,
    required this.onChanged,
    this.helper,
  });

  final String label;
  final String? value;
  final ValueChanged<String?> onChanged;
  final String? helper;

  /// أسماء المحافظات الـ22 (21 محافظة + أمانة العاصمة، وسقطرى مستقلّة).
  static const List<Map<String, String>> fallback = [
    {'code': 'YE-AB', 'name': 'أبين'},
    {'code': 'YE-AD', 'name': 'عدن'},
    {'code': 'YE-AM', 'name': 'عمران'},
    {'code': 'YE-BA', 'name': 'البيضاء'},
    {'code': 'YE-DA', 'name': 'الضالع'},
    {'code': 'YE-DH', 'name': 'ذمار'},
    {'code': 'YE-HD', 'name': 'حضرموت'},
    {'code': 'YE-HJ', 'name': 'حجة'},
    {'code': 'YE-HU', 'name': 'الحديدة'},
    {'code': 'YE-IB', 'name': 'إب'},
    {'code': 'YE-JA', 'name': 'الجوف'},
    {'code': 'YE-LA', 'name': 'لحج'},
    {'code': 'YE-MA', 'name': 'مأرب'},
    {'code': 'YE-MR', 'name': 'المهرة'},
    {'code': 'YE-MW', 'name': 'المحويت'},
    {'code': 'YE-RA', 'name': 'ريمة'},
    {'code': 'YE-SA', 'name': 'أمانة العاصمة'},
    {'code': 'YE-SD', 'name': 'صعدة'},
    {'code': 'YE-SH', 'name': 'شبوة'},
    {'code': 'YE-SN', 'name': 'صنعاء'},
    {'code': 'YE-SU', 'name': 'سقطرى'},
    {'code': 'YE-TA', 'name': 'تعز'},
  ];

  /// تُجلب مرّة واحدة لكل تشغيل — القائمة لا تتغيّر أثناء الجلسة.
  static List<Map<String, dynamic>>? cache;

  /// اسم المحافظة من رمزها. يقرأ من المجلوب إن توفّر وإلا من الاحتياطي،
  /// فيصحّ قبل أول تحميل وبعده.
  static String? nameOf(String? code) {
    if (code == null || code.isEmpty) return null;

    for (final g in (cache ?? const <Map<String, dynamic>>[])) {
      if (g['code'] == code) return g['name'] as String?;
    }
    for (final g in fallback) {
      if (g['code'] == code) return g['name'];
    }
    return null;
  }

  @override
  State<GovernoratePicker> createState() => _GovernoratePickerState();
}

class _GovernoratePickerState extends State<GovernoratePicker> {
  List<Map<String, dynamic>> _items = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (GovernoratePicker.cache != null) {
      setState(() {
        _items = GovernoratePicker.cache!;
        _loading = false;
      });
      return;
    }

    try {
      final r = await Get.find<ApiClient>()
          .getData('/api/v1/amial/geo/governorates');
      final data = (r.body is Map) ? r.body['data'] : null;
      if (data is List && data.isNotEmpty) {
        GovernoratePicker.cache =
            data.map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
    } catch (_) {
      // نسقط على الاحتياطي — التسجيل لا يتوقّف لانقطاع شبكة.
    }

    if (!mounted) return;
    setState(() {
      _items = GovernoratePicker.cache ??
          GovernoratePicker.fallback
              .map((e) => <String, dynamic>{...e, 'in_service_area': null})
              .toList();
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: DropdownButtonFormField<String>(
        initialValue: widget.value,
        isExpanded: true,
        decoration: InputDecoration(
          labelText: widget.label,
          helperText: widget.helper,
          helperMaxLines: 2,
          border: const OutlineInputBorder(),
          suffixIcon: _loading
              ? const Padding(
                  padding: EdgeInsets.all(14),
                  child: SizedBox(
                      width: 16, height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2)),
                )
              : null,
        ),
        hint: Text(_loading ? 'جارٍ التحميل…' : 'اختر المحافظة'),
        items: _items.map((g) {
          // كل المحافظات معروضة: من أصله صنعاء ويسكن عدن يحتاج «صنعاء»
          // ليختارها محافظةَ أصل. إخفاؤها يدفعه لاختيار محافظة خاطئة،
          // فنفسد البيانات نفسها التي نراجعها.
          final inArea = g['in_service_area'];
          return DropdownMenuItem<String>(
            value: g['code'] as String,
            child: Row(children: [
              Expanded(child: Text(g['name'] as String)),
              if (inArea == true)
                const Icon(Icons.check_circle,
                    size: 16, color: Color(0xFF0F9D58)),
            ]),
          );
        }).toList(),
        onChanged: _loading ? null : widget.onChanged,
        style: const TextStyle(fontSize: 14, color: AmyalColors.textPrimary),
      ),
    );
  }
}
