import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/features/notification/domain/models/notification_model.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/common/widgets/custom_image_widget.dart';

class NotificationDialogWidget extends StatelessWidget {
  final Notifications notificationModel;
  const NotificationDialogWidget({super.key, required this.notificationModel});

  @override
  Widget build(BuildContext context) {
    return Dialog(
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(10.0))),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.of(context).size.height * 0.8,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Align(
              alignment: Alignment.centerRight,
              child: IconButton(
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.of(context).pop(),
              ),
            ),

            Flexible(child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Container(
                  height: MediaQuery.of(context).size.width - 130,
                  width: MediaQuery.of(context).size.width,
                  margin: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    color: Theme.of(context).primaryColor.withValues(alpha: 0.20),
                  ),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: CustomImageWidget(
                      placeholder: Images.placeholder,
                      image: '${Get.find<SplashController>().configModel!.baseUrls?.notificationImageUrl}/${notificationModel.image}',
                      height: MediaQuery.of(context).size.width - 130,
                      width: MediaQuery.of(context).size.width,
                      fit: BoxFit.cover,
                    ),
                  ),
                ),
                const SizedBox(height: Dimensions.paddingSizeLarge),

                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                  child: Text(
                    notificationModel.title ?? '',
                    textAlign: TextAlign.center,
                    style: rubikMedium.copyWith(
                      color: Theme.of(context).primaryColor,
                      fontSize: Dimensions.fontSizeLarge,
                    ),
                  ),
                ),

                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 10, 20, 20),
                  child: Text(
                    notificationModel.description!,
                    textAlign: TextAlign.center,
                    style: rubikRegular,
                  ),
                ),
              ]),
            )),
          ],
        ),
      ),
    );
  }
}