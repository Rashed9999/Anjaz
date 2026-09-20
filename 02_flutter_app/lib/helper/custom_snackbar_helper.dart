import 'package:flutter/material.dart';
import 'package:flutter_smart_dialog/flutter_smart_dialog.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

String _arabicUserError(String message) {
  final raw = message.trim();
  if (raw.isEmpty) return raw;
  if (RegExp(r'[\u0600-\u06FF]').hasMatch(raw)) return raw;

  final value = raw.toLowerCase();
  if (value.contains('access denied') ||
      value.contains('access forbidden') ||
      value.contains('permission denied') ||
      value.contains('forbidden')) {
    return 'api_error_forbidden'.tr;
  }
  if (value.contains('unauthorized') ||
      value.contains('token') ||
      value.contains('session expired')) {
    return 'api_error_session_expired'.tr;
  }
  if (value.contains('not found') || value.contains('no resource')) {
    return 'api_error_not_found'.tr;
  }
  if (value.contains('invalid') || value.contains('missing')) {
    return 'api_error_invalid'.tr;
  }
  if (value.contains('connection') ||
      value.contains('network') ||
      value.contains('internet')) {
    return 'api_error_network'.tr;
  }
  if (value.contains('something went wrong') ||
      value.contains('server error') ||
      value.contains('internal server')) {
    return 'api_error_server'.tr;
  }

  // لا نعرض نصاً تقنياً إنجليزياً للمستخدم في شريط خطأ.
  return 'api_error_generic'.tr;
}

void showCustomSnackBarHelper(String? message, {bool isError = true, bool isIcon = false, bool isVpn = false, Duration? duration}) {
  if(isVpn) {
    SmartDialog.show(
      onDismiss: () async {
        if(await ApiChecker.isVpnActive()) {
          showCustomSnackBarHelper('', isVpn: true, duration: const Duration(minutes: 10));
        }
      },
      alignment: Alignment.topCenter,
      builder: (_) {
        return Container(
          width: Get.width,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            color: Theme.of(Get.context!).colorScheme.error,
          ),
          child: SafeArea(child: Padding(
            padding: const EdgeInsets.symmetric(
              vertical: Dimensions.paddingSizeSmall,
              horizontal: Dimensions.paddingSizeLarge,
            ),
            child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('please_disable_the_vpn'.tr, style: rubikLight.copyWith(color: Colors.white),),

                IconButton(
                  icon: const Icon(Icons.clear,size: 25),
                  color: Colors.white,
                  onPressed: () {
                    SmartDialog.dismiss();
                  },
                ),
              ],
            ),
          )),
        );
      },
    );

  }else{
    if(message != null && message.isNotEmpty){
      final safeMessage = isError ? _arabicUserError(message) : message;
      if (Get.context == null && Get.overlayContext == null) {
        debugPrint(safeMessage);
        return;
      }
      Get.closeAllSnackbars();
      Get..closeCurrentSnackbar()..showSnackbar(GetSnackBar(
        snackPosition: SnackPosition.BOTTOM,

        message: safeMessage,
        duration: duration ?? const Duration(seconds: 5),
        isDismissible: true,
        backgroundColor:  isError ? Colors.red : Colors.green,

        icon: isIcon ?  IconButton(icon: const Icon(Icons.clear,size: 16,),color: Colors.white,
            onPressed: (){
              Get.back();
            }) : null,
      ));


    }
  }


}