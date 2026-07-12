import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amyal_pay/features/family_fund/domain/repositories/funds_repo.dart';

/// AMIAL-FUND-FAMILY-001 (v0.9-D)
class FundsController extends GetxController implements GetxService {
  final FundsRepo repo;
  FundsController({required this.repo});

  final RxList<AmyalFundMembership> myMemberships = <AmyalFundMembership>[].obs;
  final Rx<AmyalFund?> selectedFund = Rx<AmyalFund?>(null);
  final RxString selectedFundRole = ''.obs;
  final RxList<AmyalFundTransaction> selectedFundTransactions = <AmyalFundTransaction>[].obs;
  // AMIAL-DESIGN-16: أعضاء الصندوق مع مساهماتهم (يرجعها show أصلاً)
  final RxList<Map<String, dynamic>> selectedFundMembers = <Map<String, dynamic>>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  Future<void> loadMyFunds() async {
    try {
      isLoading.value = true;
      final r = await repo.list();
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        myMemberships.value = items
            .map((j) => AmyalFundMembership.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'Failed to load funds';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadMyFunds error: $e');
      lastError.value = 'Network error';
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
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'Failed';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// AMIAL-FUND-003: دعوة عضو للصندوق برقم هاتفه.
  Future<bool> inviteMember({
    required String fundUlid,
    required String phone,
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.invite(fundUlid: fundUlid, phone: phone);
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map && r.body['success'] == true) {
        lastError.value = '';
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذّرت الدعوة';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> loadFundDetails(String ulid) async {
    try {
      isLoading.value = true;
      final r = await repo.show(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedFund.value = AmyalFund.fromJson(Map<String, dynamic>.from(meta['fund'] ?? {}));
        selectedFundRole.value = (meta['role'] ?? '').toString();
        final mem = meta['members'] as List? ?? [];
        selectedFundMembers.value = mem
            .whereType<Map>()
            .map((m) => Map<String, dynamic>.from(m))
            .toList();
        final txs = meta['recent_transactions'] as List? ?? [];
        selectedFundTransactions.value = txs
            .map((j) => AmyalFundTransaction.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
        return true;
      }
      lastError.value = _msg(r) ?? 'Not found';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> contribute({
    required String fundUlid,
    required String amount,
    String? note,
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.contribute(fundUlid: fundUlid, amount: amount, note: note);
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        await loadFundDetails(fundUlid);
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'Failed';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> proposeDisbursement({
    required String fundUlid,
    required int beneficiaryUserId,
    required String amount,
    String? note,
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.proposeDisbursement(
        fundUlid: fundUlid,
        beneficiaryUserId: beneficiaryUserId,
        amount: amount,
        note: note,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        await loadFundDetails(fundUlid);
        return true;
      }
      lastError.value = _msg(r) ?? 'Failed';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
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
        await loadMyFunds();
        return true;
      }
      lastError.value = _msg(r) ?? 'Failed';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
