import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/auth/widgets/governorate_picker.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-COVERAGE-002 — تحديد محافظة الإقامة.
///
/// كانت الشاشة الرئيسية تعرض «لم نتمكّن من تحديد محافظتك. حدّث عنوانك»،
/// وفيها خطآن: لا محاولة تحديد تقع أصلاً — الحقل يُقرأ من الملفّ الشخصي ولا
/// يُسأل عنه أحد — ولا سبيل في التطبيق إلى تحديث العنوان. نصيحةٌ إلى طريق
/// مسدود تبقى معروضة إلى الأبد.
///
/// **الترتيب مقصود:** الموقع أوّلاً لأنه ضغطة واحدة، والاختيار اليدوي تحته
/// دائماً لا كبديل عند الفشل — من يرفض إذن الموقع لا يُترك بلا طريق، ومن
/// يسكن قرب حدّ محافظتين يصحّح ما التقطه الجهاز.
class SetGovernorateSheet extends StatefulWidget {
  const SetGovernorateSheet({super.key});

  /// يعيد `true` إن حُدّدت المحافظة.
  static Future<bool> open(BuildContext context) async {
    final result = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const SetGovernorateSheet(),
    );

    return result ?? false;
  }

  @override
  State<SetGovernorateSheet> createState() => _SetGovernorateSheetState();
}

class _SetGovernorateSheetState extends State<SetGovernorateSheet> {
  String? _code;
  bool _locating = false;
  bool _saving = false;
  String? _error;
  String? _detected;

  ApiClient get _api => Get.find<ApiClient>();

  Future<void> _detect() async {
    setState(() {
      _locating = true;
      _error = null;
    });

    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        setState(() {
          _locating = false;
          _error = 'لم يُسمح بالوصول إلى الموقع. اختر محافظتك من القائمة.';
        });
        return;
      }

      if (!await Geolocator.isLocationServiceEnabled()) {
        setState(() {
          _locating = false;
          _error = 'خدمة الموقع مغلقة في جهازك. شغّلها أو اختر من القائمة.';
        });
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 20),
        ),
      );

      final r = await _api.postData('/api/v1/amial/geo/resolve-zone', {
        'latitude': position.latitude,
        'longitude': position.longitude,
      });

      final data = (r.body is Map) ? r.body['data'] : null;
      final code = (data is Map) ? data['governorate_code'] : null;

      if (code is! String || code.isEmpty) {
        setState(() {
          _locating = false;
          _error = 'تعذّر استنتاج المحافظة من موقعك. اخترها من القائمة.';
        });
        return;
      }

      setState(() {
        _locating = false;
        _code = code;
        _detected = GovernoratePicker.nameOf(code);
      });
    } catch (_) {
      setState(() {
        _locating = false;
        _error = 'تعذّر تحديد موقعك الآن. اختر محافظتك من القائمة.';
      });
    }
  }

  Future<void> _save() async {
    if (_code == null) {
      setState(() => _error = 'اختر محافظة أوّلاً');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      final r = await _api.postData(
          '/api/v1/amial/me/governorate', {'governorate_code': _code});

      final ok = r.statusCode == 200 &&
          r.body is Map &&
          r.body['success'] == true;

      if (!ok) {
        setState(() {
          _saving = false;
          _error = (r.body is Map ? r.body['message'] : null)?.toString() ??
              'تعذّر الحفظ';
        });
        return;
      }

      if (mounted) Navigator.of(context).pop(true);
    } catch (_) {
      setState(() {
        _saving = false;
        _error = 'تعذّر الاتصال. حاول مرة أخرى.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 22),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AmyalColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 16),

              const Text('حدّد محافظتك',
                  style: TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.bold,
                      color: AmyalColors.textPrimary)),
              const SizedBox(height: 6),
              const Text(
                'نستعملها لعرض الوكلاء والتجّار القريبين منك. لا تُغيّر رصيدك '
                'ولا صلاحياتك، ولا تُشارَك مع أحد.',
                style: TextStyle(
                    fontSize: 12.5, height: 1.7, color: AmyalColors.textSecondary),
              ),
              const SizedBox(height: 16),

              SizedBox(
                height: 48,
                child: OutlinedButton.icon(
                  onPressed: _locating || _saving ? null : _detect,
                  icon: _locating
                      ? const SizedBox(
                          width: 17,
                          height: 17,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.my_location_rounded, size: 19),
                  label: Text(_locating
                      ? 'جارٍ تحديد موقعك…'
                      : 'تحديدها من موقعي الحالي'),
                  style: OutlinedButton.styleFrom(
                      foregroundColor: AmyalColors.primary),
                ),
              ),

              if (_detected != null) ...[
                const SizedBox(height: 10),
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F5E9),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Row(children: [
                    const Icon(Icons.place_rounded,
                        size: 18, color: Color(0xFF2E7D32)),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text('يبدو أنك في محافظة $_detected',
                          style: const TextStyle(
                              fontSize: 12.5, color: Color(0xFF2E7D32))),
                    ),
                  ]),
                ),
              ],

              const SizedBox(height: 16),
              const Align(
                alignment: Alignment.centerRight,
                child: Text('أو اخترها بنفسك',
                    style: TextStyle(
                        fontSize: 12.5, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(height: 8),

              // التحديد بالموقع تقريبيّ بأقرب مركز، فيبقى التصحيح ممكناً.
              GovernoratePicker(
                value: _code,
                label: 'المحافظة',
                onChanged: (code) => setState(() {
                  _code = code;
                  _detected = null;
                  _error = null;
                }),
              ),

              if (_error != null) ...[
                const SizedBox(height: 10),
                Text(_error!,
                    style: const TextStyle(
                        color: AmyalColors.red, fontSize: 12.5, height: 1.6)),
              ],

              const SizedBox(height: 16),
              SizedBox(
                height: 50,
                child: ElevatedButton(
                  onPressed: _saving || _code == null ? null : _save,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                  ),
                  child: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Text('حفظ'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
