import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';

/// AMIAL-AGENT-PORTAL-001 — متحكّم لوحة ويب الوكيل (مدير شركة الصرافة).
///
/// يجمّع بيانات: لوحة السيولة (الرصيد + حركة اليوم + تنبيه نقص السيولة)،
/// كشف حركة الرصيد، وسجل الشحن/التسويات.
class AgentPortalController extends GetxController implements GetxService {
  final AgentRepo repo;
  AgentPortalController({required this.repo});

  // لوحة التحكم
  final RxString currentFloat = '0'.obs;
  final Rx<Map<String, dynamic>> today = Rx<Map<String, dynamic>>({});
  final Rx<Map<String, dynamic>?> limits = Rx<Map<String, dynamic>?>(null);
  final RxBool lowFloat = false.obs;
  final RxnString agentLevel = RxnString();

  // كشف حركة الرصيد
  final RxList<Map<String, dynamic>> statementRows = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>> statementTotals = Rx<Map<String, dynamic>>({});
  final RxnString from = RxnString();
  final RxnString to = RxnString();

  // سجل الشحن/الخصومات (التسويات)
  final RxList<Map<String, dynamic>> settlements = <Map<String, dynamic>>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastMessage = ''.obs;

  bool _ok(Response r) => r.statusCode == 200 && r.body is Map && r.body['success'] == true;

  Future<void> loadAll() async {
    isLoading.value = true;
    lastError.value = '';
    try {
      await Future.wait([_loadDashboard(), _loadStatement(), _loadSettlements()]);
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> _loadDashboard() async {
    final r = await repo.floatDashboard();
    if (_ok(r)) {
      final m = Map<String, dynamic>.from(r.body['meta'] as Map);
      currentFloat.value = (m['current_float'] ?? '0').toString();
      today.value = Map<String, dynamic>.from((m['today'] ?? {}) as Map);
      limits.value = m['limits'] == null ? null : Map<String, dynamic>.from(m['limits'] as Map);
      lowFloat.value = m['low_float_warning'] == true;
      agentLevel.value = m['agent_level']?.toString();
    }
  }

  Future<void> _loadStatement() async {
    final r = await repo.floatStatement(from: from.value, to: to.value);
    if (_ok(r)) {
      final m = Map<String, dynamic>.from(r.body['meta'] as Map);
      statementRows.assignAll(((m['rows'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map)));
      statementTotals.value = Map<String, dynamic>.from((m['totals'] ?? {}) as Map);
      from.value = m['from']?.toString();
      to.value = m['to']?.toString();
    }
  }

  Future<void> _loadSettlements() async {
    final r = await repo.settlements();
    if (_ok(r)) {
      settlements.assignAll(((r.body['meta']?['settlements'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map)));
    }
  }

  Future<void> setRange(String? f, String? t) async {
    from.value = f;
    to.value = t;
    await _loadStatement();
  }

  /// طلب زيادة الرصيد من الإدارة.
  Future<bool> requestTopup(String amount, {String paymentMethod = 'cash', String? reference}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.requestTopup(amount: amount, paymentMethod: paymentMethod, paymentReference: reference);
      if (_ok(r)) {
        lastMessage.value = (r.body['message'] ?? 'تم إرسال الطلب').toString();
        await _loadSettlements();
        return true;
      }
      lastError.value = (r.body is Map ? r.body['message']?.toString() : null) ?? 'فشل الطلب';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }
}
