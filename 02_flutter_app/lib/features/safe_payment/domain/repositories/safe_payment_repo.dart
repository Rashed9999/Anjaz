import 'dart:io';

import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';
import 'package:amyal_pay/util/app_constants.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class SafePaymentRepo extends GetxService {
  final ApiClient apiClient;
  SafePaymentRepo({required this.apiClient});

  Future<Response> list({String role = 'all', String? status, int page = 1}) async {
    final query = StringBuffer('?role=$role&page=$page');
    if (status != null) query.write('&status=$status');
    return apiClient.getData('${AppConstants.amyalSafePayments}$query');
  }

  Future<Response> show(String ulid) async {
    return apiClient.getData('${AppConstants.amyalSafePayments}/$ulid');
  }

  Future<Response> create({
    required String sellerPhone,
    required String title,
    required String description,
    required String amount,
    String? deliveryTerms,
    List<String>? attachments,
  }) async {
    return apiClient.postData(
      AppConstants.amyalSafePayments,
      {
        'seller_phone': sellerPhone,
        'title': title,
        'description': description,
        'amount': amount,
        'delivery_terms': ?deliveryTerms,
        'attachments': ?attachments,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('safe_pay_create'),
    );
  }

  // Seller actions
  Future<Response> sellerAccept(String ulid, {String? note}) =>
      _action(ulid, 'seller-accept', {'note': ?note}, 'sp_accept');

  Future<Response> sellerReject(String ulid, String reason) =>
      _action(ulid, 'seller-reject', {'reason': reason}, 'sp_reject');

  Future<Response> sellerMarkInDelivery(String ulid, {String? note}) =>
      _action(ulid, 'seller-mark-in-delivery', {'note': ?note}, 'sp_in_delivery');

  Future<Response> sellerMarkDelivered(String ulid, {String? note}) =>
      _action(ulid, 'seller-mark-delivered', {'note': ?note}, 'sp_delivered');

  // Buyer actions
  Future<Response> buyerConfirm(String ulid) =>
      _action(ulid, 'buyer-confirm', {}, 'sp_confirm');

  Future<Response> buyerCancel(String ulid, String reason) =>
      _action(ulid, 'buyer-cancel', {'reason': reason}, 'sp_cancel');

  Future<Response> buyerDispute(
    String ulid,
    String reason, {
    String? reasonCode,
    List<String>? attachments,
  }) =>
      _action(ulid, 'buyer-dispute', {
        'reason': reason,
        'reason_code': ?reasonCode,
        'attachments': ?attachments,
      }, 'sp_dispute');

  // ============ AMIAL-SAFEPAY-EVIDENCE-001 ============

  /// أسباب النزاع من الخادم — إضافة سبب لا تستحقّ إصدار تطبيق.
  Future<Response> disputeReasons() =>
      apiClient.getData('${AppConstants.amyalSafePayments}/dispute-reasons');

  Future<Response> evidence(String ulid) =>
      apiClient.getData('${AppConstants.amyalSafePayments}/$ulid/evidence');

  /// رفع أدلّة حقيقية (ملفات) — البائع للشحن والتسليم، والمشتري للنزاع.
  Future<Response> uploadEvidence({
    required String ulid,
    required String stage,
    required List<File> files,
    String? note,
  }) {
    return apiClient.postMultipartData(
      '${AppConstants.amyalSafePayments}/$ulid/evidence',
      {'stage': stage, 'note': ?note},
      // الخادم يقرأ files[] — المفتاح نفسه لكل ملفّ.
      files.map((f) => MultipartBody('files[]', f)).toList(),
    );
  }

  /// البائع يؤكّد التسليم برمز المشتري.
  Future<Response> verifyDelivery(String ulid, String code) =>
      _action(ulid, 'verify-delivery', {'code': code}, 'sp_verify_delivery');

  Future<Response> _action(String ulid, String path, Map<String, dynamic> body, String idemPrefix) {
    return apiClient.postData(
      '${AppConstants.amyalSafePayments}/$ulid/$path',
      body,
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction(idemPrefix),
    );
  }
}
