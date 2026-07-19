import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MERCHANT-EXCEL-001 — تصدير دفتر التاجر إلى Excel (باقة الأعمال فأعلى).
///
/// يطلب توليد التقرير من الخادم (وظيفة غير متزامنة)، يستعلم عن الحالة، ثم يُنزّل
/// الملف الجاهز ويفتحه. تصدير حقيقي من بيانات الخادم.
class MerchantExcelExportScreen extends StatefulWidget {
  const MerchantExcelExportScreen({super.key});

  @override
  State<MerchantExcelExportScreen> createState() => _MerchantExcelExportScreenState();
}

enum _S { idle, requesting, processing, ready, failed, locked }

class _MerchantExcelExportScreenState extends State<MerchantExcelExportScreen> {
  final _api = Get.find<ApiClient>();
  _S _state = _S.idle;
  String _msg = '';
  String? _ulid;
  Timer? _poll;
  int _elapsed = 0;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _start() async {
    setState(() { _state = _S.requesting; _msg = ''; });
    try {
      final r = await _api.postData('/api/v1/amial/reports/request', {
        'report_type': 'merchant_ledger',
        'format': 'excel',
      });
      if (r.statusCode == 402) {
        setState(() { _state = _S.locked; _msg = 'تصدير Excel متاح في باقة الأعمال فأعلى'; });
        return;
      }
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        _ulid = '${(r.body['meta'] ?? {})['export_ulid'] ?? ''}';
        if (_ulid!.isEmpty) { _fail('لم يصل معرّف التصدير'); return; }
        setState(() { _state = _S.processing; _elapsed = 0; });
        _startPolling();
      } else {
        _fail((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر طلب التصدير');
      }
    } catch (_) {
      _fail('خطأ في الشبكة');
    }
  }

  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 2), (t) async {
      if (!mounted) { t.cancel(); return; }
      _elapsed += 2;
      if (_elapsed > 60) { t.cancel(); _fail('استغرق التجهيز وقتاً طويلاً — حاول لاحقاً'); return; }
      final r = await _api.getData('/api/v1/amial/reports/$_ulid/status');
      if (!mounted || r.statusCode != 200 || r.body is! Map) return;
      final status = '${(r.body['meta'] ?? {})['status'] ?? ''}';
      if (status == 'ready') {
        t.cancel();
        setState(() => _state = _S.ready);
      } else if (status == 'failed') {
        t.cancel();
        _fail('فشل توليد التقرير');
      }
    });
  }

  Future<void> _download() async {
    setState(() => _msg = 'جارٍ التنزيل…');
    try {
      final uri = Uri.parse('${_api.appBaseUrl}/api/v1/amial/reports/$_ulid/download');
      final resp = await http.get(uri, headers: {'Authorization': 'Bearer ${_api.token}'});
      if (resp.statusCode != 200) { setState(() => _msg = 'تعذّر التنزيل'); return; }
      final dir = await getApplicationDocumentsDirectory();
      final f = File('${dir.path}/merchant_ledger_${DateTime.now().millisecondsSinceEpoch}.xlsx');
      await f.writeAsBytes(resp.bodyBytes, flush: true);
      await OpenFile.open(f.path);
      setState(() => _msg = 'تم التنزيل ✓');
    } catch (_) {
      setState(() => _msg = 'تعذّر فتح الملف');
    }
  }

  void _fail(String m) => setState(() { _state = _S.failed; _msg = m; });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('تصدير Excel'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.grid_on, size: 72, color: Color(0xFF1D6F42)),
            const SizedBox(height: 16),
            const Text('تصدير دفتر المتجر إلى Excel',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            const Text('يشمل الحركات المالية للمتجر بصيغة جدول قابل للتحليل.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
            const SizedBox(height: 28),
            _body(),
            if (_msg.isNotEmpty) ...[
              const SizedBox(height: 14),
              Text(_msg, textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 13,
                      color: _state == _S.locked || _state == _S.failed ? AmyalColors.red : AmyalColors.primary,
                      fontWeight: FontWeight.w600)),
            ],
          ]),
        ),
      ),
    );
  }

  Widget _body() {
    switch (_state) {
      case _S.requesting:
      case _S.processing:
        return const Column(children: [
          CircularProgressIndicator(),
          SizedBox(height: 12),
          Text('جارٍ تجهيز الملف…', style: TextStyle(fontSize: 13)),
        ]);
      case _S.ready:
        return FilledButton.icon(
          onPressed: _download,
          icon: const Icon(Icons.download),
          label: const Text('تنزيل الملف'),
          style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFF1D6F42), minimumSize: const Size(240, 52)),
        );
      case _S.locked:
        return const Icon(Icons.workspace_premium, size: 40, color: AmyalColors.yellowDark);
      default:
        return FilledButton.icon(
          onPressed: _start,
          icon: const Icon(Icons.file_download_outlined),
          label: const Text('تصدير الآن'),
          style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary, minimumSize: const Size(240, 52)),
        );
    }
  }
}
