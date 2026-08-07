import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/enums/dispute_time_type_enum.dart';
import 'package:amial_pay/common/models/config_model.dart';
import 'package:amial_pay/common/widgets/custom_button_widget.dart';
import 'package:amial_pay/common/widgets/custom_image_widget.dart';
import 'package:amial_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amial_pay/common/widgets/transaction_info_widget.dart';
import 'package:amial_pay/features/history/controllers/report_dispute_controller.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/history/domain/models/transaction_model.dart';
import 'package:amial_pay/common/widgets/report_history_item_widget.dart';
import 'package:amial_pay/features/history/widgets/dispute_submit_verify_dialog.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/helper/custom_extension_helper.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/helper/price_converter_helper.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:skeletonizer/skeletonizer.dart';

class TransactionDetailsBottomSheetWidget extends StatefulWidget {
  final Transactions? transactions;
  const TransactionDetailsBottomSheetWidget({super.key, this.transactions});

  @override
  State<TransactionDetailsBottomSheetWidget> createState() => _TransactionDetailsBottomSheetWidgetState();
}

class _TransactionDetailsBottomSheetWidgetState extends State<TransactionDetailsBottomSheetWidget> {
  final TextEditingController _commentTextController = TextEditingController();

  Transactions? transactions;

  String? userPhone;
  String? userName;
  String? userImage;


  @override
  void initState() {
    super.initState();

    transactions = widget.transactions;

    final TransactionHistoryController transactionHistoryController = Get.find<TransactionHistoryController>();
    transactionHistoryController.updateShowReportForm(false, isUpdate: false);
    Get.find<ReportDisputeController>().onUpdateReasonSelect(null, isRemoveAll: true, isUpdate: false);
    Get.find<ReportDisputeController>().onUpdateDisputeComment(null, isUpdate: false);

    _getInfo();

  }


  @override
  void dispose() {
    super.dispose();
    _commentTextController.dispose();
  }


  void _getInfo(){
    final TransactionAdminInfo? transactionAdminInfo = Get.find<TransactionHistoryController>().transactionModel?.transactionAdminInfo;


    try{

      switch (transactions?.transactionType) {
        case AppConstants.sendMoney:
          userPhone = transactions?.receiver!.phone;
          break;
        case AppConstants.receivedMoney:
        case AppConstants.addMoney:
        case 'add_money_bonus':
        case AppConstants.cashIn:
          userPhone = transactions?.sender!.phone;
          break;
        case AppConstants.withdraw:
        case AppConstants.cashOut:
          userPhone = transactions?.receiver!.phone;
          break;
        case AppConstants.deductDisputedMoney || AppConstants.addDisputedMoney:
          userPhone = transactionAdminInfo?.phone;
          break;

        default:
          userPhone = transactions?.userInfo?.phone;
      }


      switch (transactions?.transactionType) {
        case AppConstants.sendMoney:
          userName = transactions!.receiver!.name;
          break;
        case AppConstants.receivedMoney:
        case AppConstants.addMoney:
        case 'add_money_bonus':
        case AppConstants.cashIn:
          userName = transactions?.sender!.name;
          break;
        case AppConstants.withdraw:
        case AppConstants.cashOut:
          userName = transactions?.receiver!.name;
          break;
        case AppConstants.deductDisputedMoney || AppConstants.addDisputedMoney:
          userName = transactionAdminInfo?.name;
          break;

        default:
          userName = (transactions?.userInfo?.name ?? '');
      }


      switch (transactions?.transactionType) {
        case AppConstants.sendMoney:
          userImage = transactions?.receiver!.image;
          break;
        case AppConstants.receivedMoney:
        case AppConstants.addMoney:
        case 'add_money_bonus':
        case AppConstants.cashIn:
          userImage = transactions?.sender!.image;
          break;
        case AppConstants.withdraw:
        case AppConstants.cashOut:
          userImage = transactions?.receiver!.image;
          break;
        case AppConstants.deductDisputedMoney || AppConstants.addDisputedMoney:
          userImage = transactionAdminInfo?.image;
          break;
        default:
          userImage = transactions!.receiver!.image;
      }

    }catch(e){
      userName = 'no_user'.tr;
    }

  }

  @override
  Widget build(BuildContext context) {
    return GetBuilder<TransactionHistoryController>(
        builder: (transactionHistoryController) {
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault, vertical: Dimensions.paddingSizeLarge),
            child: Column(mainAxisSize: MainAxisSize.min, children: [

              !transactionHistoryController.showReportForm ?
              Row(children: [

                ClipRRect(
                  borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraLarge),
                  child: CustomImageWidget(
                    height: 50, width: 50,
                    fit: BoxFit.cover,
                    image: userImage ?? "",
                    placeholder: Images.menPlaceHolder,
                  ),
                ),
                const SizedBox(width: Dimensions.paddingSizeSmall),

                Expanded(child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [

                    Text(userName ?? '', style: rubikSemiBold.copyWith(
                      fontSize: Dimensions.fontSizeDefault,
                      color: Theme.of(context).primaryColor,
                    ), overflow: TextOverflow.ellipsis),
                    const SizedBox(height: Dimensions.paddingSizeSmall),

                    Text(
                      userPhone ?? '',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: rubikRegular.copyWith(
                        fontSize: Dimensions.fontSizeDefault,
                        color: Theme.of(context).primaryColor,
                      ),
                    ),
                  ],
                )),
                const SizedBox(width: Dimensions.paddingSizeDefault),

                InkWell(
                  splashColor: Colors.transparent,
                  highlightColor: Colors.transparent,
                  onTap: ()=> Navigator.pop(context),
                  child: Container(
                    decoration: BoxDecoration(
                      color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                      shape: BoxShape.circle,
                    ),
                    transform: Matrix4.translationValues(5, -15, 0),
                    padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                    child: Icon(Icons.clear, color: Theme.of(context).primaryColor, size: Dimensions.paddingSizeDefault),
                  ),
                ),
              ]) :
              Row(children: [

                Expanded(child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [

                    Text('tell_us_why_you_want_to_report'.tr, style: rubikMedium.copyWith(
                      fontSize: Dimensions.fontSizeLarge,
                      color: Theme.of(context).primaryColor,
                    ), overflow: TextOverflow.ellipsis),

                    Text(
                      'we_will_try_to_solve_as_soon_as_possible'.tr,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: rubikRegular.copyWith(
                        fontSize: Dimensions.fontSizeDefault,
                        color: Theme.of(context).textTheme.bodyLarge?.color,
                      ),
                    ),
                  ],
                )),

                InkWell(
                  splashColor: Colors.transparent,
                  highlightColor: Colors.transparent,
                  onTap: ()=> Navigator.pop(context),
                  child: Container(
                    decoration: BoxDecoration(
                      color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                      shape: BoxShape.circle,
                    ),
                    transform: Matrix4.translationValues(5, -15, 0),
                    padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                    child: Icon(Icons.clear, color: Theme.of(context).primaryColor, size: Dimensions.paddingSizeDefault),
                  ),
                ),
              ]),

              Padding(
                padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeDefault),
                child: Divider(thickness: 1, color: Theme.of(context).hintColor.withValues(alpha:0.1)),
              ),


              Flexible(child: SingleChildScrollView(child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  /// General Transaction Info
                  if(!transactionHistoryController.showReportForm) ...[

                    Flexible(child: SingleChildScrollView(child: Column(children: [

                      TransactionInfoWidget(label: transactions?.transactionType?.tr ?? "", info: PriceConverterHelper.convertPrice(double.parse(transactions!.amount.toString()))),
                      const SizedBox(height: Dimensions.paddingSizeDefault),

                      TransactionInfoWidget(
                        label: 'charge'.tr,
                        info: PriceConverterHelper.convertPrice(transactions?.charge ?? 0),
                      ),
                      const SizedBox(height: Dimensions.paddingSizeDefault),

                      TransactionInfoWidget(
                        label: 'date_and_time'.tr,
                        info: '${DateConverterHelper.estimatedDate(DateTime.parse(transactions!.createdAt!))} ${DateConverterHelper.isoStringToLocalTimeOnly(transactions!.createdAt!)}',
                      ),
                      const SizedBox(height: Dimensions.paddingSizeDefault),

                      TransactionInfoWidget(label: 'trx_id'.tr, info: '${transactions!.transactionId}', transactionId: transactions?.id),
                      const SizedBox(height: Dimensions.radiusSizeExtraLarge),


                      if(_canReport()) ...[
                        const SizedBox(height: Dimensions.radiusSizeExtraLarge),

                        SizedBox(
                          height: Dimensions.paddingSizeOverLarge,
                          child: CustomButtonWidget(
                            onTap: ()=> transactionHistoryController.updateShowReportForm(true),
                            buttonText: 'issue_report'.tr,
                            color: Theme.of(context).colorScheme.secondary,
                            buttonTextStyle: rubikSemiBold,
                            borderRadius: Dimensions.radiusSizeExtraSmall,
                          ),
                        ),
                        const SizedBox(height: Dimensions.paddingSizeLarge),
                      ],

                      /// Report History
                      _ReportHistoryWidget(dispute: widget.transactions?.dispute),
                    ]))),
                  ]
                  else
                    _ReportSubmitWidget(transactionId: transactions?.id, commentTextController: _commentTextController),
                ],
              ))),

              if(transactionHistoryController.showReportForm)
                GetBuilder<ReportDisputeController>(
                    builder: (reportDisputeController) {
                      return Container(
                        margin: EdgeInsets.only(top: Dimensions.paddingSizeDefault),
                        height: Dimensions.paddingSizeOverLarge,
                        child: CustomButtonWidget(
                          onTap: ((reportDisputeController.disputeComment ?? '').trim().isEmpty && reportDisputeController.selectedReasonIds.isEmpty) ? null :  () {

                            Navigator.pop(context);
                            showDialog(context: context, builder: (context)=> DisputeSubmitVerifyDialog(inputComment: reportDisputeController.disputeComment, transactionId: transactions?.id));
                          },
                          buttonText: 'submit'.tr,
                          color: Theme.of(context).colorScheme.secondary,
                          buttonTextStyle: rubikSemiBold,
                          borderRadius: Dimensions.radiusSizeExtraSmall,
                        ),
                      );
                    }
                ),
            ]),
          );
        }
    );
  }

  bool _canReport() {
    final ConfigModel? config = Get.find<SplashController>().configModel;

    final DisputeTimeType disputeTimeType = config?.reportDisputeTimeType ?? DisputeTimeType.day;
    final int disputeTimeValue = config?.reportDisputeTime ?? 0;

    final allowedDuration = switch(disputeTimeType) {
      DisputeTimeType.day => Duration(days: disputeTimeValue),
      DisputeTimeType.hour => Duration(hours: disputeTimeValue),
      DisputeTimeType.minute => Duration(minutes: disputeTimeValue),
    };

    final disputableDateTime = DateConverterHelper.isoStringToLocalDate(transactions!.createdAt!).add(allowedDuration);
    final bool isExpired = DateTime.now().isAfter(disputableDateTime);


    return (Get.find<SplashController>().configModel?.isReportDisputesActive ?? false)
        && transactions?.dispute == null
        && (transactions?.transactionType == AppConstants.sendMoney || transactions?.transactionType == AppConstants.cashOut) && !isExpired;
  }
}

class _ReportSubmitWidget extends StatelessWidget {
  final TextEditingController commentTextController;
  final String? transactionId;
  const _ReportSubmitWidget({required this.transactionId, required this.commentTextController});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ReportDisputeController>(
        builder: (reportDisputeController) {
          return  Skeletonizer(
            enabled: reportDisputeController.disputeReasonList == null,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              ListView.builder(
                physics: const NeverScrollableScrollPhysics(),
                itemCount: reportDisputeController.disputeReasonList?.length ?? 0,
                padding: EdgeInsets.zero,
                shrinkWrap: true,
                itemBuilder: (context, index){
                  final bool isSelected = reportDisputeController.selectedReasonIds.contains(reportDisputeController.disputeReasonList?[index].id);

                  return InkWell(
                    splashColor: Colors.transparent,
                    highlightColor: Colors.transparent,
                    onTap: ()=> reportDisputeController.onUpdateReasonSelect(reportDisputeController.disputeReasonList?[index].id),
                    child: Row(children: [
                      Expanded(child: Text(reportDisputeController.disputeReasonList?[index].reason ?? '', style: isSelected
                          ? rubikMedium
                          : rubikRegular.copyWith(color: Theme.of(context).hintColor),
                      )),

                      Checkbox(
                        checkColor: Theme.of(context).cardColor,
                        fillColor: WidgetStateProperty.resolveWith((states) {
                          if (states.contains(WidgetState.selected)) {
                            return Theme.of(context).primaryColor;
                          }
                          return Theme.of(context).hintColor.withValues(alpha: 0.04); // Unchecked fill color
                        }),
                        side: WidgetStateBorderSide.resolveWith((states) {
                          if(states.contains(WidgetState.pressed)){
                            return BorderSide(color: Theme.of(context).primaryColor);
                          }
                          else{
                            return BorderSide(color: Theme.of(context).hintColor.withValues(alpha: 0.2));
                          }
                        }),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Dimensions.paddingSizeExtraSmall)),
                        value: isSelected,
                        onChanged: (bool? value) {
                          reportDisputeController.onUpdateReasonSelect(reportDisputeController.disputeReasonList?[index].id);
                        },
                        visualDensity: const VisualDensity(horizontal: -4, vertical: -3),
                      ),
                    ]),
                  );
                },
              ),
              const SizedBox(height: Dimensions.paddingSizeDefault),

              Text('comments'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).primaryColor)),
              const SizedBox(height: Dimensions.paddingSizeSmall),

              CustomTextFieldWidget(
                hintText: 'type_here_your_report_reason'.tr,
                inputType: TextInputType.text,
                borderRadius: Dimensions.paddingSizeSmall,
                maxLines: 3,
                maxLength: 255,
                isMaxLimitVisible: true,
                isShowBorder: true,
                contentPadding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault, vertical: Dimensions.paddingSizeSmall),
                controller: commentTextController,
                onChanged: (value) {
                  reportDisputeController.onUpdateDisputeComment(value);
                },
              ),
              const SizedBox(height: Dimensions.radiusSizeExtraLarge),

            ]),
          );
        }
    );
  }
}

class _ReportHistoryWidget extends StatelessWidget {
  final Dispute? dispute;
  const _ReportHistoryWidget({required this.dispute});

  @override
  Widget build(BuildContext context) {
    return dispute == null ? SizedBox() : Column(mainAxisSize: MainAxisSize.min, children: [
      Padding(
        padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
        child: Divider(thickness: 1, color: Theme.of(context).hintColor.withValues(alpha:0.1)),
      ),

      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,children: [

        Text('report_history'.tr, style: rubikMedium.copyWith(color: Theme.of(context).hintColor)),

        Container(
          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: Dimensions.paddingSizeExtraSmall),
          decoration: BoxDecoration(
            color: _getStatusColor(context),
            borderRadius: BorderRadius.circular(Dimensions.paddingSizeExtraSmall),
          ),
          child: Text(dispute?.status?.value.tr ?? '', style: rubikRegular.copyWith(color: Theme.of(context).primaryColor)),
        ),

      ]),
      const SizedBox(height: Dimensions.paddingSizeDefault),

      if(dispute?.status == DisputeStatus.disputed) ...[
        ReportHistoryItemWidget(
          label: 'admin_has_dispute_your_report'.tr,
          info: '${'your_have_refunded'.tr} ${PriceConverterHelper.convertPrice(dispute?.amount ?? 0)}',
          backgroundColor: context.customThemeColors.success.withValues(alpha: 0.1),
        ),
        const SizedBox(height: Dimensions.paddingSizeDefault),
      ],

      if(dispute?.status == DisputeStatus.denied) ...[
        ReportHistoryItemWidget(
          label: 'admin_has_denied_your_report'.tr,
          info: '${'note'.tr} : ${dispute?.deniedNote ?? ''}',
          backgroundColor: Theme.of(context).colorScheme.onSecondaryContainer.withValues(alpha: 0.15),
        ),
        const SizedBox(height: Dimensions.paddingSizeDefault),
      ],

      if(dispute?.reportReason?.isNotEmpty ?? false)...[
        ReportHistoryItemWidget(label: 'report_reason'.tr, infoWidget: ListView.builder(
          shrinkWrap: true,
          itemCount: dispute?.reportReason?.length ?? 0,
          physics: NeverScrollableScrollPhysics(),
          itemBuilder: (ctx, i)=> Padding(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraSmall),
            child: Row(
              spacing: Dimensions.paddingSizeExtraSmall,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('*'),

                Flexible(child: Text(
                  dispute?.reportReason?[i] ?? '',
                  style: rubikRegular.copyWith(
                    fontSize: Dimensions.fontSizeDefault,
                    color: Theme.of(context).hintColor,
                  ),
                )),
              ],
            ),
          ),

        )),
        const SizedBox(height: Dimensions.paddingSizeDefault),
      ],

      if(dispute?.comment?.isNotEmpty ?? false) ...[
        ReportHistoryItemWidget(label: 'comment'.tr, info: dispute?.comment),
        const SizedBox(height: Dimensions.paddingSizeDefault)
      ],

    ]);

  }

  Color _getStatusColor(BuildContext context) {
    return switch(dispute?.status ?? DisputeStatus.pending) {

      DisputeStatus.pending => context.customThemeColors.info.withValues(alpha: 0.15),

      DisputeStatus.approved => context.customThemeColors.success.withValues(alpha: 0.1),

      DisputeStatus.denied => context.colorScheme.error.withValues(alpha: 0.15),

      DisputeStatus.disputed => context.customThemeColors.info.withValues(alpha: 0.15),
    };
  }
}

