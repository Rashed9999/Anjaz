import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_button_widget.dart';
import 'package:amyal_pay/common/widgets/custom_password_field_widget.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/history/controllers/report_dispute_controller.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class DisputeSubmitVerifyDialog extends StatefulWidget {
  final String? inputComment;
  final String? transactionId;
  const DisputeSubmitVerifyDialog({super.key, required this.inputComment, required this.transactionId});

  @override
  State<DisputeSubmitVerifyDialog> createState() => _DisputeSubmitVerifyDialogState();
}

class _DisputeSubmitVerifyDialogState extends State<DisputeSubmitVerifyDialog> {
  final TextEditingController _passwordTextController = TextEditingController();

  @override
  void initState() {
    super.initState();

    _passwordTextController.addListener((){
      if (!mounted) return; // AMIAL-FIX-006
      setState(() {

      });
    });
  }



  @override
  void dispose() {
    super.dispose();

    _passwordTextController.dispose();

  }

  @override
  Widget build(BuildContext context) {
    bool isPasswordValid = _passwordTextController.text.trim().length == 4;
    final AuthController authController = Get.find<AuthController>();
    final bool isBioActive = (authController.biometric && authController.bioList.isNotEmpty);

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Dimensions.paddingSizeLarge)),
      insetPadding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
      child: Padding(
        padding: const EdgeInsets.all(Dimensions.paddingSizeLarge).copyWith(top: Dimensions.paddingSizeSmall),
        child: Column(mainAxisSize: MainAxisSize.min, children: [

          Align(
            alignment: Alignment.centerRight,
            child: InkWell(
              splashColor: Colors.transparent,
              highlightColor: Colors.transparent,
              onTap: ()=> Navigator.pop(context),
              child: Container(
                transform: Matrix4.translationValues(10, 0, 0),
                decoration: BoxDecoration(
                  color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                child: Icon(Icons.clear, color: Theme.of(context).primaryColor, size: Dimensions.paddingSizeDefault),
              ),
            ),
          ),

          Text('verify_before_submit'.tr, style: rubikSemiBold.copyWith(
            fontSize: Dimensions.fontSizeSemiLarge,
            color: Theme.of(context).textTheme.bodyLarge?.color,
          ), overflow: TextOverflow.ellipsis),
          const SizedBox(height: Dimensions.paddingSizeExtraSmall),

          if(isBioActive) Text(
            'verify_using_pin_or_fingerprint'.tr,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: rubikRegular.copyWith(
              fontSize: Dimensions.fontSizeDefault,
              color: Theme.of(context).hintColor,
            ),
          ),
          const SizedBox(height: Dimensions.paddingSizeExtraLarge),

          Row(crossAxisAlignment: CrossAxisAlignment.start,children: [
            Expanded(child: CustomPasswordFieldWidget(
              controller: _passwordTextController,
              hint: '* * * *',
              isShowSuffixIcon: false,
              isPassword: true,
              letterSpacing: Dimensions.paddingSizeDefault,
              isIcon: false,
              textAlign: TextAlign.center,
              showMaxLength: false,
              hintTextStyle: rubikRegular.copyWith(
                letterSpacing: Dimensions.paddingSizeSmall,
                fontSize: Dimensions.fontSizeDefault,
                color: Theme.of(context).primaryColor,
              ),
              textStyle: rubikRegular.copyWith(
                letterSpacing: Dimensions.paddingSizeExtraLarge,
                fontSize: Dimensions.fontSizeLarge,
                color: Theme.of(context).primaryColor,
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                borderSide: BorderSide(
                  color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                  width: 1,
                ),
              ),
            )),
            const SizedBox(width: Dimensions.paddingSizeDefault),

           if(isBioActive) InkWell(
              onTap: () async {
                _createReportDisputeWithBioAuth(await authController.authenticateWithBiometric('please_authenticate_to_submit_report'.tr));

              },
              child: Container(
                padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                decoration: BoxDecoration(
                  border: Border.all(color: Theme.of(context).hintColor.withValues(alpha: 0.1), width: 1),
                  borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                ),
                child: Icon(Icons.fingerprint, size: Dimensions.radiusSizeExtraLarge, color: Theme.of(context).primaryColor),
              ),
            ),

          ]),
          const SizedBox(height: Dimensions.paddingSizeExtraLarge),

          GetBuilder<ReportDisputeController>(builder: (reportDisputeController) {
            return SizedBox(
              height: Dimensions.paddingSizeOverLarge,
              child: CustomButtonWidget(
                isLoading: reportDisputeController.isLoading,
                onTap: !isPasswordValid ? null : ()  async {
                  await _createReportDispute(_passwordTextController.text.trim());

                },
                buttonText: 'submit'.tr,
                buttonTextStyle: rubikSemiBold,
                borderRadius: Dimensions.radiusSizeExtraSmall,
              ),
            );
          }),
        ]),
      ),
    );
  }

  Future<void> _createReportDispute(String pin) async {
    final bool isSuccess = await Get.find<ReportDisputeController>().createReportDispute(
       pin: pin,
       comment: widget.inputComment,
       transactionId: widget.transactionId,
     );

     if(isSuccess) {
       Get.back();
       showCustomSnackBarHelper('report_has_been_submitted'.tr, isError: false);
     }
  }

  void _createReportDisputeWithBioAuth(bool isAuthenticated) async {
    if(isAuthenticated) {
      _createReportDispute(await Get.find<AuthController>().biometricPin());
    }

  }
}
