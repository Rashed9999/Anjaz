import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/kyc_verification/controllers/verification_center_controller.dart';
import 'package:amial_pay/features/kyc_verification/domain/reposotories/verification_center_repo.dart';
import 'package:amial_pay/features/kyc_verification/screens/complete_my_account_screen.dart';
import 'package:amial_pay/features/kyc_verification/domain/customer_verification_level.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

/// يظهر للعميل الفرد فقط من UserInfoWidget.
/// التاجر والوكيل وموظفو POS/الإدارة لا يُنشأ لهم هذا الـWidget أصلاً.
class CustomerVerificationPanel extends StatefulWidget {
  const CustomerVerificationPanel({super.key});

  @override
  State<CustomerVerificationPanel> createState() => _CustomerVerificationPanelState();
}

class _CustomerVerificationPanelState extends State<CustomerVerificationPanel> {
  @override
  void initState() {
    super.initState();
    if (!Get.isRegistered<VerificationCenterController>()) {
      Get.put(
        VerificationCenterController(
          repo: VerificationCenterRepo(apiClient: Get.find<ApiClient>()),
        ),
      );
    }
    Future.microtask(() => Get.find<VerificationCenterController>().load());
  }

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VerificationCenterController>(
      builder: (controller) {
        if (controller.isLoading && controller.data == null) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 22),
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (controller.data == null) return const SizedBox.shrink();

        final tier = _map(controller.data?['tier']);
        final bar = _map(controller.data?['usage_bar']);
        final current = controller.currentTier;
        final nextTier = current >= 3 ? 3 : current + 1;

        return Padding(
          padding: const EdgeInsets.only(top: 16, bottom: 6),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _usageCard(context, controller, tier, bar),
              const SizedBox(height: 12),
              _verificationCallout(context, controller, current, nextTier),
              const SizedBox(height: 16),
              Row(
                children: [
                  const Expanded(
                    child: Text(
                      'حالات توثيق العميل',
                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                    ),
                  ),
                  Text(
                    'حالتك: ${tier['name'] ?? CustomerVerificationLevel.unverified.label}',
                    style: const TextStyle(fontSize: 11.5, color: Color(0xFF637083)),
                  ),
                ],
              ),
              const SizedBox(height: 9),
              ...controller.levels.map(
                (level) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _levelCard(context, controller, level),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _usageCard(
    BuildContext context,
    VerificationCenterController controller,
    Map<String, dynamic> tier,
    Map<String, dynamic> bar,
  ) {
    final current = int.tryParse('${tier['current'] ?? 0}') ?? 0;
    final usedRaw = double.tryParse('${bar['used'] ?? 0}') ?? 0;
    final limitRaw = double.tryParse('${bar['limit'] ?? 0}') ?? 0;
    final used = _money(bar['used']);
    final limit = _money(bar['limit']);
    final limits = _map(tier['limits']);
    final inactive = current <= 0 || limitRaw <= 0;

    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: const Color(0xFFF7F9FC),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFDDE4ED)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.speed_outlined, size: 21, color: Color(0xFF315F95)),
              const SizedBox(width: 8),
              const Expanded(
                child: Text(
                  'حدود استخدام حسابك',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                ),
              ),
              _statusBadge(current),
            ],
          ),
          const SizedBox(height: 12),
          if (inactive) ...[
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF8E6),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFF1D997)),
              ),
              child: const Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.lock_outline, size: 19, color: Color(0xFF8A6515)),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'حدودك المالية غير مفعّلة بعد. أكمل متطلبات حالة عميل موثق جزئيا لتفعيل الاستخدام المالي وحدوده.',
                      style: TextStyle(fontSize: 12.5, height: 1.45, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
            if (usedRaw > 0) ...[
              const SizedBox(height: 10),
              Text(
                'حركة مالية مسجلة هذا الشهر: $used ر.ي',
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 3),
              const Text(
                'تُعرض الحركة المسجلة للحقيقة التاريخية فقط، ولا تعني أن حالة التوثيق الحالية تملك حداً مالياً.',
                style: TextStyle(fontSize: 11.5, height: 1.4, color: Color(0xFF657184)),
              ),
            ],
          ] else ...[
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: LinearProgressIndicator(
                value: controller.usageProgress,
                minHeight: 10,
                backgroundColor: const Color(0xFFE4E9F0),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'استخدمت $used ر.ي من حد شهري $limit ر.ي',
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                _miniLimit('للعملية', limits['max_single_transaction']),
                _miniLimit('يومي', limits['max_daily_total']),
                _miniLimit('الرصيد', limits['max_balance']),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _verificationCallout(
    BuildContext context,
    VerificationCenterController controller,
    int current,
    int nextTier,
  ) {
    final complete = current >= 3;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: complete ? const Color(0xFFEFF8F2) : const Color(0xFFFFF8E6),
        border: Border.all(
          color: complete ? const Color(0xFFC9E7D4) : const Color(0xFFF1D997),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            complete ? 'حسابك موثّق بالكامل' : 'التوثيق يرفع حدود استخدامك',
            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
          ),
          const SizedBox(height: 5),
          Text(
            complete
                ? 'يمكنك مراجعة حدودك ومزايا حالة توثيقك من القائمة أدناه.'
                : 'اختر حالة التوثيق التي تريد الوصول إليها، وستطلب صفحة «إكمال حسابي» منك فقط البيانات التي ما زالت ناقصة.',
            style: const TextStyle(fontSize: 12.5, height: 1.45, color: Color(0xFF5C6675)),
          ),
          if (!complete) ...[
            const SizedBox(height: 10),
            FilledButton.icon(
              onPressed: () => _openComplete(controller, nextTier),
              icon: const Icon(Icons.verified_user_outlined, size: 19),
              label: const Text('وثّق حسابك وارفع الحدود'),
            ),
          ],
        ],
      ),
    );
  }

  Widget _levelCard(
    BuildContext context,
    VerificationCenterController controller,
    Map<String, dynamic> level,
  ) {
    final completed = level['status'] == 'completed';
    final current = level['current'] == true;
    final requirements = _listOfMaps(level['requirements']);
    final benefits = _listOfStrings(level['benefits']);
    final limits = _map(level['limits']);
    final tier = int.tryParse('${level['tier']}') ?? 0;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: current ? const Color(0xFFF2F6FC) : Colors.white,
        borderRadius: BorderRadius.circular(15),
        border: Border.all(
          color: current
              ? CustomerVerificationLevel.fromTier(tier).color
              : const Color(0xFFE0E5EC),
          width: current ? 1.4 : 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: CustomerVerificationLevel.fromTier(tier).color,
                ),
                child: Icon(
                  completed ? Icons.check : Icons.shield_outlined,
                  size: 19,
                  color: CustomerVerificationLevel.fromTier(tier).foreground,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${level['name'] ?? ''}',
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                    ),
                    Text(
                      '${level['description'] ?? ''}',
                      style: const TextStyle(fontSize: 11.5, color: Color(0xFF677386)),
                    ),
                  ],
                ),
              ),
              if (current)
                const Chip(
                  label: Text('الحالي', style: TextStyle(fontSize: 10.5)),
                  visualDensity: VisualDensity.compact,
                ),
            ],
          ),
          const SizedBox(height: 10),
          _line('الحد الشهري', '${_money(limits['max_monthly_total'])} ر.ي'),
          _line('حد العملية', '${_money(limits['max_single_transaction'])} ر.ي'),
          const Divider(height: 18),
          const Text('المزايا', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
          const SizedBox(height: 4),
          ...benefits.map((item) => _bullet(item, true)),
          const SizedBox(height: 8),
          const Text('المتطلبات', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
          const SizedBox(height: 4),
          ...requirements.map(
            (r) => _requirement(
              '${r['label'] ?? ''}',
              r['complete'] == true,
            ),
          ),
          const SizedBox(height: 10),
          if (completed)
            OutlinedButton.icon(
              onPressed: null,
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('مكتمل'),
            )
          else
            FilledButton(
              onPressed: () => _openComplete(controller, tier),
              child: const Text('إكمال حسابي'),
            ),
        ],
      ),
    );
  }

  Widget _statusBadge(int tier) {
    final level = CustomerVerificationLevel.fromTier(tier);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: level.color,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        level.label,
        style: TextStyle(
          fontSize: 10.5,
          fontWeight: FontWeight.w800,
          color: level.foreground,
        ),
      ),
    );
  }

  Future<void> _openComplete(VerificationCenterController controller, int tier) async {
    await Get.to(() => CompleteMyAccountScreen(targetTier: tier));
    await controller.load(silent: true);
  }

  Widget _miniLimit(String title, dynamic value) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(9),
          border: Border.all(color: const Color(0xFFE1E6ED)),
        ),
        child: Text('$title: ${_money(value)}', style: const TextStyle(fontSize: 10.8)),
      );

  Widget _line(String key, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            Expanded(child: Text(key, style: const TextStyle(fontSize: 11.5, color: Color(0xFF657184)))),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 11.5)),
          ],
        ),
      );

  Widget _bullet(String label, bool benefit) => Padding(
        padding: const EdgeInsets.only(bottom: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(benefit ? Icons.add_circle_outline : Icons.circle, size: 14, color: const Color(0xFF466D9E)),
            const SizedBox(width: 6),
            Expanded(child: Text(label, style: const TextStyle(fontSize: 11.5, height: 1.35))),
          ],
        ),
      );

  Widget _requirement(String label, bool complete) => Padding(
        padding: const EdgeInsets.only(bottom: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              complete ? Icons.check_circle : Icons.radio_button_unchecked,
              size: 15,
              color: complete ? const Color(0xFF208154) : const Color(0xFF8A96A5),
            ),
            const SizedBox(width: 6),
            Expanded(child: Text(label, style: const TextStyle(fontSize: 11.5, height: 1.35))),
          ],
        ),
      );

  static String _money(dynamic value) {
    final number = double.tryParse('${value ?? 0}') ?? 0;
    return NumberFormat('#,##0.##', 'en').format(number);
  }

  static Map<String, dynamic> _map(dynamic value) =>
      value is Map ? Map<String, dynamic>.from(value) : <String, dynamic>{};

  static List<Map<String, dynamic>> _listOfMaps(dynamic value) {
    if (value is! List) return const [];
    return value.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
  }

  static List<String> _listOfStrings(dynamic value) {
    if (value is! List) return const [];
    return value.map((e) => e.toString()).toList();
  }
}
