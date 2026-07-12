
import 'package:amyal_pay/common/models/config_model.dart';
import 'package:amyal_pay/common/widgets/custom_pop_scope_widget.dart';
import 'package:amyal_pay/features/transaction_money/controllers/bootom_slider_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/common/controllers/share_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/features/transaction_money/domain/enums/suggest_type_enum.dart';
import 'package:amyal_pay/helper/custom_extension_helper.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:lottie/lottie.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:slide_to_confirm/slide_to_confirm.dart';

import 'profile_avatar_widget.dart';

class BottomSheetWithSlider extends StatefulWidget {
  final String amount;
  final String? pinCode;
  final String? transactionType;
  final String? purpose;
  final ContactModel? contactModel;
  const BottomSheetWithSlider({super.key, required this.amount, this.pinCode, this.transactionType, this.purpose, this.contactModel});

  @override
  State<BottomSheetWithSlider> createState() => _BottomSheetWithSliderState();
}

class _BottomSheetWithSliderState extends State<BottomSheetWithSlider> {
  String? transactionId ;

  @override
  void initState() {
    Get.find<TransactionMoneyController>().changeTrueFalse();
    super.initState();
  }


  @override
  Widget build(BuildContext context) {

    final ConfigModel configModel = Get.find<SplashController>().configModel!;
    final charges = _getCharges(configModel, widget.contactModel?.isFavourite ?? false);
    final double cashOutCharge = charges.cashOut;
    final double sendMoneyCharge = charges.sendMoney;



    String customerImage = '${configModel.baseUrls!.customerImageUrl}/${widget.contactModel!.avatarImage}';
    String agentImage = '${configModel.baseUrls!.agentImageUrl}/${widget.contactModel!.avatarImage}';

    final ContactController contactController = Get.find<ContactController>();

    return CustomPopScopeWidget(
      isExit: false,
      onPopInvoked: ()=> Get.offAllNamed(RouteHelper.getNavBarRoute()),
      child: Container(
        decoration: BoxDecoration(
          color: Theme.of(context).scaffoldBackgroundColor.withValues(alpha: 0.1),
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(Dimensions.radiusSizeLarge),
            topRight:Radius.circular(Dimensions.radiusSizeLarge),
          ),
        ),

        child: GetBuilder<TransactionMoneyController>(
          builder: (transactionMoneyController) {
            return Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Stack(
                  children: [
                    Container(
                      width: double.infinity,
                      alignment: Alignment.center,
                      padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeLarge),
                      decoration: BoxDecoration(
                        color: Theme.of(context).hoverColor,
                      borderRadius: const BorderRadius.vertical(top: Radius.circular(Dimensions.radiusSizeLarge) ),
                      ),
                      child: Text('${'confirm_to'.tr} ${widget.transactionType?.tr}', style: rubikSemiBold.copyWith(),),
                    ),
                    !transactionMoneyController.isLoading?
                    Visibility(
                      visible: !transactionMoneyController.isNextBottomSheet,
                      child: Positioned(top: Dimensions.paddingSizeSmall, right: 8.0, child: GestureDetector(
                        onTap: ()=> Get.offAllNamed(RouteHelper.getNavBarRoute()),
                        child: Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: Theme.of(context).shadowColor.withValues(alpha: 0.6),
                          ),
                          child: const Icon(Icons.clear,size: Dimensions.paddingSizeDefault),
                        ),
                      )),
                    ) : const SizedBox(),
                  ],
                ),

                transactionMoneyController.isNextBottomSheet?
                Column(
                  children: [
                    transactionMoneyController.isNextBottomSheet ? Lottie.asset(
                      Images.successAnimation,
                      width: 120.0,
                      fit: BoxFit.contain,
                      alignment: Alignment.center,
                    ) : Padding(
                      padding: const EdgeInsets.all(Dimensions.paddingSizeSmall), child: Lottie.asset(
                      Images.failedAnimation,
                      width: 80.0,
                      fit: BoxFit.contain,
                      alignment: Alignment.center,
                    ),
                    ),
                  ],
                ): ProfileAvatarWidget(image: widget.transactionType != TransactionType.cashOut ? customerImage : agentImage),
                Container(
                  color: Theme.of(context).scaffoldBackgroundColor.withValues(alpha: 0.1),
                  child: Column(
                    children: [
                      !transactionMoneyController.isNextBottomSheet && widget.transactionType != TransactionType.requestMoney?
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          Text('charge'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeDefault)),
                          const SizedBox(width: Dimensions.paddingSizeExtraSmall),

                          Text(
                            widget.transactionType == TransactionType.sendMoney
                                ? PriceConverterHelper.balanceWithSymbol(balance: sendMoneyCharge.toString())
                                : PriceConverterHelper.balanceWithSymbol(balance: cashOutCharge.toStringAsFixed(2)),
                            style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge),
                          ),
                        ],
                      ):const SizedBox(),


                      if(transactionMoneyController.isNextBottomSheet) Text(widget.transactionType == TransactionType.sendMoney
                          ? 'send_money_successful'.tr
                          : widget.transactionType == TransactionType.requestMoney
                          ? 'request_send_successful'.tr : 'cash_out_successful'.tr,
                        style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).textTheme.titleLarge!.color),
                      ),
                      const SizedBox(height: Dimensions.paddingSizeExtraExtraLarge),

                      Text(PriceConverterHelper.balanceWithSymbol(balance: widget.amount), style: rubikMedium.copyWith(fontSize: 34.0)),
                      const SizedBox(height: Dimensions.paddingSizeLarge),

                      // AMIAL-DESIGN-33: الرسوم + الإجمالي في إيصال النجاح
                      if (transactionMoneyController.isNextBottomSheet &&
                          widget.transactionType != TransactionType.requestMoney)
                        Padding(
                          padding: const EdgeInsets.symmetric(
                              horizontal: Dimensions.paddingSizeLarge),
                          child: Column(children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Text(
                                  PriceConverterHelper.balanceWithSymbol(
                                      balance: widget.transactionType == TransactionType.sendMoney
                                          ? sendMoneyCharge.toString()
                                          : cashOutCharge.toStringAsFixed(2)),
                                  style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault),
                                ),
                                Text('رسوم التحويل',
                                    style: rubikRegular.copyWith(
                                        fontSize: Dimensions.fontSizeDefault)),
                              ],
                            ),
                            const SizedBox(height: 6),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Text(
                                  PriceConverterHelper.balanceWithSymbol(
                                      balance: (double.parse(widget.amount) +
                                              (widget.transactionType == TransactionType.sendMoney
                                                  ? double.parse(sendMoneyCharge.toString())
                                                  : cashOutCharge))
                                          .toStringAsFixed(0)),
                                  style: rubikSemiBold.copyWith(
                                      fontSize: Dimensions.fontSizeLarge),
                                ),
                                Text('المبلغ الإجمالي',
                                    style: rubikSemiBold.copyWith(
                                        fontSize: Dimensions.fontSizeDefault)),
                              ],
                            ),
                            const SizedBox(height: Dimensions.paddingSizeSmall),
                          ]),
                        ),

                      if(!transactionMoneyController.isNextBottomSheet && widget.transactionType != TransactionType.requestMoney)
                        GetBuilder<ProfileController>(builder: (profileController) {
                          return profileController.isLoading ? const SizedBox() : Text(
                            '${'new_balance'.tr} ${
                                  transactionMoneyController.isNextBottomSheet ?
                                  PriceConverterHelper.balanceWithSymbol(balance: '${profileController.userInfo?.balance}') :
                                  widget.transactionType == TransactionType.requestMoney
                                ? PriceConverterHelper.newBalanceWithCredit(inputBalance: double.parse(widget.amount))
                                : PriceConverterHelper.newBalanceWithDebit(inputBalance: double.parse(widget.amount),

                                charge: widget.transactionType == TransactionType.sendMoney
                                    ? double.parse(sendMoneyCharge.toString())
                                    : cashOutCharge)
                            }',

                            style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault),
                          );
                      }),

                      const SizedBox(height: Dimensions.paddingSizeDefault),

                      const Padding(
                        padding: EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
                        child: Divider(height: Dimensions.dividerSizeSmall),
                      ),

                      Padding(
                        padding: const EdgeInsets.symmetric(
                          vertical: Dimensions.paddingSizeDefault,
                          horizontal: Dimensions.paddingSizeDefault,
                        ),
                        child: Column(
                          children: [
                            Text(
                              widget.transactionType != TransactionType.cashOut ?  widget.purpose! : 'cash_out'.tr,
                              style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault),
                            ),

                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Text(
                                  widget.contactModel!.name == null?'(unknown )' :'(${widget.contactModel!.name}) ',
                                  style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault),
                                  maxLines: 1,overflow: TextOverflow.ellipsis,
                                ),

                                Text(
                                  widget.contactModel!.phoneNumber == null
                                      ? '' : '${widget.contactModel!.phoneNumber}',
                                  style: rubikSemiBold.copyWith(fontSize: Dimensions.fontSizeDefault),
                                ),
                              ],
                            ),

                            const SizedBox(height: Dimensions.paddingSizeExtraSmall),

                            transactionMoneyController.isNextBottomSheet ?
                            transactionId != null? Text(
                              'TrxID: $transactionId',
                              style: rubikLight.copyWith(fontSize: Dimensions.fontSizeDefault),
                            ): const SizedBox() : const SizedBox(),

                          ],
                        ),
                      ),

                    ],
                  ),
                ),


                transactionMoneyController.isNextBottomSheet?
                Column(
                  children: [
                    const Padding(
                      padding: EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault / 1.7),
                      child: Divider(height: Dimensions.dividerSizeSmall),
                    ),
                    const SizedBox(height: Dimensions.paddingSizeDefault),

                    CustomInkWellWidget(
                      onTap:() async => await  Get.find<ShareController>().statementScreenShootFunction(
                        amount: widget.amount,
                        transactionType: widget.transactionType,
                        contactModel: widget.contactModel,
                        charge: widget.transactionType == TransactionType.sendMoney
                            ? sendMoneyCharge.toString()
                            :  cashOutCharge.toString(),trxId: transactionId,
                      ),
                      child: Text('share_statement'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge)),
                    ),

                    const SizedBox(height: Dimensions.paddingSizeDefault),
                  ],
                ) : const SizedBox(),



                transactionMoneyController.isNextBottomSheet ?
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraExtraLarge),
                  child: Container(
                    decoration: BoxDecoration(
                      color: Theme.of(context).colorScheme.secondary,
                      borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                    ),
                    child: CustomInkWellWidget(
                      onTap: (){
                        Get.find<BottomSliderController>().goBackButton();
                      },
                      radius: Dimensions.radiusSizeSmall,
                      highlightColor: Theme.of(context).textTheme.titleLarge!.color!.withValues(alpha:0.1),
                      child: SizedBox(
                        height: 50.0,
                        child: Center(child: Text(
                          'back_to_home'.tr,
                          style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge),
                        )),

                      ),
                    ),
                  ),
                ):
                transactionMoneyController.isLoading
                    ? Center(child: CircularProgressIndicator(color: Theme.of(context).textTheme.titleLarge!.color,))
                    : ConfirmationSlider(
                        height: 60.0,
                        backgroundColor: context.customThemeColors.sliderColor,

                  text: 'swipe_to_confirm'.tr,
                        textStyle: rubikRegular.copyWith(fontSize: Dimensions.paddingSizeLarge),
                        shadow: const BoxShadow(),
                        sliderButtonContent: Container(
                          padding: const EdgeInsets.all(Dimensions.paddingSizeDefault),
                          decoration: BoxDecoration(
                            color: Theme.of(context).colorScheme.secondary,
                            shape: BoxShape.circle,
                          ),
                          child: Image.asset(Images.slideRightIcon),

                        ),
                        onConfirmation: ()async{
                          if( widget.transactionType == TransactionType.sendMoney){
                            transactionMoneyController.sendMoney(
                              contactModel: widget.contactModel!,
                              amount: double.parse(widget.amount),
                              purpose: widget.purpose,
                              pinCode: widget.pinCode,
                              onSuggest: ()=> contactController.addToSuggestContact(
                                widget.contactModel, type: SuggestType.sendMoney,
                              )
                            ).then((value) {
                              transactionId = value.body['transaction_id'];
                            });
                          }else if(widget.transactionType == TransactionType.requestMoney){
                            transactionMoneyController.requestMoney(
                              contactModel: widget.contactModel!,
                              amount: double.parse(widget.amount),
                              onSuggest: ()=> contactController.addToSuggestContact(
                                widget.contactModel, type: SuggestType.requestMoney,
                              ),
                            );
                          }else if(widget.transactionType == TransactionType.cashOut){
                            transactionMoneyController.cashOutMoney(
                              contactModel: widget.contactModel!,
                              amount: double.parse(widget.amount),
                              pinCode: widget.pinCode,
                              onSuggest: (){
                                if(contactController.isFutureSave) {
                                  contactController.addToSuggestContact(widget.contactModel, type: SuggestType.cashOut);
                                }
                              }
                            ).then((value) {
                              transactionId = value.body['transaction_id'];
                            });
                          }


                       },

                ),
                const SizedBox(height: 40.0),

              ],
            );
          }
        ),
      ),
    );
  }

  ({double cashOut, double sendMoney}) _getCharges(ConfigModel configModel, bool isFavContact) {
    final cashOutCharge = PriceConverterHelper.convertCharge(amount: double.parse(widget.amount), charge: configModel.cashOutChargePercent);

    if(isFavContact) {
      return (
      cashOut: cashOutCharge -  PriceConverterHelper.convertCharge(amount: cashOutCharge, charge: configModel.favCashOutChargeDiscount),
      sendMoney: configModel.sendMoneyChargeFlat! -  PriceConverterHelper.convertCharge(amount: configModel.sendMoneyChargeFlat, charge: configModel.favSendMoneyChargeDiscount),
      );
    }else {
      return (cashOut: cashOutCharge, sendMoney: configModel.sendMoneyChargeFlat ?? 0);
    }

  }
}