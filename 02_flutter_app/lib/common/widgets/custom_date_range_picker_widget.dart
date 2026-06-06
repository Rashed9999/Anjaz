import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:amyal_pay/features/setting/controllers/theme_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class CustomDateRangePickerWidget extends StatefulWidget {
  final String? text;
  final String? image;
  final bool requiredField;
  final Function? selectDate;
  const CustomDateRangePickerWidget({super.key, this.text,this.image, this.requiredField = false,this.selectDate,});

  @override
  State<CustomDateRangePickerWidget> createState() => _CustomDateRangePickerWidgetState();
}

bool _isDateSet(String dateText, {String format = 'd MMM,y'}) {
  try {
    DateFormat(format).parse(dateText);
    return true;
  } catch (e) {
    return false;
  }
}

class _CustomDateRangePickerWidgetState extends State<CustomDateRangePickerWidget> {
  @override
  Widget build(BuildContext context) {
    return GetBuilder<ThemeController>(
      builder: (themeController) {
        return GestureDetector(
          onTap: widget.selectDate as void Function()?,
          child: Container(
            margin:  const EdgeInsets.only(left: Dimensions.paddingSizeSmall,right: Dimensions.paddingSizeSmall),
            child: Container(
              height: 40,
              padding:  const EdgeInsets.fromLTRB(Dimensions.paddingSizeExtraSmall,Dimensions.paddingSizeExtraSmall,0,Dimensions.paddingSizeExtraSmall),

              child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,children: [
                Text(widget.text ?? '', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall,
                    color: _isDateSet(widget.text ?? '') ? Theme.of(context).primaryColor : Theme.of(context).hintColor,
                )),
                const SizedBox(width: Dimensions.paddingSizeExtraSmall),
              ]),
            ),
          ),
        );
      }
    );
  }
}



