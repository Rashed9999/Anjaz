import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/common/controllers/share_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_small_button_widget.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';


class ProfileQRCodeBottomSheetWidget extends StatelessWidget {
  const ProfileQRCodeBottomSheetWidget({ super.key });

  @override
  Widget build(BuildContext context) {
    final Size size = MediaQuery.of(context).size;
    return Container(
     
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: const BorderRadius.only(topLeft: Radius.circular(Dimensions.radiusSizeLarge),topRight:Radius.circular(Dimensions.radiusSizeLarge) ),
        boxShadow: [
          BoxShadow(color: Theme.of(context).textTheme.bodyLarge!.color!.withValues(alpha:0.5), blurRadius: 80, offset: const Offset(0, 20)),
          ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 8.0,),

          Row(mainAxisAlignment: MainAxisAlignment.center,
              children: [
            Container( height: 4.0,width: 32.0, decoration: BoxDecoration(color: Theme.of(context).hintColor.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(32.0)))]
          ),
          const SizedBox(height: Dimensions.paddingSizeLarge),

          Padding(
            padding: const EdgeInsets.only(left: Dimensions.paddingSizeLarge,bottom: Dimensions.paddingSizeSmall),
            child: Text('my_qr_code'.tr,style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).textTheme.bodyLarge!.color!.withValues(alpha:0.6),)),
          ),
          // AMIAL-FIX(QR): كان يعرض SvgPicture.string لقيمة qrCode القادمة من
          // الخادم — وهي فارغة فيسقط إلى '<svg/>' فلا يظهر رمز أصلاً (دائرة
          // صفراء صمّاء). الآن نُولّد رمز QR محلياً من رقم الحساب/الهاتف عبر
          // QrDisplayWidget (نفس مولّد بقية الشاشات) فيظهر رمزاً حقيقياً.
          GetBuilder<ProfileController>(builder: (controller){
            final info = controller.userInfo;
            final uid = (info?.uniqueId ?? '').trim();
            final payload = uid.isNotEmpty ? uid : (info?.phone ?? '').trim();
            if (payload.isEmpty) {
              return const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('تعذّر توليد الرمز — بيانات الحساب غير متاحة'),
                ),
              );
            }
            return Center(
              child: QrDisplayWidget(
                data: payload,
                size: size.width * 0.5,
                caption: payload,
              ),
            );
          }),

          const SizedBox(height: 50.0,),

          Padding(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
            child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,   children: [
              Expanded(
                child: CustomSmallButtonWidget(
                  text: 'download'.tr,
                  textSize: Dimensions.fontSizeLarge,
                  textColor: Theme.of(context).textTheme.bodyLarge!.color,
                  backgroundColor: Theme.of(context).colorScheme.secondary,
                  onTap: () => Get.find<ShareController>().qrCodeDownloadAndShare(qrCode: (Get.find<ProfileController>().userInfo?.qrCode ?? ''), phoneNumber: (Get.find<ProfileController>().userInfo?.phone ?? ''),isShare: false),
                ),
                /*child: Container(
                  alignment: Alignment.center,

                  decoration: BoxDecoration(borderRadius: BorderRadius.circular(Dimensions.RADIUS_SIZE_SMALL),color: Theme.of(context).colorScheme.secondary),
                  child: CustomInkWell(
                    onTap: () => Get.find<ScreenShootWidgetController>().qrCodeDownloadAndShare(qrCode: Get.find<ProfileController>().userInfo.qrCode, phoneNumber: Get.find<ProfileController>().userInfo.phone,isShare: false),
                    child: Padding(
                      padding: const EdgeInsets.symmetric( vertical: 14.0),
                      child: Text('download'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.FONT_SIZE_LARGE),),
                    ),
                  ),
                ),*/
              ),
              const SizedBox(width: Dimensions.paddingSizeLarge),

              Expanded(
                child: CustomSmallButtonWidget(
                  text: 'share_QR_code'.tr,
                  textSize: Dimensions.fontSizeLarge,
                  textColor: Theme.of(context).cardColor,
                  backgroundColor: Theme.of(context).textTheme.titleLarge!.color,
                  onTap: () => Get.find<ShareController>().qrCodeDownloadAndShare(qrCode: (Get.find<ProfileController>().userInfo?.qrCode ?? ''), phoneNumber: (Get.find<ProfileController>().userInfo?.phone ?? ''),isShare: true),
                ),
                /*child: InkWell(
                  onTap: (){
                    Get.find<ScreenShootWidgetController>().qrCodeDownloadAndShare(qrCode: Get.find<ProfileController>().userInfo.qrCode, phoneNumber: Get.find<ProfileController>().userInfo.phone,isShare: true);

                    },
                  child: Container(
                    alignment: Alignment.center,
                    decoration: BoxDecoration(borderRadius: BorderRadius.circular(Dimensions.RADIUS_SIZE_SMALL),color: Theme.of(context).primaryColor),
                    child: CustomInkWell(
                      onTap: ()=> Get.find<ScreenShootWidgetController>().qrCodeDownloadAndShare(qrCode: Get.find<ProfileController>().userInfo.qrCode, phoneNumber: Get.find<ProfileController>().userInfo.phone,isShare: true),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 9.0, vertical: 14.0),
                        child: Text('share_QR_code'.tr,maxLines: 1, style: rubikMedium.copyWith(fontSize: Dimensions.FONT_SIZE_LARGE,color: Colors.white),),
                      ),
                    ),
                  ),
                ),*/
              ),
            ],),
          ),
        const SizedBox(height: 50.0),




        ],
      ),
      
    );
  }
}