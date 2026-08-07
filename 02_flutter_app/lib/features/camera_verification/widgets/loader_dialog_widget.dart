import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_large_widget.dart';
import 'package:amial_pay/features/auth/screens/amial_registration_wizard_screen.dart';
import 'package:amial_pay/features/setting/screens/edit_profile_screen.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';
import '../controllers/camera_screen_controller.dart';


class LoaderDialogWidget extends StatelessWidget {
  const LoaderDialogWidget({super.key});


  @override
  Widget build(BuildContext context) {

    final Size size = MediaQuery.of(context).size;

    return GetBuilder<CameraScreenController>(builder: (cameraScreenController) {
      return  cameraScreenController.isSuccess  == 0 ?
      const Center(child: CircularProgressIndicator())  : PopScope(
        canPop: false,
        onPopInvokedWithResult: ( _ , result){

        },
        child: Dialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          child: Padding(
            padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.center, children: [

              const SizedBox(height: Dimensions.paddingSizeLarge,),

              Image.asset( cameraScreenController.isSuccess  == 1 ? Images.successIcon : Images.failedIcon , height: 50,),

              const SizedBox(height: Dimensions.paddingSizeDefault),

              Text( cameraScreenController.isSuccess  == 1 ?  'face_scanning_successful'.tr : 'sorry_your_face_could_not_detect'.tr , textAlign: TextAlign.center,
                style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge,
                    color: Theme.of(context).textTheme.bodyLarge?.color?.withValues(alpha:0.8)
                ),
              ),
              const SizedBox(height: Dimensions.paddingSizeOverLarge),

              Visibility(
                visible: cameraScreenController.isSuccess  == 1,
                child: CustomLargeButtonWidget(
                  backgroundColor: Theme.of(context).colorScheme.secondary,
                  text: 'okay'.tr,
                  fontSize: Dimensions.fontSizeDefault,
                  onTap: () {
                    if(Get.find<CameraScreenController>().fromEditProfile) {
                      Get.off(() => const EditProfileScreen());
                    }else{
                      Get.off(() => const AmialRegistrationWizardScreen());
                    }
                  },
                ),
              ),

              Visibility(
                visible: cameraScreenController.isSuccess  != 1,
                child: Row(children: [

                  Expanded(child: CustomLargeButtonWidget(
                    backgroundColor: Theme.of(context).hoverColor,
                    padding: EdgeInsets.zero,
                    fontSize: Dimensions.fontSizeDefault,
                    text: 'skip'.tr,
                    onTap: () {
                      if(Get.find<CameraScreenController>().fromEditProfile) {
                        Get.off(() => const EditProfileScreen());
                      }else{
                        Get.off(() => const AmialRegistrationWizardScreen());
                      }
                    },
                  )),
                  SizedBox(width: size.width * 0.08),

                  Expanded(
                    child: CustomLargeButtonWidget(
                      backgroundColor: Theme.of(context).colorScheme.secondary,
                      text: 'retry'.tr,
                      fontSize: Dimensions.fontSizeDefault,
                      padding: EdgeInsets.zero,
                      onTap: () {
                        cameraScreenController.valueInitialize(cameraScreenController.fromEditProfile);
                        Get.back();
                        cameraScreenController.startLiveFeed();
                      },
                    ),
                  ),

                ]),
              ),

            ]),
          ),
        ),
      );
    });
  }
}
