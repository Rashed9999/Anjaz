import 'dart:io';

import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class SafePaymentRepo extends GetxService {
  final ApiClient apiClient;
  SafePaymentRepo({required this.apiClient});

  Future<Response> list({String role = 'all', String? status, int page = 1}) async {
    final query = StringBuffer('?role=$role&page=$page');
    if (status != null) query.write('&status=$status');
    return apiClient.getData('${AppConstants.amialSafePayments}$query');
  }

  Future<Response> show(String ulid) async {
    return apiClient.getData('${AppConstants.amialSafePayments}/$ulid');
  }

  Future<Response> create({
    required String sellerPhone,
    required String title,
    required String description,
    required String amount,
    String? deliveryTerms,
    List<String>? attachments,
    required String idempotencyKey,
  }) async {
    return apiClient.postData(
      AppConstants.amialSafePayments,
      {
        'seller_phone': sellerPhone,
        'title': title,
        'description': description,
        'amount': amount,
        'delivery_terms': ?deliveryTerms,
        'attachments': ?attachments,
      },
      idempotencyKey: idempotencyKey,
    );
  }

  // Seller actions
  Future<Response> sellerAccept(String ulid,
          {String? note, required String idempotencyKey}) =>
      _action(ulid, 'seller-accept', {'note': ?note}, idempotencyKey);

  Future<Response> sellerReject(String ulid, String reason,
          {required String idempotencyKey}) =>
      _action(ulid, 'seller-reject', {'reason': reason}, idempotencyKey);

  Future<Response> sellerMarkInDelivery(String ulid,
          {String? note, required String idempotencyKey}) =>
      _action(ulid, 'seller-mark-in-delivery', {'note': ?note}, idempotencyKey);

  Future<Response> sellerMarkDelivered(String ulid,
          {String? note, required String idempotencyKey}) =>
      _action(ulid, 'seller-mark-delivered', {'note': ?note}, idempotencyKey);

  // Buyer actions
  Future<Response> buyerConfirm(String ulid,
          {required String idempotencyKey}) =>
      _action(ulid, 'buyer-confirm', {}, idempotencyKey);

  Future<Response> buyerCancel(String ulid, String reason,
          {required String idempotencyKey}) =>
      _action(ulid, 'buyer-cancel', {'reason': reason}, idempotencyKey);

  Future<Response> buyerDispute(
    String ulid,
    String reason, {
    String? reasonCode,
    List<String>? attachments,
    required String idempotencyKey,
  }) =>
      _action(ulid, 'buyer-dispute', {
        'reason': reason,
        'reason_code': ?reasonCode,
        'attachments': ?attachments,
      }, idempotencyKey);

  // ============ AMIAL-SAFEPAY-EVIDENCE-001 ============

  /// أسباب النزاع من الخادم — إضافة سبب لا تستحقّ إصدار تطبيق.
  Future<Response> disputeReasons() =>
      apiClient.getData('${AppConstants.amialSafePayments}/dispute-reasons');

  Future<Response> evidence(String ulid) =>
      apiClient.getData('${AppConstants.amialSafePayments}/$ulid/evidence');

  /// رفع أدلّة حقيقية (ملفات) — البائع للشحن والتسليم، والمشتري للنزاع.
  Future<Response> uploadEvidence({
    required String ulid,
    required String stage,
    required List<File> files,
    String? note,
  }) {
    return apiClient.postMultipartData(
      '${AppConstants.amialSafePayments}/$ulid/evidence',
      {'stage': stage, 'note': ?note},
      // الخادم يقرأ files[] — المفتاح نفسه لكل ملفّ.
      files.map((f) => MultipartBody('files[]', f)).toList(),
    );
  }

  /// البائع يؤكّد التسليم برمز المشتري.
  Future<Response> verifyDelivery(String ulid, String code,
          {required String idempotencyKey}) =>
      _action(ulid, 'verify-delivery', {'code': code}, idempotencyKey);

  /// AMIAL-IDEMPOTENCY-002 — **المفتاحُ يُستقبَل ولا يُولَّد هنا.**
  ///
  /// كان `IdempotencyKeyGenerator.forFinancialAction(idemPrefix)` داخل
  /// قائمة المُعامِلات — أي في **كلّ نداء**. فمن انقطع اتّصالُه بعد وصول
  /// الطلب وقبل وصول الردّ يُعيد المحاولة بمفتاحٍ جديد، فيقرؤها الخادمُ
  /// عمليّةً ثانية.
  ///
  /// والمستودعُ `GetxService` مفردٌ لا يعرف متى تبدأ نيّةٌ ومتى تنتهي —
  /// يعرفها المتحكّم، فمنه يأتي المفتاح.
  Future<Response> _action(
      String ulid, String path, Map<String, dynamic> body, String idempotencyKey) {
    return apiClient.postData(
      '${AppConstants.amialSafePayments}/$ulid/$path',
      body,
      idempotencyKey: idempotencyKey,
    );
  }
}
