import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/domain/repositories/split_bill_repo.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-SPLIT-BILL-001 — متحكّم تقسيم الفاتورة.
class SplitBillController extends GetxController implements GetxService {
  final SplitBillRepo repo;
  SplitBillController({required this.repo});

  final RxBool isCreating = false.obs;
  final RxBool isLoadingShares = false.obs;
  final RxnInt payingParticipantId = RxnInt();
  final RxString lastError = ''.obs;

  /// آخر فاتورة أُنشئت (meta.split_bill)
  final Rx<Map<String, dynamic>?> createdBill = Rx<Map<String, dynamic>?>(null);

  /// حصص العميل المعلّقة
  final RxList<Map<String, dynamic>> myShares = <Map<String, dynamic>>[].obs;

  // مفاتيح idempotency لكل حصة (participantId -> key)
  final Map<int, String> _payKeys = {};

  Future<bool> createSplit({
    required String totalAmount,
    required List<String> participants,
    String channel = 'qr',
    String? note,
  }) async {
    try {
      isCreating.value = true;
      lastError.value = '';
      createdBill.value = null;

      final r = await repo.create(
        totalAmount: totalAmount,
        participants: participants,
        channel: channel,
        note: note,
      );

      if (_isOk(r)) {
        createdBill.value = Map<String, dynamic>.from(
          (r.body['meta']?['split_bill'] ?? {}) as Map,
        );
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل إنشاء الفاتورة';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isCreating.value = false;
    }
  }

  Future<void> loadMyShares() async {
    try {
      isLoadingShares.value = true;
      lastError.value = '';
      final r = await repo.myShares();
      if (_isOk(r)) {
        final list = (r.body['meta']?['shares'] ?? []) as List;
        myShares.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل الحصص';
    } finally {
      isLoadingShares.value = false;
    }
  }

  Future<bool> payShare(int participantId) async {
    try {
      payingParticipantId.value = participantId;
      lastError.value = '';
      _payKeys[participantId] ??= IdempotencyKeyGenerator.forFinancialAction('split_pay_$participantId');

      final r = await repo.payShare(participantId, idempotencyKey: _payKeys[participantId]!);

      if (_isOk(r)) {
        myShares.removeWhere((s) => (s['id'] ?? s['participant_id']) == participantId);
        _payKeys.remove(participantId);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل دفع الحصة';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      payingParticipantId.value = null;
    }
  }

  bool _isOk(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map &&
      r.body['success'] == true;

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}
