import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/domain/models/agent_models.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';

/// AMIAL-AGENT-APP-001 (v1.6)
class AgentController extends GetxController implements GetxService {
  final AgentRepo repo;
  AgentController({required this.repo});

  // ====== State ======
  final Rx<AmyalAgent?> agent = Rx<AmyalAgent?>(null);
  final Rx<AmyalAgentDashboardStats> stats = AmyalAgentDashboardStats.empty().obs;
  final RxList<AmyalAgentTransaction> transactions = <AmyalAgentTransaction>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastSuccessMessage = ''.obs;

  // ====== Profile ======
  Future<void> loadProfile() async {
    try {
      isLoading.value = true;
      final r = await repo.getProfile();
      if (r.statusCode == 200 && r.body is Map) {
        final body = r.body as Map;
        final data = body['meta'] ?? body['data'] ?? body;
        if (data is Map) {
          agent.value = AmyalAgent.fromJson(Map<String, dynamic>.from(data));
        }
      } else {
        lastError.value = _msg(r) ?? 'فشل تحميل الملف الشخصي';
      }
      // تحميل الإحصاءات من endpoint منفصل (v1.7)
      await _loadDailyStats();
    } catch (e) {
      if (kDebugMode) debugPrint('loadProfile: $e');
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> _loadDailyStats() async {
    try {
      final r = await repo.dailyStats();
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        if (meta is Map) {
          stats.value = AmyalAgentDashboardStats.fromJson(
              Map<String, dynamic>.from(meta));
        }
      }
    } catch (e) {
      if (kDebugMode) debugPrint('_loadDailyStats: $e');
    }
  }

  // ====== Cash In ======
  Future<bool> cashIn({
    required String customerPhone,
    required String amount,
    required String pin,
    String? note,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.cashIn(
        customerPhone: customerPhone,
        amount: amount,
        pin: pin,
        note: note,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          (r.body['success'] == true ||
              r.body['message']?.toString().toLowerCase().contains('success') == true)) {
        lastSuccessMessage.value = 'تم الإيداع للعميل بنجاح';
        await loadProfile();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشلت العملية';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ====== Cash Out Request ======
  Future<bool> requestCashOut({
    required String customerPhone,
    required String amount,
    String? note,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.requestCashOut(
        customerPhone: customerPhone,
        amount: amount,
        note: note,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          (r.body['success'] == true ||
              r.body['message']?.toString().toLowerCase().contains('success') == true)) {
        lastSuccessMessage.value = 'تم إرسال طلب السحب للعميل. ينتظر موافقته.';
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الطلب';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ====== Transactions ======
  Future<void> loadTransactions({String? type}) async {
    try {
      isLoading.value = true;
      final r = await repo.transactionHistory(type: type);
      if (r.statusCode == 200 && r.body is Map) {
        final list = (r.body['data'] ?? r.body['meta']?['data'] ?? []) as List;
        transactions.value = list
            .map((j) => AmyalAgentTransaction.fromJson(Map<String, dynamic>.from(j)))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadTransactions: $e');
    } finally {
      isLoading.value = false;
    }
  }

  // ====== Check customer before cash-in ======
  Future<Map<String, dynamic>?> checkCustomer(String phone) async {
    try {
      final r = await repo.checkCustomer(phone);
      if (r.statusCode == 200 && r.body is Map && r.body['data'] is Map) {
        return Map<String, dynamic>.from(r.body['data']);
      }
      return null;
    } catch (e) {
      return null;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}
