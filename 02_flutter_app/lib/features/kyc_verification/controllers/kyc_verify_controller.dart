import 'dart:io';

import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/features/kyc_verification/domain/reposotories/kyc_verify_repo.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/helper/file_validation_helper.dart';
import '../../../data/api/api_client.dart';

class KycVerifyController extends GetxController implements GetxService {
  final KycVerifyRepo kycVerifyRepo;
  KycVerifyController({required this.kycVerifyRepo});

  final List<XFile> _identityImage = [];
  List<XFile> get identityImage => _identityImage;

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  /// Tier 2 للأفراد لا يستقبل رخصة نشاط تجاري؛ تلك وثيقة تحقق التاجر.
  final List<String> _dropList = [
    'select_identity_type',
    'nid',
    'passport',
    'driving_licence',
  ];
  List<String> get dropList => _dropList;

  String _dropDownSelectedValue = 'select_identity_type';
  String get dropDownSelectedValue => _dropDownSelectedValue;

  void dropDownChange(String value) {
    _dropDownSelectedValue = value;
    update();
  }

  void initialSelect() {
    _dropDownSelectedValue = 'select_identity_type';
    _identityImage.clear();
    _isLoading = false;
  }

  /// AMIAL-PROGRESSIVE-KYC-UPGRADE-APP-001
  /// صورتان فقط: الأولى وجه/الصفحة الرئيسية، والثانية الظهر/الصفحة المقابلة.
  /// حتى لو أعاد منتقي الملفات أكثر من صورتين لا ندفع صورة ثالثة بصمت.
  Future<void> pickImage(bool isRemove) async {
    if (isRemove) {
      _identityImage.clear();
      update();
      return;
    }

    if (_identityImage.length >= 2) {
      showCustomSnackBarHelper('تم اختيار صورتي الهوية المطلوبتين بالفعل');
      return;
    }

    final picked = await FileValidationHelper.validateAndPickMultipleImages();
    if (picked == null || picked.isEmpty) return;

    final remaining = 2 - _identityImage.length;
    _identityImage.addAll(picked.take(remaining));
    if (picked.length > remaining) {
      showCustomSnackBarHelper('توثيق الهوية يحتاج صورتين فقط: وجه الهوية وظهرها');
    }
    update();
  }

  void removeImage(int index) {
    if (index < 0 || index >= _identityImage.length) return;
    _identityImage.removeAt(index);
    update();
  }

  Future<void> kycVerify(
    String idNumber, {
    String reviewMode = 'standard',
  }) async {
    if (_isLoading) return;
    if (_identityImage.length != 2) {
      showCustomSnackBarHelper('ارفع وجه الهوية وظهرها فقط');
      return;
    }

    final field = <String, String>{
      'identification_number': idNumber.trim(),
      'identification_type': _dropDownSelectedValue,
    };
    final multipart = <MultipartBody>[
      MultipartBody('id_front', File(_identityImage[0].path)),
      MultipartBody('id_back', File(_identityImage[1].path)),
    ];

    _isLoading = true;
    update();

    try {
      // اختيار الخصوصية يثبت قبل رفع أي وثيقة حساسة.
      final privacyResponse = await kycVerifyRepo.updatePrivacyMode(reviewMode);
      final privacyStatus = privacyResponse.statusCode ?? 500;
      final privacyOk = privacyStatus >= 200 &&
          privacyStatus < 300 &&
          privacyResponse.body is Map &&
          privacyResponse.body['success'] == true;
      if (!privacyOk) {
        showCustomSnackBarHelper(
          _responseMessage(privacyResponse, 'تعذّر حفظ إعداد الخصوصية'),
        );
        return;
      }

      final response = await kycVerifyRepo.kycVerifyApi(field, multipart);
      final status = response.statusCode ?? 500;
      final ok = status >= 200 &&
          status < 300 &&
          response.body is Map &&
          response.body['success'] == true;

      if (ok) {
        final message = _responseMessage(
          response,
          'تم إرسال الهوية للمراجعة. لا نطلب صورة شخصية للمستوى الثاني.',
        );
        Get.back();
        showCustomSnackBarHelper(message, isError: false);
        return;
      }

      final message = _responseMessage(response, 'تعذّر إرسال الهوية للمراجعة');
      if (status == 401 || status == 403 && response.body is! Map) {
        ApiChecker.checkApi(response);
      } else {
        showCustomSnackBarHelper(message);
      }
    } finally {
      _isLoading = false;
      update();
    }
  }

  String _responseMessage(Response response, String fallback) {
    final body = response.body;
    if (body is Map) {
      final message = body['message']?.toString().trim();
      if (message != null && message.isNotEmpty) return message;

      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return first.first.toString();
        return first.toString();
      }
    }
    return fallback;
  }
}
