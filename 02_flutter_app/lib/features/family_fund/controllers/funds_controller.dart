import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:amial_pay/data/api/idempotent_intent.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amial_pay/features/family_fund/domain/repositories/funds_repo.dart';
import 'package:amial_pay/helper/amial_errors.dart';

/// AMIAL-FUND-FAMILY-001 (v0.9-D)
class FundsController extends GetxController with IdempotentIntent implements GetxService {
  final FundsRepo repo;
  FundsController({required this.repo});

  final RxList<AmialFundMembership> myMemberships = <AmialFundMembership>[].obs;
  final Rx<AmialFund?> selectedFund = Rx<AmialFund?>(null);
  final RxString selectedFundRole = ''.obs;
  final RxList<AmialFundTransaction> selectedFundTransactions = <AmialFundTransaction>[].obs;
  // AMIAL-DESIGN-16: أعضاء الصندوق مع مساهماتهم (يرجعها show أصلاً)
  final RxList<Map<String, dynamic>> selectedFundMembers = <Map<String, dynamic>>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxBool isLoadingMoreTx = false.obs;
  final RxBool hasMoreTransactions = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastDisbursementStatus = ''.obs;

  int _txLoadedPage = 0;

  Future<void> loadMyFunds() async {
    try {
      isLoading.value = true;
      final r = await repo.list();
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        myMemberships.value = items
            .map((j) => AmialFundMembership.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'تعذّر تحميل الصناديق';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadMyFunds error: $e');
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> createFund({
    required String name,
    String? description,
    bool requireOwnerApproval = true,
    String? targetAmount, // AMIAL-FUND-002
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.create(
        name: name,
        description: description,
        requireOwnerApproval: requireOwnerApproval,
        targetAmount: targetAmount,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        lastError.value = '';
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّر إتمام العملية';
      return false;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// AMIAL-FUND-003: دعوة عضو للصندوق برقم هاتفه مع دعم تحديد الدور.
  Future<bool> inviteMember({
    required String fundUlid,
    required String phone,
    String role = 'member',
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.invite(fundUlid: fundUlid, phone: phone, role: role);
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map && r.body['success'] == true) {
        lastError.value = '';
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّرت الدعوة';
      return false;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> loadFundDetails(String ulid) async {
    try {
      isLoading.value = true;
      lastError.value = '';
      // تصفير بيانات الصندوق السابق عند فتح صندوق جديد حتى لا تظهر بيانات قديمة
      if (selectedFund.value?.fundUlid != ulid) {
        selectedFund.value = null;
        selectedFundRole.value = '';
        selectedFundMembers.clear();
        selectedFundTransactions.clear();
        _txLoadedPage = 0;
        hasMoreTransactions.value = false;
      }

      final r = await repo.show(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedFund.value = AmialFund.fromJson(Map<String, dynamic>.from(meta['fund'] ?? {}));
        selectedFundRole.value = (meta['role'] ?? '').toString();
        final mem = meta['members'] as List? ?? [];
        selectedFundMembers.value = mem
            .whereType<Map>()
            .map((m) => Map<String, dynamic>.from(m))
            .toList();
        final txs = meta['recent_transactions'] as List? ?? [];
        selectedFundTransactions.value = txs
            .map((j) => AmialFundTransaction.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        _txLoadedPage = 0;
        hasMoreTransactions.value = selectedFundTransactions.length >= 20;
        lastError.value = '';
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّر تحميل تفاصيل الصندوق';
      return false;
    } catch (e) {
      if (kDebugMode) debugPrint('loadFundDetails error: $e');
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  /// تحميل المزيد من الحركات عبر نقطة transactions المرقّمة.
  Future<void> loadMoreTransactions(String fundUlid) async {
    if (isLoadingMoreTx.value || !hasMoreTransactions.value) return;
    try {
      isLoadingMoreTx.value = true;
      final nextPage = _txLoadedPage + 1;
      final r = await repo.transactions(fundUlid, page: nextPage);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        final items = (meta['items'] as List? ?? [])
            .map((j) => AmialFundTransaction.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        final pagination = meta['pagination'] is Map
            ? Map<String, dynamic>.from(meta['pagination'])
            : <String, dynamic>{};
        final total = int.tryParse('${pagination['total'] ?? 0}') ?? 0;

        if (nextPage == 1) {
          selectedFundTransactions.value = items;
        } else {
          final existingUlids = selectedFundTransactions.map((t) => t.txUlid).toSet();
          final fresh = items.where((t) => !existingUlids.contains(t.txUlid)).toList();
          selectedFundTransactions.addAll(fresh);
        }

        _txLoadedPage = nextPage;
        hasMoreTransactions.value =
            items.isNotEmpty && selectedFundTransactions.length < total;
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'تعذّر تحميل المزيد من الحركات';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadMoreTransactions error: $e');
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
    } finally {
      isLoadingMoreTx.value = false;
    }
  }

  Future<bool> contribute({
    required String fundUlid,
    required String amount,
    String? pin,
    String? note,
  }) async {
    if (isSubmitting.value) return false;
    final scope = jsonEncode([fundUlid, amount, note]);
    try {
      isSubmitting.value = true;
      final r = await repo.contribute(
        fundUlid: fundUlid,
        amount: amount,
        pin: pin,
        note: note,
        idempotencyKey: keyFor('fund_contribute', scope: scope),
      );
      settleKey('fund_contribute', r, scope: scope);
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        lastError.value = '';
        await loadFundDetails(fundUlid);
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّر إتمام العملية';
      return false;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// يُرجع 'completed' عند التنفيذ الفوري، أو 'pending_approval' عند انتظار موافقة المالك،
  /// أو null عند الفشل (مع تعبئة lastError).
  Future<String?> proposeDisbursement({
    required String fundUlid,
    required int beneficiaryUserId,
    required String amount,
    String? pin,
    String? note,
  }) async {
    if (isSubmitting.value) return null;
    final scope = jsonEncode([fundUlid, beneficiaryUserId, amount, note]);
    try {
      isSubmitting.value = true;
      lastDisbursementStatus.value = '';
      final r = await repo.proposeDisbursement(
        fundUlid: fundUlid,
        beneficiaryUserId: beneficiaryUserId,
        amount: amount,
        pin: pin,
        note: note,
        idempotencyKey: keyFor('fund_disburse', scope: scope),
      );
      settleKey('fund_disburse', r, scope: scope);
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        final meta = r.body['meta'] is Map ? Map<String, dynamic>.from(r.body['meta']) : {};
        final txMap = meta['transaction'] is Map ? Map<String, dynamic>.from(meta['transaction']) : {};
        final txStatus = txMap['status']?.toString() ??
            (r.body['code'] == 'DISBURSEMENT_PENDING' ? 'pending_approval' : 'completed');
        lastDisbursementStatus.value = txStatus;
        lastError.value = '';
        await loadFundDetails(fundUlid);
        await loadMyFunds();
        return txStatus;
      }
      lastError.value = _msg(r) ?? 'تعذّر إتمام العملية';
      return null;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> approveDisbursement(String fundUlid, String txUlid, String pin) async {
    if (isSubmitting.value) return false;
    try {
      isSubmitting.value = true;
      final r = await repo.approveDisbursement(
        txUlid,
        pin: pin,
        idempotencyKey: keyFor('fund_approve', scope: txUlid),
      );
      settleKey('fund_approve', r, scope: txUlid);
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        final meta = r.body['meta'];
        if (meta is Map && meta['approved'] == false) {
          lastError.value = 'تمت معالجة هذا الطلب مسبقاً';
          await loadFundDetails(fundUlid);
          return false;
        }
        lastError.value = '';
        await loadFundDetails(fundUlid);
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّر اعتماد طلب الصرف';
      return false;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> rejectDisbursement(String fundUlid, String txUlid, String reason) async {
    try {
      isSubmitting.value = true;
      final r = await repo.rejectDisbursement(txUlid, reason);
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        lastError.value = '';
        await loadFundDetails(fundUlid);
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّر رفض طلب الصرف';
      return false;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> acceptInvitation(int membershipId) async {
    try {
      isSubmitting.value = true;
      final r = await repo.acceptInvitation(membershipId);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        lastError.value = '';
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّر إتمام العملية';
      return false;
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) {
        // AMIAL-ERRORS-001: تعريب رسائل الخادم الإنجليزية
        return AmialErrors.arabize(
          r.body['message']?.toString(),
          code: r.body['code']?.toString(),
        );
      }
    } catch (_) {}
    return null;
  }
}
