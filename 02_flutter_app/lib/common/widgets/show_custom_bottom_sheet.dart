import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/util/dimensions.dart';

Future<void> showCustomBottomSheet({required Widget child}) async {
  await Get.bottomSheet(
    SafeArea(
      child: LayoutBuilder(
        builder: (context, constraints) {
          /// removes the keyboard height to avoid overflow
          final availableHeight = (MediaQuery.of(Get.context!).size.height * 0.8) - MediaQuery.of(context).viewInsets.bottom;

          return ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: availableHeight,
            ),
            child: child,
          );
        },
      ),
    ),
    useRootNavigator: true,
    backgroundColor: Theme.of(Get.context!).cardColor,
    isScrollControlled: true,
    barrierColor: Colors.black.withValues(alpha:  Get.isDarkMode ? 0.8 : 0.6),
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.only(
        topLeft: Radius.circular(Dimensions.radiusSizeExtraLarge),
        topRight: Radius.circular(Dimensions.radiusSizeExtraLarge),
      ),
    ),
  );
}

