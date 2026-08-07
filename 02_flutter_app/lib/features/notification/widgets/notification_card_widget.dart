import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_image_widget.dart';
import 'package:amial_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amial_pay/features/notification/controllers/notification_controller.dart';
import 'package:amial_pay/features/notification/domain/models/notification_model.dart';
import 'package:amial_pay/features/notification/widgets/notification_dialog_widget.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/helper/custom_extension_helper.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';

class NotificationCardWidget extends StatelessWidget {
  final Notifications notification;
  final int index;
  const NotificationCardWidget({
    super.key, required this.notification, required this.index,
  });

  @override
  Widget build(BuildContext context) {

    return Container(
      margin: const EdgeInsets.only(bottom: Dimensions.paddingSizeExtraSmall),
      child: CustomInkWellWidget(
        onTap: (){
          showDialog(context: context, builder: (context) => NotificationDialogWidget(notificationModel: notification));


        },
        highlightColor: Theme.of(context).primaryColor.withValues(alpha:0.1),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

          if(_willShowDate(index, Get.find<NotificationController>().notificationList)) ...[
            const SizedBox(height: Dimensions.paddingSizeDefault),

            Padding(padding: const EdgeInsets.only(left: Dimensions.paddingSizeDefault),
              child: Text(
                DateConverterHelper.getRelativeDateStatus(notification.createdAt ?? '', context),
                style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeDefault,
                  fontWeight: FontWeight.w400,
                  color: Theme.of(context).textTheme.bodyLarge!.color!.withValues(alpha:0.70),
                ),
                textDirection: TextDirection.ltr,
              ),
            ),
            const SizedBox(height: Dimensions.paddingSizeSmall),
          ],

          Container(padding: const EdgeInsets.all(Dimensions.paddingSizeDefault),
            decoration: BoxDecoration(
              color: Theme.of(context).cardColor,
              boxShadow: [
                BoxShadow(
                    color: Colors.black.withValues(alpha:0.05),
                    spreadRadius: 0,
                    blurRadius: 7,
                    offset: const Offset(0, 1)
                ),
              ],
            ),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [

              Container(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.black.withValues(alpha: 0.05), width: 1),
                ),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraLarge),
                  child: CustomImageWidget(
                    placeholder: Images.placeholder,
                    height: 50, width: 50, fit: BoxFit.cover,
                    image: '${Get.find<SplashController>().configModel!.baseUrls!.notificationImageUrl
                    }/${notification.image}',
                  ),
                ),
              ),
              const SizedBox(width: Dimensions.paddingSizeSmall),

              Expanded(child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [

                  Text(notification.title!, style: rubikSemiBold.copyWith(
                    fontWeight: FontWeight.w400,
                    fontSize: Dimensions.fontSizeDefault,
                    color: Theme.of(context).textTheme.bodyLarge?.color,
                  ), overflow: TextOverflow.ellipsis),
                  const SizedBox(height: Dimensions.paddingSizeSmall),

                  Text(
                    notification.description!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: rubikRegular.copyWith(
                      fontSize: Dimensions.radiusSizeSmall,
                      color: Theme.of(context).hintColor,
                    ),
                  ),
                ],
              )),
              const SizedBox(width: Dimensions.paddingSizeDefault),

              Text(
                DateConverterHelper.getDifferenceFromPresent(notification.createdAt ?? '') == 0 ?
                DateConverterHelper.timeAgo(notification.createdAt ?? '') :
                DateConverterHelper.isoStringToLocalTimeOnly(notification.createdAt ?? ''),
                style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeSmall,
                  color: context.customThemeColors.info,
                ),
              ),
            ]),
          ),
        ],
        ),
      ),
    );
  }

  bool _willShowDate(int index, List<Notifications>? notificationList) {
    if (notificationList?.isEmpty ?? true) return false;
    if (index == 0) return true;

    final currentSupportTicket = notificationList?[index];
    final currentSupportTicketDate = DateTime.tryParse(currentSupportTicket?.createdAt ?? '');

    final previousSupportTicket = notificationList?[index - 1];
    final previousSupportTicketDate = DateTime.tryParse(previousSupportTicket?.createdAt ?? '');

    return currentSupportTicketDate?.day != previousSupportTicketDate?.day;
  }

}