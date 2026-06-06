
import 'package:country_code_picker/country_code_picker.dart' show codes;
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';
import 'package:phone_numbers_parser/phone_numbers_parser.dart';

class PhoneNumberHelper {
  static Future<PhoneNumber?> isNumberValid(String phone) async {
    PhoneNumber? num;
    try{
      num = PhoneNumber.parse(phone);

    }catch(e){
      debugPrint('error ---> $e');
    }
    return num ;
  }

  static String? getCountryCode(String? number) {
    String? countryCode;
    try{
      countryCode = codes.firstWhere((item) =>
          number!.contains('${item['dial_code']}'))['dial_code'];
    }catch(error){
      debugPrint('country error: $error');
    }
    return countryCode;
  }

  static String? getPhoneNumber(String? number) {
    try {
      for (var item in codes) {
        final dialCode = item['dial_code'] as String;
        if (number != null && number.startsWith(dialCode)) {
          return number.replaceFirst(dialCode, '');
        }
      }
    } catch (error) {
      debugPrint('phone error: $error');
    }
    return number;
  }

  static String? onValidatePhone({required String countryCode, required TextEditingController controller}) {

    String phoneNumber = '$countryCode${controller.text}';
    PhoneNumber phone = PhoneNumber.parse(phoneNumber);
    if(phone.isValid(type: PhoneNumberType.mobile)){
      controller.text = phone.nsn;
      return null;
    }
    return "please_input_your_valid_number".tr;

  }

  static String getValidatePhoneNumberWithPhoneParser({required String? countryCode, required String phoneNumber}) {
    PhoneNumber phone = PhoneNumber.parse('$countryCode${phoneNumber.trim()}');

    return '$countryCode${phone.nsn}';
  }


}