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
  final RxBool isLoadingIncoming = false.obs;
  final RxBool isLoadingOutgoing = false.obs;
  final RxBool isLoadingMoreIncoming = false.obs;
  final RxBool isLoadingMoreOutgoing = false.obs;
  final RxBool hasMoreIncoming = false.obs;
  final RxBool hasMoreOutgoing = false.obs;

  final RxString lastError = ''.obs;
  final RxString lastErrorIncoming = ''.obs;
  final RxString lastErrorOutgoing = ''.obs;

  int _incomingPage = 1;
  int _incomingLastPage = 1;
  String? _incomingStatus;

  int _outgoingPage = 1;
  int _outgoingLastPage = 1;
  String? _outgoingStatus;

  /// AMIAL-REQUEST-DIRECT-002 — حالةُ الرقم المكتوب: مشترك أم لا.
  ///
  /// `null` = لم يُسأل بعد. والتمييزُ عن «غير مشترك» ضروريّ: الصمتُ ليس
  /// جواباً، ولا يُعرض «سيُشارَك برابط» قبل أن يُسأل الخادم.
  final Rx<Map<String, dynamic>?> recipientCheck = Rx<Map<String, dynamic>?>(null);
  final RxBool isChecking = false.obs;
  int _recipientLookupVersion = 0;

  /// يسأل الخادم عن الرقم. لا يرمي، ولا يُعطّل الإنشاء إن فشل:
  /// **تعذُّرُ السؤال لا يمنع الطلب** — الخادمُ يقرّر عند الإنشاء بأيّ حال.
  Future<bool> checkRecipient(String phone) async {
    final digits = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length != 8 && digits.length < 9) {
      recipientCheck.value = null;
      return false;
    }
    final version = ++_recipientLookupVersion;
    try {
      isChecking.value = true;
      lastError.value = '';
      final r = await repo.checkRecipient(phone);
      if (version != _recipientLookupVersion) return false;
      if (_ok(r)) {
        recipientCheck.value =
            Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        return recipientCheck.value?['found'] == true;
      }
      recipientCheck.value = null;
      lastError.value = _msg(r) ?? 'تعذّر التحقق من العميل';
      return false;
    } catch (_) {
      if (version == _recipientLookupVersion) {
        recipientCheck.value = null;
        lastError.value = 'تعذّر الاتصال للتحقق من العميل';
      }
      return false;
    } finally {
      if (version == _recipientLookupVersion) isChecking.value = false;
    }
  }

  void clearRecipientCheck() {
    _recipientLookupVersion++;
    recipientCheck.value = null;
    lastError.value = '';
    isChecking.value = false;
  }

  /// إنشاء طلب مباشر بالرمز الذي صدر بعد معاينة بيانات العميل.
  Future<bool> createDirect({
    required String amount,
    required int recipientId,
    required String verificationToken,
    String? note,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.createDirect(
        amount: amount,
        recipientId: recipientId,
        verificationToken: verificationToken,
        note: note,
      );
      if (_ok(r)) {
        currentRequest.value =
            Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        return true;
      }
      if (r.statusCode == 1) {
        // ApiClient يحتفظ بمفتاح التفرّد عند انقطاع الجواب. إبقاء بيانات
        // التأكيد يجعل الضغطة التالية تعيد الجسد نفسه، فيرجع الخادم النتيجة
        // المحفوظة بدل إنشاء طلبٍ ثانٍ إن كان الأول قد وصل.
        lastError.value = 'تعذّر الاتصال — أعد المحاولة للتحقق من حالة الطلب';
        return false;
      }
      lastError.value = _msg(r) ?? 'تعذّر إرسال الطلب';
      return false;
    } catch (_) {
      lastError.value = 'تعذّر الاتصال — أعد المحاولة للتحقق من حالة الطلب';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

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
    final isIncoming = direction == 'incoming';
    final loadingRx = isIncoming ? isLoadingIncoming : isLoadingOutgoing;
    final errorRx = isIncoming ? lastErrorIncoming : lastErrorOutgoing;

    try {
      loadingRx.value = true;
      isLoading.value = true;
      errorRx.value = '';
      if (isIncoming) {
        _incomingStatus = status;
      } else {
        _outgoingStatus = status;
      }

      final r = await repo.list(direction: direction, status: status, page: 1);
      if (_ok(r)) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final list = (meta['requests'] ?? []) as List;
        final mapped = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        final pagination = meta['pagination'] is Map
            ? Map<String, dynamic>.from(meta['pagination'] as Map)
            : <String, dynamic>{};
        final currentPage = int.tryParse('${pagination['current_page'] ?? 1}') ?? 1;
        final lastPage = int.tryParse('${pagination['last_page'] ?? 1}') ?? 1;

        if (isIncoming) {
          incoming.assignAll(mapped);
          _incomingPage = currentPage;
          _incomingLastPage = lastPage;
          hasMoreIncoming.value = _incomingPage < _incomingLastPage;
        } else {
          outgoing.assignAll(mapped);
          _outgoingPage = currentPage;
          _outgoingLastPage = lastPage;
          hasMoreOutgoing.value = _outgoingPage < _outgoingLastPage;
        }
      } else {
        errorRx.value = _msg(r) ?? 'تعذّر تحميل الطلبات';
      }
    } catch (_) {
      errorRx.value = 'تعذّر الاتصال لتحميل الطلبات';
    } finally {
      loadingRx.value = false;
      isLoading.value = isLoadingIncoming.value || isLoadingOutgoing.value;
    }
  }

  Future<void> loadMoreIncoming() async {
    if (isLoadingIncoming.value ||
        isLoadingMoreIncoming.value ||
        _incomingPage >= _incomingLastPage) {
      return;
    }

    try {
      isLoadingMoreIncoming.value = true;
      final nextPage = _incomingPage + 1;
      final r = await repo.list(
        direction: 'incoming',
        status: _incomingStatus,
        page: nextPage,
      );
      if (_ok(r)) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final list = (meta['requests'] ?? []) as List;
        final mapped = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        final existingIds = incoming.map((e) => e['id']).toSet();
        incoming.addAll(mapped.where((e) => !existingIds.contains(e['id'])));

        final pagination = meta['pagination'] is Map
            ? Map<String, dynamic>.from(meta['pagination'] as Map)
            : <String, dynamic>{};
        _incomingPage = int.tryParse('${pagination['current_page'] ?? nextPage}') ?? nextPage;
        _incomingLastPage = int.tryParse('${pagination['last_page'] ?? _incomingLastPage}') ?? _incomingLastPage;
        hasMoreIncoming.value = _incomingPage < _incomingLastPage;
      }
    } catch (_) {
      // نُبقي القائمة الحالية كما هي؛ يمكن للمستخدم المحاولة لاحقاً.
    } finally {
      isLoadingMoreIncoming.value = false;
    }
  }

  Future<void> loadMoreOutgoing() async {
    if (isLoadingOutgoing.value ||
        isLoadingMoreOutgoing.value ||
        _outgoingPage >= _outgoingLastPage) {
      return;
    }

    try {
      isLoadingMoreOutgoing.value = true;
      final nextPage = _outgoingPage + 1;
      final r = await repo.list(
        direction: 'outgoing',
        status: _outgoingStatus,
        page: nextPage,
      );
      if (_ok(r)) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final list = (meta['requests'] ?? []) as List;
        final mapped = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        final existingIds = outgoing.map((e) => e['id']).toSet();
        outgoing.addAll(mapped.where((e) => !existingIds.contains(e['id'])));

        final pagination = meta['pagination'] is Map
            ? Map<String, dynamic>.from(meta['pagination'] as Map)
            : <String, dynamic>{};
        _outgoingPage = int.tryParse('${pagination['current_page'] ?? nextPage}') ?? nextPage;
        _outgoingLastPage = int.tryParse('${pagination['last_page'] ?? _outgoingLastPage}') ?? _outgoingLastPage;
        hasMoreOutgoing.value = _outgoingPage < _outgoingLastPage;
      }
    } catch (_) {
      // نُبقي القائمة الحالية كما هي؛ يمكن للمستخدم المحاولة لاحقاً.
    } finally {
      isLoadingMoreOutgoing.value = false;
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
      lastError.value = '';
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
      lastError.value = 'خطأ في الشبكة';
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
