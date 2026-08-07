import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/setting/controllers/theme_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:syncfusion_flutter_datepicker/datepicker.dart';

class CustomCalendarWidget extends StatefulWidget {
  final PickerDateRange? initDateRange;
  final Function(PickerDateRange? dateRange) onSubmit;
  final Function() onCancel;
  const CustomCalendarWidget({super.key, required this.onSubmit, required this.onCancel, this.initDateRange});

  @override
  State<CustomCalendarWidget> createState() => _CustomCalendarWidgetState();
}

class _CustomCalendarWidgetState extends State<CustomCalendarWidget> {

  @override
  Widget build(BuildContext context) {

    return GetBuilder<ThemeController>(
        builder: (themeController) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(Dimensions.paddingSizeDefault),
        child: Container(
          color: themeController.darkTheme ? Colors.white12 : Theme.of(context).cardColor,
          child: SfDateRangePicker(
            backgroundColor:  themeController.darkTheme ? Colors.white12 : null,
            confirmText: 'ok'.tr,
            showActionButtons: true,
            cancelText: 'cancel'.tr,
            onCancel: () => widget.onCancel(),
            onSubmit: (value){

              if(value is PickerDateRange) {

                widget.onSubmit(value);

                Navigator.pop(context);
              }

            },
            todayHighlightColor: themeController.darkTheme ? Theme.of(context).cardColor : Theme.of(context).primaryColor,
            selectionMode: DateRangePickerSelectionMode.range,
            rangeSelectionColor: Theme.of(context).primaryColor.withValues(alpha:.50),
            view: DateRangePickerView.month,
            selectionTextStyle: TextStyle(color: Theme.of(context).cardColor),
            startRangeSelectionColor: Theme.of(context).colorScheme.primary,
            endRangeSelectionColor: Theme.of(context).colorScheme.primary,
            initialSelectedRange:  PickerDateRange(
              widget.initDateRange?.startDate,
              widget.initDateRange?.endDate,
            ),
          ),),
      );
    });

  }
}
