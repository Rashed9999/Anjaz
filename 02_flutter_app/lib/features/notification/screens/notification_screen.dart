
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_app_bar_widget.dart';
import 'package:amyal_pay/features/notification/controllers/notification_controller.dart';
import 'package:amyal_pay/features/notification/widgets/notification_card_widget.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/common/widgets/no_data_widget.dart';
import 'package:amyal_pay/common/widgets/paginated_list_widget.dart';
import 'package:amyal_pay/features/notification/widgets/notification_shimmer_widget.dart';
class NotificationScreen extends StatefulWidget {
  const NotificationScreen({ super.key });

  @override
  State<NotificationScreen> createState() => _NotificationScreenState();
}


class _NotificationScreenState extends State<NotificationScreen> {
  @override
  Widget build(BuildContext context) {
    final ScrollController scrollController = ScrollController();
    return Scaffold(
      appBar: CustomAppbarWidget(title: 'notification'.tr, onlyTitle: true),
      body: RefreshIndicator(
        onRefresh: () async{
          await Get.find<NotificationController>().getNotificationList(true);
        },
        child: GetBuilder<NotificationController>(
          builder: (notificationController) {
            return notificationController.notificationModel == null ? const NotificationShimmerWidget() :  notificationController.notificationList.isNotEmpty ?
            PaginatedListWidget(
              scrollController: scrollController,
              totalSize: notificationController.notificationModel?.totalSize,
              onPaginate: (int offset) async => await notificationController.getNotificationList(false, offset: offset),
              offset: notificationController.notificationModel?.offset,
              itemView: Expanded(
                child: ListView.builder(
                  controller : scrollController,
                  itemCount: notificationController.notificationList.length,
                  padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
                  itemBuilder: (context, index) {
                    return NotificationCardWidget(notification: notificationController.notificationList[index], index: index);
                  },
                ),
              ),
            ) : const NoDataFoundWidget();
          },
        ),
      ),
    );
  }
}

