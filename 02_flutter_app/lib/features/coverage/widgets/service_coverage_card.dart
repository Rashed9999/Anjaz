import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-COVERAGE-005 — التغطية تظهر داخل الخدمة فقط.
///
/// الافتراضي: محافظة السكن المسجلة في KYC.
/// «موقعي الحالي» اختياري ومؤقت ولا يغيّر عنوان السكن.
class ServiceCoverageCard extends StatefulWidget {
  final String capability;
  final String serviceLabel;

  const ServiceCoverageCard({
    super.key,
    required this.capability,
    required this.serviceLabel,
  });

  @override
  State<ServiceCoverageCard> createState() => _ServiceCoverageCardState();
}

class _ServiceCoverageCardState extends State<ServiceCoverageCard> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  bool _locating = false;
  String? _error;
  bool _usingCurrentLocation = false;

  @override
  void initState() {
    super.initState();
    Future.microtask(_loadResidenceCoverage);
  }

  bool get _available {
    final d = _data;
    if (d == null) return true;
    return widget.capability == 'cash_out'
        ? d['can_cash_out'] == true
        : d['can_pay_merchant'] == true;
  }

  Future<void> _loadResidenceCoverage() async {
    try {
      final res = await Get.find<ApiClient>()
          .getData('/api/v1/amial/service-coverage');
      final d = res.body is Map ? res.body['data'] : null;
      if (res.statusCode == 200 && d is Map && mounted) {
        setState(() {
          _data = Map<String, dynamic>.from(d);
          _loading = false;
          _error = null;
          _usingCurrentLocation = false;
        });
        return;
      }
    } catch (_) {}

    if (mounted) {
      setState(() {
        _loading = false;
        _error = 'coverage_check_failed'.tr;
      });
    }
  }

  Future<void> _useCurrentLocation() async {
    if (_locating) return;
    setState(() {
      _locating = true;
      _error = null;
    });

    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission != LocationPermission.always &&
          permission != LocationPermission.whileInUse) {
        throw Exception('permission');
      }
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw Exception('disabled');
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 15),
        ),
      );

      final api = Get.find<ApiClient>();
      final zone = await api.postData('/api/v1/amial/geo/resolve-zone', {
        'latitude': position.latitude,
        'longitude': position.longitude,
      });
      final zoneData = zone.body is Map ? zone.body['data'] : null;
      final code = zoneData is Map
          ? zoneData['governorate_code']?.toString()
          : null;
      if (code == null || code.isEmpty) throw Exception('zone');

      final res = await api.getData(
        '/api/v1/amial/service-coverage',
        query: {'governorate': code},
      );
      final d = res.body is Map ? res.body['data'] : null;
      if (res.statusCode != 200 || d is! Map) throw Exception('coverage');

      if (mounted) {
        setState(() {
          _data = Map<String, dynamic>.from(d);
          _usingCurrentLocation = true;
          _locating = false;
          _error = null;
        });
      }
      return;
    } catch (_) {
      if (mounted) {
        setState(() {
          _locating = false;
          _error = 'coverage_location_failed_keep_residence'.tr;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const SizedBox.shrink();

    final data = _data;
    if (data == null) {
      return _card(
        icon: Icons.info_outline,
        title: 'coverage_check_failed_title'.tr,
        body: _error ?? 'coverage_retry_later'.tr,
        warning: true,
      );
    }

    final needsResidence = data['needs_governorate'] == true;
    final governorate = data['governorate']?.toString();

    if (_available && !_usingCurrentLocation) {
      return const SizedBox.shrink();
    }

    final title = _available
        ? 'coverage_service_available_current'.trParams({
            'service': widget.serviceLabel,
          })
        : needsResidence
            ? 'coverage_residence_missing'.tr
            : 'coverage_service_unavailable'.trParams({
                'service': widget.serviceLabel,
                'governorate': governorate ?? 'coverage_this_governorate'.tr,
              });

    final body = _available
        ? 'coverage_current_only_notice'.tr
        : needsResidence
            ? 'coverage_residence_default_notice'.tr
            : (_usingCurrentLocation
                ? 'coverage_basis_current_notice'.tr
                : 'coverage_basis_residence_notice'.tr);

    return _card(
      icon: _available
          ? Icons.check_circle_outline
          : Icons.location_on_outlined,
      title: title,
      body: _error ?? body,
      warning: !_available,
      action: !_available
          ? TextButton.icon(
              onPressed: _locating ? null : _useCurrentLocation,
              icon: _locating
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.my_location, size: 18),
              label: Text(
                _locating
                    ? 'coverage_locating'.tr
                    : 'coverage_use_current'.tr,
              ),
            )
          : null,
    );
  }

  Widget _card({
    required IconData icon,
    required String title,
    required String body,
    required bool warning,
    Widget? action,
  }) {
    final accent = warning ? AmialColors.yellowDark : Colors.green;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: warning
            ? AmialColors.yellow.withValues(alpha: 0.12)
            : Colors.green.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: accent.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            Icon(icon, color: accent, size: 20),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                title,
                textAlign: TextAlign.right,
                style: const TextStyle(
                  fontWeight: FontWeight.w700,
                  fontSize: 13,
                ),
              ),
            ),
          ]),
          const SizedBox(height: 6),
          Text(
            body,
            textAlign: TextAlign.right,
            style: const TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: AmialColors.textSecondary,
            ),
          ),
          if (action != null) ...[
            const SizedBox(height: 6),
            Align(alignment: Alignment.centerLeft, child: action),
          ],
        ],
      ),
    );
  }
}
