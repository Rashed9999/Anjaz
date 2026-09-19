import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class YemenResidenceSelection {
  final String? governorateCode;
  final int? districtId;
  final String? districtName;

  const YemenResidenceSelection({
    this.governorateCode,
    this.districtId,
    this.districtName,
  });

  bool get hasRequired =>
      governorateCode != null &&
      governorateCode!.isNotEmpty &&
      districtId != null;
}

/// AMIAL-YEMEN-REGIONS-002
///
/// عنوان العميل المنظم يقتصر على:
/// محافظة -> مديرية.
/// اسم الحي/المنطقة والعنوان التفصيلي يكتبهما العميل نصياً في الشاشة.
class YemenResidencePicker extends StatefulWidget {
  final ValueChanged<YemenResidenceSelection> onChanged;
  final bool enabled;

  const YemenResidencePicker({
    super.key,
    required this.onChanged,
    this.enabled = true,
  });

  @override
  State<YemenResidencePicker> createState() => _YemenResidencePickerState();
}

class _YemenResidencePickerState extends State<YemenResidencePicker> {
  String? _governorate;
  int? _districtId;
  List<Map<String, dynamic>> _districts = const [];
  bool _loadingDistricts = false;

  ApiClient get _api => Get.find<ApiClient>();

  Future<List<Map<String, dynamic>>> _loadDistricts(String governorate) async {
    final response = await _api.getData(
      '/api/v1/amial/geo/yemen/districts?governorate=$governorate',
    );
    if (response.statusCode != 200 || response.body is! Map) {
      throw Exception('district_lookup_failed');
    }

    final raw = response.body['data'];
    if (raw is! List) return const [];

    return raw
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .toList();
  }

  void _emit() {
    Map<String, dynamic>? district;
    for (final item in _districts) {
      if (_asInt(item['id']) == _districtId) {
        district = item;
        break;
      }
    }

    widget.onChanged(
      YemenResidenceSelection(
        governorateCode: _governorate,
        districtId: _districtId,
        districtName: district?['name_ar']?.toString(),
      ),
    );
  }

  Future<void> _selectGovernorate(String? value) async {
    setState(() {
      _governorate = value;
      _districtId = null;
      _districts = const [];
      _loadingDistricts = value != null;
    });
    _emit();

    if (value == null) return;

    try {
      final rows = await _loadDistricts(value);
      if (!mounted) return;
      setState(() => _districts = rows);
    } finally {
      if (mounted) setState(() => _loadingDistricts = false);
    }
  }

  void _selectDistrict(int? value) {
    setState(() => _districtId = value);
    _emit();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        GovernoratePicker(
          label: 'محافظة السكن الحالية',
          value: _governorate,
          helper:
              'اختر مكان إقامتك الفعلي. المحافظة غير المدعومة لا تمنع التسجيل أو التوثيق.',
          onChanged: widget.enabled ? (value) { _selectGovernorate(value); } : null,
        ),
        const SizedBox(height: 10),
        _districtDropdown(),
      ],
    );
  }

  Widget _districtDropdown() {
    if (_loadingDistricts) {
      return const InputDecorator(
        decoration: InputDecoration(
          labelText: 'المديرية *',
          border: OutlineInputBorder(),
        ),
        child: Row(
          children: [
            SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
            SizedBox(width: 10),
            Text('جاري تحميل المديريات...'),
          ],
        ),
      );
    }

    return DropdownButtonFormField<int>(
      value: _districtId,
      isExpanded: true,
      decoration: const InputDecoration(
        labelText: 'المديرية *',
        border: OutlineInputBorder(),
      ),
      hint: const Text('اختر المديرية'),
      items: _districts
          .map(
            (item) => DropdownMenuItem<int>(
              value: _asInt(item['id']),
              child: Text(item['name_ar']?.toString() ?? '—'),
            ),
          )
          .toList(),
      onChanged: widget.enabled && _governorate != null
          ? _selectDistrict
          : null,
    );
  }

  static int _asInt(dynamic value) => int.tryParse('$value') ?? 0;
}
