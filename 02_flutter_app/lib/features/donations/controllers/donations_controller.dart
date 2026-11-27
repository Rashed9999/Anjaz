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
  final RxList<AmialCharityCampaign> homeCampaigns = <AmialCharityCampaign>[].obs;
  final RxList<AmialCharityCampaign> categoryCampaigns = <AmialCharityCampaign>[].obs;
  final RxList<AmialCharityCampaign> featuredCampaigns = <AmialCharityCampaign>[].obs;
  final Rx<AmialCharityCampaign?> selectedCampaign = Rx<AmialCharityCampaign?>(null);
  final RxList<AmialDonation> myDonations = <AmialDonation>[].obs;
  final RxString selectedCategoryCode = ''.obs;

  /// توافق مع أي مستدعٍ قديم يقرأ `campaigns`.
  RxList<AmialCharityCampaign> get campaigns => homeCampaigns;

  // حالات تحميل مستقلة لكل شاشة
  final RxBool isCategoriesLoading = false.obs;
  final RxBool isHomeLoading = false.obs;
  final RxBool isHomeLoadingMore = false.obs;
  final RxBool isCategoryLoading = false.obs;
  final RxBool isCategoryLoadingMore = false.obs;
  final RxBool isDetailLoading = false.obs;
  final RxBool isMyDonationsLoading = false.obs;
  final RxBool isMyDonationsLoadingMore = false.obs;
  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;

  // أخطاء مستقلة لكل شاشة
  final RxString homeError = ''.obs;
  final RxString categoryError = ''.obs;
  final RxString detailError = ''.obs;
  final RxString myDonationsError = ''.obs;
  final RxString lastError = ''.obs;

  // حالة الترقيم (Pagination)
  final RxBool hasMoreHome = false.obs;
  final RxBool hasMoreCategory = false.obs;
  final RxBool hasMoreMyDonations = false.obs;
  int _homePage = 1;
  int _categoryPage = 1;
  int _myDonationsPage = 1;

  bool _hasMoreFromMeta(Map<String, dynamic> meta) {
    final pag = meta['pagination'];
    if (pag is Map) {
      final total = (pag['total'] as num?)?.toInt() ?? 0;
      final perPage = (pag['per_page'] as num?)?.toInt() ?? 20;
      final current = (pag['current_page'] as num?)?.toInt() ?? 1;
      return current * perPage < total;
    }
    return false;
  }

  Future<void> loadCategories() async {
    try {
      isCategoriesLoading.value = true;
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
      isCategoriesLoading.value = false;
    }
  }

  Future<void> loadHomeCampaigns({bool loadMore = false}) async {
    if (loadMore && (isHomeLoadingMore.value || !hasMoreHome.value)) return;
    final nextPage = loadMore ? _homePage + 1 : 1;
    try {
      if (loadMore) {
        isHomeLoadingMore.value = true;
      } else {
        isHomeLoading.value = true;
        homeError.value = '';
      }
      final r = await repo.campaigns(page: nextPage);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        final items = (meta['items'] as List? ?? [])
            .map((j) => AmialCharityCampaign.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        if (loadMore) {
          homeCampaigns.addAll(items);
        } else {
          homeCampaigns.value = items;
        }
        _homePage = nextPage;
        hasMoreHome.value = _hasMoreFromMeta(meta);
        homeError.value = '';
      } else {
        homeError.value = _msg(r) ?? 'فشل التحميل';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadHomeCampaigns: $e');
      homeError.value = 'خطأ في الشبكة';
    } finally {
      isHomeLoading.value = false;
      isHomeLoadingMore.value = false;
    }
  }

  Future<void> loadCategoryCampaigns(String categoryCode, {bool loadMore = false}) async {
    if (loadMore && (isCategoryLoadingMore.value || !hasMoreCategory.value)) return;
    final nextPage = loadMore ? _categoryPage + 1 : 1;
    try {
      if (loadMore) {
        isCategoryLoadingMore.value = true;
      } else {
        isCategoryLoading.value = true;
        selectedCategoryCode.value = categoryCode;
        categoryCampaigns.clear();
        categoryError.value = '';
      }
      final r = await repo.campaigns(categoryCode: categoryCode, page: nextPage);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        final items = (meta['items'] as List? ?? [])
            .map((j) => AmialCharityCampaign.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        if (loadMore) {
          categoryCampaigns.addAll(items);
        } else {
          categoryCampaigns.value = items;
        }
        _categoryPage = nextPage;
        hasMoreCategory.value = _hasMoreFromMeta(meta);
        categoryError.value = '';
      } else {
        categoryError.value = _msg(r) ?? 'فشل التحميل';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadCategoryCampaigns: $e');
      categoryError.value = 'خطأ في الشبكة';
    } finally {
      isCategoryLoading.value = false;
      isCategoryLoadingMore.value = false;
    }
  }

  Future<void> loadCampaigns({String? categoryCode, bool loadMore = false}) {
    if (categoryCode != null && categoryCode.isNotEmpty) {
      return loadCategoryCampaigns(categoryCode, loadMore: loadMore);
    }
    return loadHomeCampaigns(loadMore: loadMore);
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
      isDetailLoading.value = true;
      isLoading.value = true;
      selectedCampaign.value = null;
      detailError.value = '';
      lastError.value = '';
      final r = await repo.campaignShow(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedCampaign.value = AmialCharityCampaign.fromJson(
            Map<String, dynamic>.from(meta['campaign'] ?? {}));
        return true;
      }
      final err = _msg(r) ?? 'لم يتم العثور على الحملة';
      detailError.value = err;
      lastError.value = err;
      return false;
    } catch (e) {
      detailError.value = 'خطأ في الشبكة';
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isDetailLoading.value = false;
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

  Future<void> loadMyDonations({bool loadMore = false}) async {
    if (loadMore && (isMyDonationsLoadingMore.value || !hasMoreMyDonations.value)) return;
    final nextPage = loadMore ? _myDonationsPage + 1 : 1;
    try {
      if (loadMore) {
        isMyDonationsLoadingMore.value = true;
      } else {
        isMyDonationsLoading.value = true;
        myDonationsError.value = '';
      }
      final r = await repo.myDonations(page: nextPage);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        final items = (meta['items'] as List? ?? [])
            .map((j) => AmialDonation.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        if (loadMore) {
          myDonations.addAll(items);
        } else {
          myDonations.value = items;
        }
        _myDonationsPage = nextPage;
        hasMoreMyDonations.value = _hasMoreFromMeta(meta);
        myDonationsError.value = '';
      } else {
        myDonationsError.value = _msg(r) ?? 'تعذّر تحميل التبرعات';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadMyDonations: $e');
      myDonationsError.value = 'خطأ في الشبكة';
    } finally {
      isMyDonationsLoading.value = false;
      isMyDonationsLoadingMore.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
