import 'dart:io';

import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/features/kyc_verification/domain/reposotories/verification_center_repo.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:get/get.dart';

class VerificationCenterController extends GetxController implements GetxService {
  final VerificationCenterRepo repo;

  VerificationCenterController({required this.repo});

  Map<String, dynamic>? _data;
  Map<String, dynamic> _completion = <String, dynamic>{};
  bool _isLoading = false;
  bool _isActionLoading = false;
  List<Map<String, dynamic>> _residenceOptions = const [];

  Map<String, dynamic>? get data => _data;
  Map<String, dynamic> get completion => _completion;
  bool get isLoading => _isLoading;
  bool get isActionLoading => _isActionLoading;
  List<Map<String, dynamic>> get residenceOptions => _residenceOptions;

  int get currentTier => _asInt(_map(_data?['tier'])['current']);
  bool get phoneVerified => _map(_data?['contact'])['phone_verified'] == true;
  String get identityStatus => _map(_data?['identity'])['status']?.toString() ?? 'not_submitted';
  String get residenceStatus => _map(_data?['residence'])['status']?.toString() ?? 'not_submitted';
  bool get biometricAvailable => _map(_data?['privacy'])['biometric_available'] == true;

  List<Map<String, dynamic>> get levels {
    final raw = _data?['verification_levels'];
    if (raw is! List) return const [];
    return raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
  }

  List<String> get tier3ProfileMissingCodes {
    final raw = _completion['tier3_profile_missing_codes'];
    if (raw is! List) return const [];
    return raw.map((e) => e.toString()).toList();
  }

  List<Map<String, dynamic>> get incomeSourceOptions =>
      _optionList(_map(_completion['profile_options'])['income_sources']);

  List<Map<String, dynamic>> get accountPurposeOptions =>
      _optionList(_map(_completion['profile_options'])['account_purposes']);

  Map<String, dynamic> get tier3Ownership =>
      _map(_completion['tier3_ownership']);

  double get usageProgress {
    final bar = _map(_data?['usage_bar']);
    final used = double.tryParse('${bar['used'] ?? 0}') ?? 0;
    final limit = double.tryParse('${bar['limit'] ?? 0}') ?? 0;
    if (limit <= 0) return 0;
    return (used / limit).clamp(0.0, 1.0);
  }

  Future<void> load({bool silent = false}) async {
    if (!silent) {
      _isLoading = true;
      update();
    }

    final response = await repo.status();
    if (response.statusCode == 200 && response.body is Map && response.body['success'] == true) {
      _data = Map<String, dynamic>.from(response.body['data'] as Map);

      final completionResponse = await repo.completion();
      if (completionResponse.statusCode == 200 &&
          completionResponse.body is Map &&
          completionResponse.body['success'] == true &&
          completionResponse.body['data'] is Map) {
        _completion = Map<String, dynamic>.from(completionResponse.body['data'] as Map);
      }
    } else {
      ApiChecker.checkApi(response);
    }

    _isLoading = false;
    update();
  }

  Future<bool> requestPhoneOtp() async {
    _setActionLoading(true);
    final response = await repo.requestPhoneOtp();
    _setActionLoading(false);

    if (response.statusCode == 200) {
      final body = response.body;
      final pilot = body is Map && body['pilot_mode'] == true;
      showCustomSnackBarHelper(
        pilot
            ? 'وضع تجريبي: رمز تحقق الهاتف هو 123456'
            : 'تم إرسال رمز التحقق إلى رقم حسابك',
        isError: false,
      );
      return true;
    }
    _showError(response, 'تعذر إرسال رمز التحقق');
    return false;
  }

  Future<bool> verifyPhoneOtp(String otp) async {
    _setActionLoading(true);
    final response = await repo.verifyPhoneOtp(otp);
    _setActionLoading(false);

    if (response.statusCode == 200) {
      showCustomSnackBarHelper('تم إثبات ملكية رقم الهاتف', isError: false);
      await load(silent: true);
      return true;
    }
    _showError(response, 'رمز التحقق غير صحيح');
    return false;
  }

  Future<void> loadResidenceOptions() async {
    final response = await repo.residence();
    if (response.statusCode == 200 && response.body is Map) {
      final raw = response.body['evidence_options'];
      if (raw is List) {
        _residenceOptions = raw
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
        update();
      }
    }
  }

  Future<bool> submitResidence({
    required String birthGovernorate,
    required String governorate,
    required String district,
    String? area,
    String? landmark,
    required String evidenceType,
    required File evidence,
    String? evidenceDate,
  }) async {
    _setActionLoading(true);
    final response = await repo.submitResidence(
      birthGovernorate: birthGovernorate,
      governorate: governorate,
      district: district,
      area: area,
      landmark: landmark,
      evidenceType: evidenceType,
      evidence: evidence,
      evidenceDate: evidenceDate,
    );
    _setActionLoading(false);

    if ((response.statusCode ?? 500) >= 200 &&
        (response.statusCode ?? 500) < 300 &&
        response.body is Map && response.body['success'] == true) {
      showCustomSnackBarHelper(
        response.body['message']?.toString() ?? 'تم إرسال إثبات السكن',
        isError: false,
      );
      await load(silent: true);
      return true;
    }
    _showError(response, 'تعذر إرسال إثبات السكن');
    return false;
  }

  Future<bool> updateFullProfile(Map<String, dynamic> fields) async {
    _setActionLoading(true);
    final response = await repo.updateFullProfile(fields);
    _setActionLoading(false);

    if ((response.statusCode ?? 500) >= 200 &&
        (response.statusCode ?? 500) < 300 &&
        response.body is Map && response.body['success'] == true) {
      showCustomSnackBarHelper(
        response.body['message']?.toString() ?? 'تم حفظ بيانات التوثيق',
        isError: false,
      );
      await load(silent: true);
      return true;
    }
    _showError(response, 'تعذر حفظ بيانات التوثيق');
    return false;
  }

  Future<bool> submitOwnershipSelfie({
    required String mode,
    required File selfie,
  }) async {
    _setActionLoading(true);
    final response = await repo.submitOwnershipSelfie(mode: mode, selfie: selfie);
    _setActionLoading(false);

    if ((response.statusCode ?? 500) >= 200 &&
        (response.statusCode ?? 500) < 300 &&
        response.body is Map && response.body['success'] == true) {
      showCustomSnackBarHelper(
        response.body['message']?.toString() ?? 'تم إرسال دليل إثبات الملكية',
        isError: false,
      );
      await load(silent: true);
      return true;
    }
    _showError(response, 'تعذر إرسال دليل إثبات الملكية');
    return false;
  }

  Future<bool> choosePrivacy(String mode) async {
    _setActionLoading(true);
    final response = await repo.updatePrivacy(mode);
    _setActionLoading(false);
    if ((response.statusCode ?? 500) >= 200 &&
        (response.statusCode ?? 500) < 300 &&
        response.body is Map && response.body['success'] == true) {
      await load(silent: true);
      return true;
    }
    _showError(response, 'طريقة التحقق المطلوبة غير متاحة');
    return false;
  }

  Future<bool> startBiometric() async {
    _setActionLoading(true);
    final selected = await repo.updatePrivacy('automated');
    if ((selected.statusCode ?? 500) < 200 ||
        (selected.statusCode ?? 500) >= 300 ||
        selected.body is! Map || selected.body['success'] != true) {
      _setActionLoading(false);
      _showError(selected, 'التحقق الآلي غير متاح');
      return false;
    }

    final response = await repo.startBiometric();
    _setActionLoading(false);
    if ((response.statusCode ?? 500) >= 200 &&
        (response.statusCode ?? 500) < 300 &&
        response.body is Map && response.body['success'] == true) {
      showCustomSnackBarHelper(
        response.body['message']?.toString() ?? 'تم بدء جلسة التحقق الآلي',
        isError: false,
      );
      await load(silent: true);
      return true;
    }
    _showError(response, 'تعذر بدء التحقق الآلي');
    return false;
  }

  void _setActionLoading(bool value) {
    _isActionLoading = value;
    update();
  }

  void _showError(Response response, String fallback) {
    if (response.statusCode == 401 || response.statusCode == 403) {
      ApiChecker.checkApi(response);
      return;
    }

    String message = fallback;
    final body = response.body;
    if (body is Map) {
      final direct = body['message']?.toString();
      if (direct != null && direct.trim().isNotEmpty) {
        message = direct;
      } else if (body['errors'] is List && (body['errors'] as List).isNotEmpty) {
        final first = (body['errors'] as List).first;
        if (first is Map && first['message'] != null) {
          message = first['message'].toString();
        }
      }
    }
    showCustomSnackBarHelper(message);
  }

  static Map<String, dynamic> _map(dynamic value) =>
      value is Map ? Map<String, dynamic>.from(value) : <String, dynamic>{};

  static List<Map<String, dynamic>> _optionList(dynamic value) {
    if (value is! List) return const [];
    return value.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
  }

  static int _asInt(dynamic value) => int.tryParse('$value') ?? 0;
}
