import 'package:flutter/material.dart';
import 'package:pin_code_fields/pin_code_fields.dart';

class CustomPinCodeFieldWidget extends StatelessWidget {
  final Function? onCompleted;
  final Function? onChange;
  final double padding;
  const CustomPinCodeFieldWidget({super.key, this.onCompleted, this.padding = 0.0, this.onChange}) ;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: padding),
      child: PinCodeTextField(
        autoFocus: true,
        appContext: context,
        length: 4,
        obscureText: false,
        animationType: AnimationType.fade,
        showCursor: true,
        cursorColor: Theme.of(context).textTheme.bodyLarge?.color,
        keyboardType: TextInputType.number,
        pinTheme: PinTheme(
            shape: PinCodeFieldShape.box,
            borderRadius: BorderRadius.circular(10),
            fieldHeight: 60,
            fieldWidth: 60,
            activeFillColor: Theme.of(context).cardColor,
            selectedColor: Theme.of(context).textTheme.titleLarge!.color,
            selectedFillColor: Theme.of(context).cardColor,
            inactiveFillColor: Theme.of(context).cardColor,
            inactiveColor: Theme.of(context).hintColor.withValues(alpha: 0.2),
            activeColor: Theme.of(context).primaryColor,
            borderWidth: 0.5,
            activeBorderWidth: 1,
            inactiveBorderWidth: 0.5,
            errorBorderWidth: 0.5,
            selectedBorderWidth: 0.5
        ),
        animationDuration: const Duration(milliseconds: 300),
        backgroundColor: Colors.transparent,
        enableActiveFill: true,
        onCompleted: onCompleted as void Function(String)?,
        onChanged: onChange as void Function(String)?,
        beforeTextPaste: (text) {
          //if you return true then it will show the paste confirmation dialog. Otherwise if false, then nothing will happen.
          //but you can show anything you want here, like your pop up saying wrong paste format or etc
          return true;
        },
      ),
    );
  }
}
