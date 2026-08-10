import 'package:get/get.dart';
import 'package:amial_pay/features/requested_money/domain/repositories/payment_request_repo.dart';

/// AMIAL-PAYMENT-REQUESTS-001 — Controller.
class PaymentRequestController extends GetxController implements GetxService {
  final PaymentRequestRepo repo;
  PaymentRequestController({required this.repo});

  // الطلب الجاري إنشاؤه/معاينته
  final Rx<Map<String, dynamic>?> currentRequest = Rx<Map<String, dynamic>?>(null);

  // قوائم منفصلة للصادرة والواردة
  final RxList<Map<String, dynamic>> outgoing = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> incoming = <Map<String, dynamic>>[].obs;

  final RxBool isSubmitting = false.obs;
  final RxBool isLoading = false.obs;
  final RxString lastError = ''.obs;

  /// AMIAL-REQUEST-DIRECT-002 — حالةُ الرقم المكتوب: مشترك أم لا.
  ///
  /// `null` = لم يُسأل بعد. والتمييزُ عن «غير مشترك» ضروريّ: الصمتُ ليس
  /// جواباً، ولا يُعرض «سيُشارَك برابط» قبل أن يُسأل الخادم.
  final Rx<Map<String, dynamic>?> recipientCheck = Rx<Map<String, dynamic>?>(null);
  final RxBool isChecking = false.obs;

  /// يسأل الخادم عن الرقم. لا يرمي، ولا يُعطّل الإنشاء إن فشل:
  /// **تعذُّرُ السؤال لا يمنع الطلب** — الخادمُ يقرّر عند الإنشاء بأيّ حال.
  Future<void> checkRecipient(String phone) async {
    final digits = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length < 9) {
      recipientCheck.value = null;
      return;
    }
    try {
      isChecking.value = true;
      final r = await repo.checkRecipient(phone);
      recipientCheck.value = _ok(r)
          ? Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map)
          : null;
    } catch (_) {
      recipientCheck.value = null;
    } finally {
      isChecking.value = false;
    }
  }

  void clearRecipientCheck() => recipientCheck.value = null;

  Future<bool> create({
    required String amount,
    String? recipientPhone,
    String? recipientName,
    String? note,
    String shareMethod = 'link',
    bool isRecurring = false,
    String? recurringPeriod,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final body = <String, dynamic>{
        'amount': amount,
        'share_method': shareMethod,
        if (recipientPhone != null && recipientPhone.isNotEmpty) 'recipient_phone': recipientPhone,
        if (recipientName != null && recipientName.isNotEmpty) 'recipient_name': recipientName,
        if (note != null && note.isNotEmpty) 'note': note,
        if (isRecurring) 'is_recurring': true,
        if (isRecurring && recurringPeriod != null) 'recurring_period': recurringPeriod,
      };
      final r = await repo.create(body);
      if (_ok(r)) {
        currentRequest.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل إنشاء الطلب';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> loadList(String direction, {String? status}) async {
    try {
      isLoading.value = true;
      final r = await repo.list(direction: direction, status: status);
      if (_ok(r)) {
        final list = (r.body['meta']?['requests'] ?? []) as List;
        final mapped = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        if (direction == 'incoming') {
          incoming.assignAll(mapped);
        } else {
          outgoing.assignAll(mapped);
        }
      }
    } catch (_) {} finally {
      isLoading.value = false;
    }
  }

  Future<Map<String, dynamic>?> showByCode(String code) async {
    try {
      final r = await repo.showByCode(code);
      if (_ok(r)) {
        return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      }
      lastError.value = _msg(r) ?? 'الطلب غير موجود';
      return null;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return null;
    }
  }

  /// AMIAL-MERCHANT-PAY-002 — البحث بفاتورةٍ عند رقم حساب تاجر.
  Future<Map<String, dynamic>?> lookupInvoice({
    String? merchantPhone,
    int? merchantUserId,
    required String invoiceNo,
  }) async {
    try {
      lastError.value = '';
      final r = await repo.lookupInvoice(
        merchantPhone: merchantPhone,
        merchantUserId: merchantUserId,
        invoiceNo: invoiceNo,
      );
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      }
      // **والرسالةُ تُنقل كما جاءت من الخادم** — «رقم الفاتورة لا يخصّ هذا
      // التاجر» تُرشد، و«فشل» لا تُرشد.
      lastError.value = (r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر جلب الفاتورة';
      return null;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return null;
    }
  }

  Future<bool> pay(String code, {required String pin}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.pay(code, pin: pin);
      if (_ok(r)) return true;
      lastError.value = _msg(r) ?? 'فشل الدفع';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// المستلم يوافق: يدفع الطلب من قائمته بلا رمز.
  Future<bool> payById(int id, {required String pin}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.payById(id, pin: pin);
      if (_ok(r)) {
        incoming.removeWhere((e) => e['id'] == id);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الدفع';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// المستلم يرفض. والرفض غير الإلغاء: الإلغاء يفعله الطالب.
  Future<bool> decline(int id, {String? reason}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.decline(id, reason: reason);
      if (_ok(r)) {
        incoming.removeWhere((e) => e['id'] == id);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الرفض';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> cancel(int id) async {
    try {
      isSubmitting.value = true;
      final r = await repo.cancel(id);
      if (_ok(r)) {
        outgoing.removeWhere((e) => e['id'] == id);
        if (currentRequest.value?['request']?['id'] == id) {
          currentRequest.value = null;
        }
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الإلغاء';
      return false;
    } catch (_) {
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;

  String? _msg(Response r) {
    try { if (r.body is Map) return r.body['message']?.toString(); } catch (_) {}
    return null;
  }
}
