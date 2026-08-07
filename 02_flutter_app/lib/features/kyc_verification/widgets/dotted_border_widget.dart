import 'dart:io';

import 'package:dotted_border/dotted_border.dart';
import 'package:flutter/material.dart';
import 'package:get/get_core/src/get_main.dart';
import 'package:get/get_instance/src/extension_instance.dart';
import 'package:amial_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amial_pay/features/kyc_verification/controllers/kyc_verify_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';

class DottedBorderWidget extends StatelessWidget {
  final String? path;
  const DottedBorderWidget({super.key, this.path});


  @override
  Widget build(BuildContext context) {
    return DottedBorder(
      options: RoundedRectDottedBorderOptions(
        dashPattern: const [10],
        strokeWidth: 0.5,
        color: Theme.of(context).hintColor,
        radius: const Radius.circular(Dimensions.paddingSizeSmall),
      ),
      child: CustomInkWellWidget(
        onTap: path != null ? null :()=> Get.find<KycVerifyController>().pickImage(false),
        child: path != null ? Padding(
          padding: const EdgeInsets.all(8.0),
          child: ClipRRect(
            borderRadius: const BorderRadius.all(Radius.circular(Dimensions.paddingSizeSmall)),
            child: Image.file(
              File(path ?? ''),
              width: 160, height: 100, fit: BoxFit.cover,
            ),
          ),
        ) :
        SizedBox(
          height: 100, width: 160, child: Padding(
          padding: const EdgeInsets.all(30),
          child: Image.asset(Images.cameraIcon),
        ),
        ),
      ),
    );
  }
}
