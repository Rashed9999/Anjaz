
import 'package:flutter/material.dart';
import 'package:amyal_pay/util/images.dart';

class CustomLogoWidget extends StatelessWidget {
  final double? height,width;
  const CustomLogoWidget({super.key,
    this.height,this.width
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: height,
      width: width,
      child: Image.asset(Images.logo),

    );
  }
}
