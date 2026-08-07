import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/amial/domain/models/amial_models.dart';
import 'package:amial_pay/features/amial/domain/repositories/amial_repo.dart';

/// AMIAL-LEGAL/ZONE/RECOVERY-001 (v0.7-D)
///
/// Controller موحد لـ Amial features.
/// يستخدم بواسطة:
///   - ZoneBannerWidget (يقرأ canTransact)
///   - TermsAcceptanceScreen (يقرأ legalStatus + يستدعي accept)
///   - AccountRecoveryScreens
class AmialController extends GetxController implements GetxService {
  final AmialRepo repo;
  AmialController({required this.repo});

  // -------- Reactive state --------

  final Rx<AmialSessionPolicy?> sessionPolicy = Rx<AmialSessionPolicy?>(null);
  final Rx<AmialLegalStatus?> legalStatus = Rx<AmialLegalStatus?>(null);
  final Rx<AmialLegalTerm?> currentTerm = Rx<AmialLegalTerm?>(null);
  final RxBool isLoading = false.obs;
  final RxString lastError = ''.obs;

  // -------- Zone Policy --------

  /// يستدعى في bootstrap (splash) وعند resume.
  Future<void> refreshSessionPolicy() async {
    try {
      isLoading.value = true;
      final r = await repo.getSessionPolicy();
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map<String, dynamic>;
        sessionPolicy.value = AmialSessionPolicy.fromJson(meta);
        lastError.value = '';
      } else {
        lastError.value = _extractMessage(r) ?? 'Failed to load session policy';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('refreshSessionPolicy error: $e');
      lastError.value = 'Network error';
    } finally {
      isLoading.value = false;
    }
  }

  bool get canTransact => sessionPolicy.value?.canTransact ?? false;
  bool get isReadOnly => sessionPolicy.value?.readOnlyMode ?? false;
  String? get bannerMessage => sessionPolicy.value?.bannerMessage;

  // -------- Legal Terms --------

  Future<bool> refreshLegalStatus() async {
    try {
      isLoading.value = true;
      final r = await repo.getLegalStatus();
      if (r.statusCode == 200 && r.body is Map) {
        legalStatus.value = AmialLegalStatus.fromJson(
          (r.body['meta'] ?? {}) as Map<String, dynamic>,
        );
        return true;
      }
      lastError.value = _extractMessage(r) ?? 'Failed';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<AmialLegalTerm?> loadCurrentTerm() async {
    try {
      isLoading.value = true;
      final r = await repo.getCurrentTerms();
      if (r.statusCode == 200 && r.body is Map) {
        currentTerm.value = AmialLegalTerm.fromJson(
          (r.body['meta'] ?? {}) as Map<String, dynamic>,
        );
        return currentTerm.value;
      }
      lastError.value = _extractMessage(r) ?? 'No terms';
      return null;
    } catch (e) {
      lastError.value = 'Network error';
      return null;
    } finally {
      isLoading.value = false;
    }
  }

  /// يعيد true لو القبول نجح أو كان قبل ذلك مقبول.
  Future<bool> acceptCurrentTerm({String? deviceId}) async {
    final term = currentTerm.value;
    if (term == null) {
      lastError.value = 'No term loaded';
      return false;
    }
    try {
      isLoading.value = true;
      final r = await repo.acceptTerms(
        version: term.version,
        locale: term.locale,
        deviceId: deviceId,
      );
      if (r.statusCode == 200 && r.body is Map && (r.body['success'] == true)) {
        // تحديث الـ status فوراً
        legalStatus.value = AmialLegalStatus(
          needsAcceptance: false,
          currentVersion: term.version,
          title: term.title,
        );
        lastError.value = '';
        return true;
      }
      lastError.value = _extractMessage(r) ?? 'Failed';
      return false;
    } catch (e) {
      lastError.value = 'Network error';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  // -------- Helpers --------

  String? _extractMessage(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
