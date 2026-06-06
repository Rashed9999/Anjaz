import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_dialog_widget.dart';
import 'package:amyal_pay/features/splash/screens/splash_screen.dart';
import 'package:amyal_pay/helper/dialog_helper.dart';


class CustomPopScopeWidget extends StatefulWidget {
  final Widget child;
  final Function()? onPopInvoked;
  final bool isExit;
  const CustomPopScopeWidget({super.key, required this.child, this.onPopInvoked, this.isExit = true});

  @override
  State<CustomPopScopeWidget> createState() => _CustomPopScopeWidgetState();
}

class _CustomPopScopeWidgetState extends State<CustomPopScopeWidget> {

  @override
  Widget build(BuildContext context) {

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) {

        if (widget.onPopInvoked != null) {
          widget.onPopInvoked!();
        }

        if(didPop) {
          return;
        }

        if(widget.isExit) {
          if(!Navigator.canPop(context)) {

            DialogHelper.showAnimatedDialog(
              context,
              CustomDialogWidget(
                icon: Icons.exit_to_app_rounded,
                title: 'exit'.tr,
                description: 'do_you_want_to_exit_the_app'.tr,
                onTapFalse:() => Navigator.of(context).pop(false),
                onTapTrue:()=> SystemNavigator.pop().then((value) => Get.offAll(()=> const SplashScreen())),
                onTapTrueText: 'yes'.tr, onTapFalseText: 'no'.tr,
              ),
              dismissible: false,
              isFlip: true,
            );

          }else {
            if(Navigator.canPop(context)) {
              Navigator.pop(context);
            }
          }
        }




      },
      child: widget.child,
    );
  }
}
