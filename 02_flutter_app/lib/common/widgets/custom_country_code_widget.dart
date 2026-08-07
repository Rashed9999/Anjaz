import 'package:country_code_picker/country_code_picker.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
class CustomCountryCodeWidget extends StatelessWidget {
  final ValueChanged<CountryCode> onChanged;
  final ValueChanged<CountryCode?>? onInit;
  final String? initSelect;
  const CustomCountryCodeWidget({super.key, required this.onChanged, this.initSelect, this.onInit});


  @override
  Widget build(BuildContext context) {
    return CountryCodePicker(
      dialogBackgroundColor: Theme.of(context).canvasColor,
      padding: EdgeInsets.zero,
      onChanged: onChanged,
      onInit: onInit,
      showDropDownButton: true,
      initialSelection: initSelect ?? Get.find<SplashController>().configModel!.country,
      favorite: [CountryCode.fromCountryCode(Get.find<SplashController>().configModel!.country!).code!],
      showCountryOnly: false,
      showOnlyCountryWhenClosed: false,
      alignLeft: false,
      showFlag: false,
    );
  }


}
