import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class YemenResidenceSelection {
  final String? governorateCode;
  final int? districtId;
  final int? uzlahId;
  final int? villageId;
  final String? districtName;
  final String? uzlahName;
  final String? villageName;

  const YemenResidenceSelection({
    this.governorateCode,
    this.districtId,
    this.uzlahId,
    this.villageId,
    this.districtName,
    this.uzlahName,
    this.villageName,
  });

  bool get hasRequired =>
      governorateCode != null &&
      governorateCode!.isNotEmpty &&
      districtId != null;
}

/// AMIAL-YEMEN-REGIONS-001
///
/// اختيار كسول للعناوين اليمنية:
/// محافظة -> مديرية -> عزلة/منطقة -> قرية/حي.
///
/// المصدر الحقيقي في الخادم، ولا نحمّل 41 ألف قرية داخل حزمة Flutter.
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
  int? _uzlahId;
  int? _villageId;

  List<Map<String, dynamic>> _districts = const [];
  List<Map<String, dynamic>> _uzaal = const [];
  List<Map<String, dynamic>> _villages = const [];

  bool _loadingDistricts = false;
  bool _loadingUzaal = false;
  bool _loadingVillages = false;

  ApiClient get _api => Get.find<ApiClient>();

  Future<List<Map<String, dynamic>>> _load(String path) async {
    final response = await _api.getData(path);
    if (response.statusCode != 200 || response.body is! Map) {
      throw Exception('location_lookup_failed');
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
    Map<String, dynamic>? uzlah;
    Map<String, dynamic>? village;

    for (final item in _districts) {
      if (_asInt(item['id']) == _districtId) {
        district = item;
        break;
      }
    }
    for (final item in _uzaal) {
      if (_asInt(item['id']) == _uzlahId) {
        uzlah = item;
        break;
      }
    }
    for (final item in _villages) {
      if (_asInt(item['id']) == _villageId) {
        village = item;
        break;
      }
    }

    widget.onChanged(
      YemenResidenceSelection(
        governorateCode: _governorate,
        districtId: _districtId,
        uzlahId: _uzlahId,
        villageId: _villageId,
        districtName: district?['name_ar']?.toString(),
        uzlahName: uzlah?['name_ar']?.toString(),
        villageName: village?['name_ar']?.toString(),
      ),
    );
  }

  Future<void> _selectGovernorate(String? value) async {
    setState(() {
      _governorate = value;
      _districtId = null;
      _uzlahId = null;
      _villageId = null;
      _districts = const [];
      _uzaal = const [];
      _villages = const [];
      _loadingDistricts = value != null;
    });
    _emit();

    if (value == null) return;

    try {
      final rows = await _load(
        '/api/v1/amial/geo/yemen/districts?governorate=$value',
      );
      if (!mounted) return;
      setState(() => _districts = rows);
    } finally {
      if (mounted) setState(() => _loadingDistricts = false);
    }
  }

  Future<void> _selectDistrict(int? value) async {
    setState(() {
      _districtId = value;
      _uzlahId = null;
      _villageId = null;
      _uzaal = const [];
      _villages = const [];
      _loadingUzaal = value != null;
    });
    _emit();

    if (value == null) return;

    try {
      final rows = await _load(
        '/api/v1/amial/geo/yemen/uzaal?district_id=$value',
      );
      if (!mounted) return;
      setState(() => _uzaal = rows);
    } finally {
      if (mounted) setState(() => _loadingUzaal = false);
    }
  }

  Future<void> _selectUzlah(int? value) async {
    setState(() {
      _uzlahId = value;
      _villageId = null;
      _villages = const [];
      _loadingVillages = value != null;
    });
    _emit();

    if (value == null) return;

    try {
      final rows = await _load(
        '/api/v1/amial/geo/yemen/villages?uzlah_id=$value',
      );
      if (!mounted) return;
      setState(() => _villages = rows);
    } finally {
      if (mounted) setState(() => _loadingVillages = false);
    }
  }

  void _selectVillage(int? value) {
    setState(() => _villageId = value);
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
          onChanged: widget.enabled ? _selectGovernorate : null,
        ),
        const SizedBox(height: 10),
        _dropdown(
          label: 'المديرية',
          value: _districtId,
          rows: _districts,
          loading: _loadingDistricts,
          enabled: widget.enabled && _governorate != null,
          requiredField: true,
          onChanged: _selectDistrict,
        ),
        if (_districtId != null && (_loadingUzaal || _uzaal.isNotEmpty)) ...[
          const SizedBox(height: 10),
          _dropdown(
            label: 'العزلة / المنطقة — اختياري',
            value: _uzlahId,
            rows: _uzaal,
            loading: _loadingUzaal,
            enabled: widget.enabled,
            onChanged: _selectUzlah,
          ),
        ],
        if (_uzlahId != null &&
            (_loadingVillages || _villages.isNotEmpty)) ...[
          const SizedBox(height: 10),
          _dropdown(
            label: 'القرية / الحي — اختياري',
            value: _villageId,
            rows: _villages,
            loading: _loadingVillages,
            enabled: widget.enabled,
            onChanged: _selectVillage,
          ),
        ],
      ],
    );
  }

  Widget _dropdown({
    required String label,
    required int? value,
    required List<Map<String, dynamic>> rows,
    required bool loading,
    required bool enabled,
    required ValueChanged<int?> onChanged,
    bool requiredField = false,
  }) {
    if (loading) {
      return InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
        ),
        child: const Row(
          children: [
            SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
            SizedBox(width: 10),
            Text('جاري تحميل الخيارات...'),
          ],
        ),
      );
    }

    return DropdownButtonFormField<int>(
      value: value,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: requiredField ? '$label *' : label,
        border: const OutlineInputBorder(),
      ),
      items: [
        if (!requiredField)
          const DropdownMenuItem<int>(
            value: null,
            child: Text('بدون تحديد'),
          ),
        ...rows.map(
          (item) => DropdownMenuItem<int>(
            value: _asInt(item['id']),
            child: Text(item['name_ar']?.toString() ?? '—'),
          ),
        ),
      ],
      onChanged: enabled ? onChanged : null,
    );
  }

  static int _asInt(dynamic value) => int.tryParse('$value') ?? 0;
}
