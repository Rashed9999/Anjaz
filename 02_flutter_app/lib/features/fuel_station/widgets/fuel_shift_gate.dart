import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-FUEL-SHIFT-GATE-001 — باب وردية المحطة أمام شاشة بيع الوقود.
///
/// وردية الوقود محطة-كاملة وليست درج الكاشير العام؛ لذلك لا نستخدم
/// ShiftGate الخاص بالتجزئة/الصيدلية، ولا نعطي الكاشير حق فتح وردية
/// المحطة. إذا لم تكن هناك وردية مفتوحة، يطلب من المشرف فتحها ثم يحدّث.
class FuelShiftGate extends StatefulWidget {
  const FuelShiftGate({super.key, required this.child});

  final Widget child;

  @override
  State<FuelShiftGate> createState() => _FuelShiftGateState();
}

class _FuelShiftGateState extends State<FuelShiftGate> {
  final ApiClient _api = Get.find<ApiClient>();

  bool _loading = true;
  bool _hasOpenShift = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _check();
  }

  Future<void> _check() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final response =
          await _api.getData('/api/v1/amial/merchant/fuel/shifts/current');

      if (response.statusCode == 200 && response.body is Map) {
        final body = Map<String, dynamic>.from(response.body as Map);
        final meta = body['meta'] is Map
            ? Map<String, dynamic>.from(body['meta'] as Map)
            : <String, dynamic>{};
        final shift = meta['shift'];

        if (mounted) {
          setState(() => _hasOpenShift = shift is Map);
        }
      } else {
        final message = response.body is Map
            ? response.body['message']?.toString()
            : null;
        if (mounted) {
          setState(() {
            _hasOpenShift = false;
            _error = message != null && message.isNotEmpty
                ? message
                : 'تعذّر التحقق من وردية المحطة';
          });
        }
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _hasOpenShift = false;
          _error = 'تعذّر الاتصال بالخادم للتحقق من وردية المحطة';
        });
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(
        backgroundColor: AmialColors.background,
        body: Center(
          child: CircularProgressIndicator(color: AmialColors.primary),
        ),
      );
    }

    if (_hasOpenShift) return widget.child;

    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('بيع الوقود'),
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
      ),
      body: RefreshIndicator(
        onRefresh: _check,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(24),
          children: [
            const SizedBox(height: 90),
            Icon(
              _error == null
                  ? Icons.lock_clock_outlined
                  : Icons.cloud_off_outlined,
              size: 68,
              color: _error == null
                  ? AmialColors.yellowDark
                  : AmialColors.textMuted,
            ),
            const SizedBox(height: 18),
            Text(
              _error == null
                  ? 'لا توجد وردية محطة مفتوحة'
                  : 'تعذّر التحقق من الوردية',
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 19,
                fontWeight: FontWeight.w900,
                color: AmialColors.textPrimary,
              ),
            ),
            const SizedBox(height: 10),
            Text(
              _error ??
                  'بيع الوقود مرتبط بورديّة المحطة والمضخات. اطلب من مشرف الوردية أو مدير المحطة فتح الوردية، ثم اضغط تحديث. حساب الكاشير لا يفتح وردية المحطة من تلقاء نفسه.',
              textAlign: TextAlign.center,
              style: const TextStyle(
                height: 1.65,
                color: AmialColors.textSecondary,
              ),
            ),
            const SizedBox(height: 22),
            FilledButton.icon(
              onPressed: _check,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('تحديث حالة الوردية'),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(52),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
