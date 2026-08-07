import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amial_pay/features/safe_payment/domain/repositories/safe_payment_repo.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class SafePaymentController extends GetxController implements GetxService {
  final SafePaymentRepo repo;
  SafePaymentController({required this.repo});

  final RxList<AmialSafePayment> payments = <AmialSafePayment>[].obs;
  final Rx<AmialSafePayment?> selectedPayment = Rx<AmialSafePayment?>(null);
  final Rx<AmialSafePaymentActions> availableActions =
      AmialSafePaymentActions.empty().obs;
  final RxString yourRole = ''.obs; // buyer | seller

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ===== AMIAL-SAFEPAY-EVIDENCE-001 / CODE-001 / TRUST-001 =====

  /// رمز التسليم — يصل للمشتري وحده ويبقى null عند البائع.
  final RxString deliveryCode = ''.obs;
  final RxBool deliveryCodeVerified = false.obs;

  /// أدلّة العملية مجموعةً بالمرحلة.
  final RxMap<String, List<AmialEvidenceItem>> evidence =
      <String, List<AmialEvidenceItem>>{}.obs;

  final Rx<AmialTrustSummary?> counterpartyTrust = Rx<AmialTrustSummary?>(null);

  /// أسباب النزاع — تُجلب مرّة وتبقى.
  final RxList<AmialDisputeReason> disputeReasons = <AmialDisputeReason>[].obs;

  Future<void> loadList({String role = 'all', String? status}) async {
    try {
      isLoading.value = true;
      final r = await repo.list(role: role, status: status);
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        payments.value = items
            .map((j) => AmialSafePayment.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'فشل التحميل';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadList: $e');
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> loadDetail(String ulid) async {
    try {
      isLoading.value = true;
      final r = await repo.show(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedPayment.value = AmialSafePayment.fromJson(
            Map<String, dynamic>.from(meta['payment'] ?? {}));
        yourRole.value = (meta['your_role'] ?? '').toString();
        availableActions.value = AmialSafePaymentActions.fromJson(
            Map<String, dynamic>.from(meta['can_actions'] ?? {}));

        deliveryCode.value = (meta['delivery_code'] ?? '').toString();
        deliveryCodeVerified.value = meta['delivery_code_verified'] == true;

        counterpartyTrust.value = meta['counterparty_trust'] is Map
            ? AmialTrustSummary.fromJson(
                Map<String, dynamic>.from(meta['counterparty_trust']))
            : null;

        _absorbEvidence(meta['evidence']);

        lastError.value = '';
        return true;
      }
      lastError.value = _msg(r) ?? 'غير موجود';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> create({
    required String sellerPhone,
    required String title,
    required String description,
    required String amount,
    String? deliveryTerms,
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.create(
        sellerPhone: sellerPhone,
        title: title,
        description: description,
        amount: amount,
        deliveryTerms: deliveryTerms,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        await loadList();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الإنشاء';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ============ Seller actions ============
  Future<bool> sellerAccept(String ulid, {String? note}) =>
      _executeAction(() => repo.sellerAccept(ulid, note: note), ulid);

  Future<bool> sellerReject(String ulid, String reason) =>
      _executeAction(() => repo.sellerReject(ulid, reason), ulid);

  Future<bool> sellerMarkInDelivery(String ulid, {String? note}) =>
      _executeAction(() => repo.sellerMarkInDelivery(ulid, note: note), ulid);

  Future<bool> sellerMarkDelivered(String ulid, {String? note}) =>
      _executeAction(() => repo.sellerMarkDelivered(ulid, note: note), ulid);

  // ============ Buyer actions ============
  Future<bool> buyerConfirm(String ulid) =>
      _executeAction(() => repo.buyerConfirm(ulid), ulid);

  Future<bool> buyerCancel(String ulid, String reason) =>
      _executeAction(() => repo.buyerCancel(ulid, reason), ulid);

  Future<bool> buyerDispute(
    String ulid,
    String reason, {
    String? reasonCode,
    List<String>? attachments,
  }) =>
      _executeAction(
          () => repo.buyerDispute(ulid, reason,
              reasonCode: reasonCode, attachments: attachments),
          ulid);

  // ============ AMIAL-SAFEPAY-CODE-001 ============

  /// البائع يؤكّد التسليم برمز المشتري.
  ///
  /// لا يمرّ عبر `_executeAction` لأن الفشل هنا ليس فشلاً عابراً: للمشتري
  /// ثلاث محاولات فقط، فرسالة الخادم (كم بقي) يجب أن تصل للبائع حرفياً.
  Future<bool> verifyDelivery(String ulid, String code) =>
      _executeAction(() => repo.verifyDelivery(ulid, code), ulid);

  // ============ AMIAL-SAFEPAY-EVIDENCE-001 ============

  Future<void> loadDisputeReasons() async {
    if (disputeReasons.isNotEmpty) return;
    try {
      final r = await repo.disputeReasons();
      if (r.statusCode == 200 && r.body is Map && r.body['data'] is List) {
        disputeReasons.value = (r.body['data'] as List)
            .map((j) => AmialDisputeReason.fromJson(Map<String, dynamic>.from(j)))
            .toList();
      }
    } catch (_) {
      // القائمة تكميلية: النزاع يُفتح بنصّ حرّ حتى لو تعذّر جلبها.
    }
  }

  Future<void> refreshEvidence(String ulid) async {
    try {
      final r = await repo.evidence(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        _absorbEvidence(r.body['data']);
      }
    } catch (_) {
      // الصمت مقصود: فشل تحديث المعرض لا يُفسد الشاشة.
    }
  }

  /// يقبل خريطة (مجموعة بالمرحلة) أو قائمة فارغة — PHP يُرجع `[]` لا `{}`
  /// حين لا أدلّة، وقراءتها كخريطة تُلقي استثناءً يُفرغ الشاشة.
  void _absorbEvidence(dynamic raw) {
    if (raw is! Map) {
      evidence.clear();
      return;
    }

    final parsed = <String, List<AmialEvidenceItem>>{};
    raw.forEach((stage, items) {
      if (items is! List) return;
      parsed['$stage'] = items
          .whereType<Map>()
          .map((j) => AmialEvidenceItem.fromJson(Map<String, dynamic>.from(j)))
          .toList();
    });

    evidence.value = parsed;
  }

  int get evidenceCount =>
      evidence.values.fold<int>(0, (sum, list) => sum + list.length);

  Future<bool> _executeAction(
      Future<Response> Function() action, String ulid) async {
    try {
      isSubmitting.value = true;
      final r = await action();
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        await loadDetail(ulid);
        await loadList();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل التنفيذ';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
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
