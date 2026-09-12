import 'package:amial_pay/features/setting/domain/models/profile_model.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/camera_verification/controllers/camera_screen_controller.dart';
import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/common/models/response_model.dart';
import 'package:amial_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:get/get.dart';

class EditProfileController extends GetxController implements GetxService{
  final AuthRepo authRepo;
  EditProfileController({required this.authRepo});

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  String? _image ;
  String? get image => _image;
  void setImage(String image){
    _image = image;
  }

  ///gender
  String? _gender;
  String? get gender => _gender;

  void setGender(String select, {bool isUpdate = true}){
    _gender = select;

    if(isUpdate){
      update();
    }
  }

  ///occupation
  String? _occupation ;
  String? get occupation => _occupation;

  Future<bool> updateProfileData(ProfileModel editProfileBody,List<MultipartBody> multipartBody) async{
    _isLoading = true;
    bool isSuccess = false;
    update();

    // AMIAL-EMAIL-IDENTITY-001: البريد أصبح اعتماد استعادة وليس حقل ملف
    // شخصي عادياً. لا نرسله إلى update-profile مطلقاً؛ تغيير البريد يمر من
    // /auth/email-change/request ثم /confirm (كلمة المرور الحالية + OTP إلى
    // البريد الجديد). الخادم يرفض أي مسار قديم يحاول تغييره مباشرة أيضاً.
    Map<String, String> allProfileInfo = {
      'f_name': editProfileBody.fName ?? '',
      'l_name': editProfileBody.lName ?? '',
      'gender': editProfileBody.gender ?? '',
      'occupation': editProfileBody.occupation ?? '',
      '_method': 'put',
    };

    Response response = await authRepo.updateProfile(allProfileInfo, multipartBody);
    ResponseModel responseModel;
    if (response.statusCode == 200) {
      responseModel = ResponseModel(true, response.body['message']);
      isSuccess = true;
      if(Get.find<CameraScreenController>().getImage != null) {
        Get.find<CameraScreenController>().removeImage();
      }
      Get.find<ProfileController>().getProfileData(reload: true, isUpdate: true);
      Get.back();
      showCustomSnackBarHelper(responseModel.message, isError: false);
    }
    else {
      ApiChecker.checkApi(response);
    }
    _isLoading = false;
    update();
    return isSuccess;
  }
}
