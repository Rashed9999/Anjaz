import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_button_widget.dart';
import 'package:amyal_pay/common/widgets/custom_calendar_widget.dart';
import 'package:amyal_pay/common/widgets/custom_date_range_picker_widget.dart';
import 'package:amyal_pay/common/widgets/custom_drop_down_button_widget.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/history/domain/models/transaction_model.dart';
import 'package:amyal_pay/helper/date_converter_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:syncfusion_flutter_datepicker/datepicker.dart';

class TransactionFilterDrawerWidget extends StatefulWidget {
  const TransactionFilterDrawerWidget({super.key});

  @override
  State<TransactionFilterDrawerWidget> createState() => _TransactionFilterDrawerWidgetState();
}

class _TransactionFilterDrawerWidgetState extends State<TransactionFilterDrawerWidget> {

  @override
  void initState() {
    super.initState();
    Get.find<TransactionHistoryController>().initFilterData();
  }

  @override
  Widget build(BuildContext context) {
    final double heightSize = MediaQuery.sizeOf(context).height;

    return GetBuilder<TransactionHistoryController>(
        builder: (transactionHistoryController) {
          return SafeArea(child: Container(
            color: Theme.of(context).cardColor,
            child: Column(mainAxisSize: MainAxisSize.min,crossAxisAlignment: CrossAxisAlignment.start,children: [

              Container(
                color: Theme.of(context).hintColor.withValues(alpha: 0.04),
                padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault, vertical: Dimensions.paddingSizeSmall),
                child: Row(children: [
                  Text('filter_statement'.tr, style: rubikRegular.copyWith(
                    fontSize: Dimensions.fontSizeLarge,
                    color: Theme.of(context).primaryColor,
                  )),

                  const Spacer(),

                  InkWell(
                    splashColor: Colors.transparent,
                    highlightColor: Colors.transparent,
                    onTap: ()=> Get.back(),
                    child: Container(
                      decoration: BoxDecoration(
                        color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                        shape: BoxShape.circle,
                      ),
                      padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                      child: Icon(Icons.clear,   color: Theme.of(context).primaryColor, size: Dimensions.paddingSizeDefault),
                    ),
                  ),
                ]),
              ),
              const SizedBox(height: Dimensions.paddingSizeSmall),

              Padding(
                padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start,children: [

                  if(transactionHistoryController.transactionTypeIndex == 0) ...[
                    Text('transaction_type'.tr, style: rubikRegular.copyWith(
                      fontSize: Dimensions.fontSizeDefault,
                      color: Theme.of(context).textTheme.bodyLarge?.color,
                    )),
                    const SizedBox(height: Dimensions.paddingSizeSmall),

                    CustomDropDownButtonWidget(
                      borderRadius: Dimensions.radiusSizeExtraSmall,
                      borderColor: Theme.of(context).hintColor.withValues(alpha: 0.1),
                      value: transactionHistoryController.filterTransactionType ?? AppConstants.transactionTypeList.first,
                      textColor: Theme.of(context).textTheme.bodyLarge?.color,
                      itemList: AppConstants.transactionTypeList,
                      onChanged: (value){
                        transactionHistoryController.updateFilterTransactionType(value);
                      },
                    ),
                    const SizedBox(height: Dimensions.paddingSizeLarge),
                  ],

                  Text('date_range'.tr, style: rubikRegular.copyWith(
                    fontSize: Dimensions.fontSizeDefault,
                    color: Theme.of(context).textTheme.bodyLarge?.color,
                  )),
                  const SizedBox(height: Dimensions.paddingSizeSmall),

                  CustomDropDownButtonWidget(
                    borderRadius: Dimensions.radiusSizeExtraSmall,
                    borderColor: Theme.of(context).hintColor.withValues(alpha: 0.1),
                    value: transactionHistoryController.selectedDateRange ?? AppConstants.filterDateRangeList.first,
                    textColor: Theme.of(context).textTheme.bodyLarge?.color,
                    itemList: AppConstants.filterDateRangeList,
                    onChanged: (value){
                      transactionHistoryController.updateDateRange(value);
                    },
                  ),
                  const SizedBox(height: Dimensions.paddingSizeLarge),

                  if(transactionHistoryController.selectedDateRange == AppConstants.filterDateRangeList.last)...[
                    Text('custom_date_range'.tr, style: rubikRegular.copyWith(
                      fontSize: Dimensions.fontSizeDefault,
                      color: Theme.of(context).textTheme.bodyLarge?.color,
                    )),
                    const SizedBox(height: Dimensions.paddingSizeSmall),

                    InkWell(
                      onTap: ()=> showDialog(context: context, builder: (BuildContext context) {
                        return Dialog(child: SizedBox(height: heightSize * 0.45, child: CustomCalendarWidget(
                          initDateRange: PickerDateRange(transactionHistoryController.startDate, transactionHistoryController.endDate),
                          onSubmit: (range) {
                            transactionHistoryController.setSelectedDate(
                              startDate: range?.startDate,
                              endDate: range?.endDate ?? range?.startDate,
                            );
                          },
                          onCancel: ()=> Navigator.of(context).pop(),
                        )));
                      }),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeExtraSmall),
                        decoration: BoxDecoration(
                          border: Border.all(width: 1, color: Theme.of(context).hintColor.withValues(alpha: .15)),
                          borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                        ),
                        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [

                          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                            CustomDateRangePickerWidget(
                              text: transactionHistoryController.startDate == null ?
                              'from_date'.tr :
                              DateConverterHelper.dateStringMonthYear(transactionHistoryController.startDate),
                            ),

                            Icon(Icons.arrow_right_alt, size: Dimensions.paddingSizeLarge, color: Theme.of(context).hintColor),

                            CustomDateRangePickerWidget(
                              text: transactionHistoryController.endDate == null ?
                              'to_date'.tr :
                              DateConverterHelper.dateStringMonthYear(transactionHistoryController.endDate),
                            ),
                          ]),

                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
                            child: Icon(Icons.calendar_month, color: Theme.of(context).hintColor.withValues(alpha: 0.3)),
                          ),
                        ]),
                      ),
                    ),
                    const SizedBox(height: Dimensions.paddingSizeLarge),
                  ],
                ]),
              ),

              const Spacer(),

              Padding(
                padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge, vertical: Dimensions.radiusSizeExtraExtraLarge),
                child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,children: [

                  Expanded(child: SizedBox(
                    height: Dimensions.paddingSizeOverLarge,
                    child: CustomButtonWidget(
                      onTap: () async {

                        Get.back();

                        if(_canResetFilters()) {
                          transactionHistoryController.resetFilter();
                          await transactionHistoryController.getTransactionData(
                            1,
                            transactionType: transactionHistoryController.transactionType[transactionHistoryController.transactionTypeIndex],
                          );
                        }
                      },
                      buttonText: 'reset'.tr,
                      color: Theme.of(context).hintColor.withValues(alpha: 0.08),
                      buttonTextStyle: rubikMedium,
                      borderRadius: Dimensions.radiusSizeExtraSmall,
                    ),
                  )),
                  const SizedBox(width: Dimensions.paddingSizeExtraSmall),

                  Expanded(child: SizedBox(
                    height: Dimensions.paddingSizeOverLarge,
                    child: CustomButtonWidget(
                      onTap: () async {

                        if(_canFilter(transactionHistoryController.transactionModel)){

                          Get.back();

                          await transactionHistoryController.getTransactionData(
                            1,
                            transactionType: transactionHistoryController.transactionType[transactionHistoryController.transactionTypeIndex],
                            balanceType: transactionHistoryController.transactionTypeIndex == 0 ? transactionHistoryController.filterTransactionType : null,
                            startDate: transactionHistoryController.startDate,
                            endDate: transactionHistoryController.endDate,
                          );
                        }
                      },
                      buttonText: 'filter'.tr,
                      color: _canFilter(transactionHistoryController.transactionModel) ? Theme.of(context).colorScheme.onSecondary : Theme.of(context).disabledColor,
                      buttonTextStyle: rubikMedium,
                      borderRadius: Dimensions.radiusSizeExtraSmall,
                    ),
                  )),
                ]),
              ),
            ]),
          ));
        }
    );
  }
}


bool _canFilter(TransactionModel? transactionModel) {

  if(transactionModel == null) return false;

  final TransactionHistoryController transactionHistoryController = Get.find<TransactionHistoryController>();
  final transactionType = transactionHistoryController.transactionTypeIndex != 0 ? null : transactionHistoryController.filterTransactionType;

  return transactionHistoryController.endDate != transactionModel.endDate ||
      transactionHistoryController.startDate != transactionModel.startDate ||
      transactionType != transactionModel.balanceType;

}



bool _canResetFilters() {
  final transactionHistoryController = Get.find<TransactionHistoryController>();

  return transactionHistoryController.transactionModel?.balanceType != null ||
      transactionHistoryController.transactionModel?.startDate != null ||
      transactionHistoryController.transactionModel?.endDate != null;
}