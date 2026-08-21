import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/donations/domain/models/donation_models.dart';
import 'package:amial_pay/data/api/idempotent_intent.dart';
import 'package:amial_pay/features/donations/domain/repositories/donations_repo.dart';

/// AMIAL-DONATIONS-001 (v1.2)
class DonationsController extends GetxController with IdempotentIntent implements GetxService {
  final DonationsRepo repo;
  DonationsController({required this.repo});

  final RxList<AmialCharityCategory> categories = <AmialCharityCategory>[].obs;
  final RxList<AmialCharityCampaign> campaigns = <AmialCharityCampaign>[].obs;
  final RxList<AmialCharityCampaign> featuredCampaigns = <AmialCharityCampaign>[].obs;
  final Rx<AmialCharityCampaign?> selectedCampaign = Rx<AmialCharityCampaign?>(null);
  final RxList<AmialDonation> myDonations = <AmialDonation>[].obs;
  final RxString selectedCategoryCode = ''.obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  Future<void> loadCategories() async {
    try {
      isLoading.value = true;
      final r = await repo.categories();
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['categories'] as List? ?? []);
        categories.value = items
            .map((j) => AmialCharityCategory.fromJson(Map<String, dynamic>.from(j)))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadCategories: $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadCampaigns({String? categoryCode}) async {
    try {
      isLoading.value = true;
      selectedCategoryCode.value = categoryCode ?? '';
      final r = await repo.campaigns(categoryCode: categoryCode);
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        campaigns.value = items
            .map((j) => AmialCharityCampaign.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'فشل التحميل';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadCampaigns: $e');
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadFeatured() async {
    try {
      final r = await repo.campaigns(featured: true);
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        featuredCampaigns.value = items
            .map((j) => AmialCharityCampaign.fromJson(Map<String, dynamic>.from(j)))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadFeatured: $e');
    }
  }

  Future<bool> loadCampaign(String ulid) async {
    try {
      isLoading.value = true;
      selectedCampaign.value = null;
      lastError.value = '';
      final r = await repo.campaignShow(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedCampaign.value = AmialCharityCampaign.fromJson(
            Map<String, dynamic>.from(meta['campaign'] ?? {}));
        return true;
      }
      lastError.value = _msg(r) ?? 'لم يتم العثور على الحملة';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> donate({
    required String campaignUlid,
    required String amount,
    required String pin,
    bool isAnonymous = false,
    String? message,
  }) async {
    try {
      isSubmitting.value = true;
      // AMIAL-IDEMPOTENCY-002 — مفتاحٌ واحدٌ لنيّةٍ واحدة، والحملةُ نطاقُه:
      // تبرّعان لحملتين نيّتان، وإعادةُ الأولى بعد انقطاعٍ إعادةٌ لا تبرّعٌ ثانٍ.
      final r = await repo.donate(
        campaignUlid: campaignUlid,
        amount: amount,
        pin: pin,
        isAnonymous: isAnonymous,
        message: message,
        idempotencyKey: keyFor('donate', scope: campaignUlid),
      );

      settleKey('donate', r, scope: campaignUlid);
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        // refresh campaign details
        await loadCampaign(campaignUlid);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشلت المساهمة';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> loadMyDonations() async {
    try {
      isLoading.value = true;
      final r = await repo.myDonations();
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        myDonations.value = items
            .map((j) => AmialDonation.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'تعذّر تحميل التبرعات';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadMyDonations: $e');
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
