import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/domain/models/merchant_models.dart';
import 'package:amial_pay/features/merchant/domain/repositories/merchant_repo.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantController extends GetxController implements GetxService {
  final MerchantRepo repo;
  MerchantController({required this.repo});

  final Rx<AmialMerchant?> merchant = Rx<AmialMerchant?>(null);
  final Rx<AmialMerchantDashboardStats> stats =
      AmialMerchantDashboardStats.empty().obs;
  final RxList<AmialMerchantTransaction> transactions =
      <AmialMerchantTransaction>[].obs;
  final Rx<Map<String, dynamic>?> financialReport = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoadingFinancialReport = false.obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ====== State for payment request (QR) ======
  final RxString lastPaymentRequestId = ''.obs;
  final RxString lastPaymentAmount = ''.obs;

  // ====== Profile ======
  /// ══════════════════════════════════════════════════════════════════
  /// AMIAL-MERCHANT-SESSION-001 — **نداءُ ملفّ العميل نُزع من هنا.**
  ///
  /// كان يُنادي `/api/v1/customer/get-customer`، **وهي تردّ ٤٠٣ لكلّ
  /// تاجر** — فلم يملأ `merchant.value` مرّةً واحدةً منذ كُتب، والخطأُ
  /// مبتلَعٌ في `catch` أدناه. والأسوأُ أنّه كان نداءً إلى المجموعة
  /// الوحيدة التي تفحص الخمول، فيحذف رموزَ التاجر ويطرده من حسابه.
  ///
  /// **وهويّةُ المتجر تصل من بيان الوصول أصلاً** (`merchant_context`)،
  /// و**الرصيدُ من `daily-stats`** — كلاهما مسارُ تاجرٍ يعمل.
  Future<void> loadProfile() async {
    try {
      isLoading.value = true;
      await _loadDailyStats();
    } catch (e) {
      if (kDebugMode) debugPrint('loadProfile (merchant): $e');
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
          stats.value = AmialMerchantDashboardStats.fromJson(
              Map<String, dynamic>.from(meta));
        }
      }
    } catch (e) {
      if (kDebugMode) debugPrint('_loadDailyStats (merchant): $e');
    }
  }

  Future<void> loadFinancialReport({String? from, String? to}) async {
    try {
      isLoadingFinancialReport.value = true;
      lastError.value = '';
      final r = await repo.financialReport(from: from, to: to);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = r.body['meta'] ?? {};
        final report = meta is Map ? meta['report'] : null;
        if (report is Map) financialReport.value = Map<String, dynamic>.from(report);
      } else {
        lastError.value = _msg(r) ?? 'تعذر تحميل التقرير المالي';
      }
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoadingFinancialReport.value = false;
    }
  }

  // ====== Generate Payment Request ======
  Future<bool> requestPayment({
    required String amount,
    String? note,
    String? customerPhone,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.requestPayment(
        amount: amount,
        note: note,
        customerPhone: customerPhone,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          (r.body['success'] == true ||
              r.body['message']?.toString().toLowerCase().contains('success') ==
                  true)) {
        final meta = r.body['meta'] ?? r.body['data'] ?? {};
        if (meta is Map) {
          // **الحقل الصحيح `short_code`.**
          //
          // كان يقرأ `meta['id']` والخادمُ يردّ `meta['short_code']` —
          // فتُقرأ قيمةٌ غيرُ موجودة، ويصير رقمُ الفاتورة **نصّاً فارغاً**،
          // ويُبنى الرمزُ حوله فلا يدلّ على شيء.
          lastPaymentRequestId.value =
              (meta['short_code'] ?? meta['request']?['short_code'] ?? '').toString();
        }
        lastPaymentAmount.value = amount;
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

  Future<void> loadTransactions() async {
    try {
      isLoading.value = true;
      // ════════════════════════════════════════════════════════════
      // AMIAL-MERCHANT-SESSION-001 — **من دفتر التاجر لا من سجلّ العميل.**
      //
      // كان مكتوباً هنا أنّ `/customer/transaction-history` «مصدرُ حركة
      // المحفظة الحقيقيّ للتاجر». **وقِيس فإذا هي ٤٠٣ لكلّ تاجر** —
      // القائمةُ فارغةٌ منذ كُتبت، والشاشةُ تقول «لا حركةَ في المحفظة
      // بعد» على متجرٍ يبيع. وهو غيابٌ يُعرَض صفراً (القاعدة السابعة).
      // ════════════════════════════════════════════════════════════
      final r = await repo.walletLedger();
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        final list = (meta is Map ? meta['entries'] : null) as List? ?? const [];
        transactions.value = list
            .map((j) => AmialMerchantTransaction.fromLedgerEntry(
                Map<String, dynamic>.from(j as Map)))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadTransactions (merchant): $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> processRefund({
    required String originalTransactionId,
    required String amount,
    String? reason,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.processRefund(
        originalTransactionId: originalTransactionId,
        amount: amount,
        reason: reason,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        await loadProfile();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الاسترجاع';
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
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}
