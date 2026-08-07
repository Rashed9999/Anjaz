import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/models/config_model.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amial_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amial_pay/features/transaction_money/domain/models/purpose_model.dart';
import 'package:amial_pay/common/models/contact_model.dart';
import 'package:amial_pay/features/transaction_money/domain/models/withdraw_model.dart';
import 'package:amial_pay/features/transaction_money/widgets/transaction_charge_widget.dart';
import 'package:amial_pay/helper/email_checker_helper.dart';
import 'package:amial_pay/helper/price_converter_helper.dart';
import 'package:amial_pay/helper/transaction_type.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/common/widgets/custom_loader_widget.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/features/transaction_money/screens/transaction_confirmation_screen.dart';
import 'package:amial_pay/features/transaction_money/widgets/input_box_widget.dart';
import 'package:amial_pay/features/transaction_money/widgets/purpose_widget.dart';
import '../widgets/field_item_widget.dart';
import '../widgets/for_person_widget.dart';
import '../widgets/next_button_widget.dart';

class TransactionBalanceInputScreen extends StatefulWidget {
  final String? transactionType;
  final ContactModel? contactModel;
  final String? countryCode;
   const TransactionBalanceInputScreen({super.key, this.transactionType ,this.contactModel, this.countryCode});
  @override
  State<TransactionBalanceInputScreen> createState() => _TransactionBalanceInputScreenState();
}

class _TransactionBalanceInputScreenState extends State<TransactionBalanceInputScreen> {
  final TextEditingController _inputAmountController = TextEditingController();
  String? _selectedMethodId;
  List<MethodField>? _fieldList;
  List<MethodField>? _gridFieldList;
  Map<String?, TextEditingController> _textControllers =  {};
  Map<String?, TextEditingController> _gridTextController =  {};
  final FocusNode _inputAmountFocusNode = FocusNode();

  void setFocus() {
    _inputAmountFocusNode.requestFocus();
    Get.back();
  }

  @override
  void initState() {
    super.initState();

    Get.find<SplashController>().getConfigData();

    // AMIAL-FIX(GRAY): حمّل الملف الشخصي إن لم يكن مُحمَّلاً (الدخول الموحّد)
    // ليظهر الرصيد المتاح ويُفحص الرصيد قبل الإرسال.
    try {
      if (Get.find<ProfileController>().userInfo == null) {
        Get.find<ProfileController>().getProfileData(reload: false);
      }
    } catch (_) {}

    if(widget.transactionType == TransactionType.withdrawRequest) {
      Get.find<TransactionMoneyController>().getWithdrawMethods();
    }
    _inputAmountController.addListener(() {
      if (!mounted) return; // AMIAL-FIX-006
      setState(() {
      });
    });

  }

  @override
  void dispose() {
    super.dispose();

    _inputAmountController.dispose();
    _inputAmountFocusNode.dispose();

  }
 
  @override
  Widget build(BuildContext context) {
    final ProfileController profileController = Get.find<ProfileController>();
    final SplashController splashController = Get.find<SplashController>();
    return GestureDetector(
      onTap: () => FocusScope.of(context).unfocus(),
      child: Scaffold(
          appBar: AppBar(title: Text(widget.transactionType!.tr), backgroundColor: AmialColors.primary, foregroundColor: Colors.white, elevation: 0),

          body: GetBuilder<TransactionMoneyController>(
              builder: (transactionMoneyController) {
                if(widget.transactionType == TransactionType.withdrawRequest &&
                    transactionMoneyController.withdrawModel == null) {
                  return CustomLoaderWidget(color: Theme.of(context).primaryColor);
                }
                return SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if(widget.transactionType != TransactionType.addMoney &&
                          widget.transactionType != TransactionType.withdrawRequest)...[
                        ForPersonWidget(transactionType: widget.transactionType, contactModel: widget.contactModel),
                        Divider(color: Theme.of(context).dividerColor, height: Dimensions.dividerSizeMedium),
                      ],


                      if(widget.transactionType == TransactionType.withdrawRequest)
                        Padding(
                          padding: const EdgeInsets.symmetric(
                            vertical: Dimensions.paddingSizeDefault,
                            horizontal: Dimensions.paddingSizeSmall,
                          ),
                          child: Column(children: [
                            Container(
                              height: 50,
                              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
                              decoration: BoxDecoration(
                                color: Theme.of(context).cardColor,
                                borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                                border: Border.all(color: Theme.of(context).primaryColor.withValues(alpha:0.4)),
                              ),

                              child: DropdownButton<String>(
                                menuMaxHeight: Get.height * 0.5,
                                hint: Text(
                                  'please_select_a_method'.tr,
                                  style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall),
                                ),
                                value: _selectedMethodId,
                                items: transactionMoneyController.withdrawModel!.withdrawalMethods.map((withdraw) =>
                                    DropdownMenuItem<String>(
                                      value: withdraw.id.toString(),
                                      child: Text(
                                        withdraw.methodName ?? 'no method',
                                        style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall),
                                      ),
                                    )
                                ).toList(),

                                onChanged: (id) {
                                  _selectedMethodId = id;
                                  _gridFieldList = [];
                                  _fieldList = [];

                                  transactionMoneyController.withdrawModel!.withdrawalMethods.firstWhere((method) =>
                                  method.id.toString() == id).methodFields.forEach((method) {
                                    _gridFieldList!.addIf(method.inputName!.contains('cvv') || method.inputType == 'date', method);
                                  });


                                  transactionMoneyController.withdrawModel!.withdrawalMethods.firstWhere((method) =>
                                  method.id.toString() == id).methodFields.forEach((method) {
                                    _fieldList!.addIf(!method.inputName!.contains('cvv') && method.inputType != 'date', method);
                                  });

                                  _textControllers = _textControllers =  {};
                                  _gridTextController = _gridTextController =  {};

                                  for (var method in _fieldList!) {
                                    _textControllers[method.inputName] = TextEditingController();
                                  }
                                  for (var method in _gridFieldList!) {
                                    _gridTextController[method.inputName] = TextEditingController();
                                  }

                                  transactionMoneyController.update();
                                },

                                isExpanded: true,
                                underline: const SizedBox(),
                              ),
                            ),

                            const SizedBox(height: Dimensions.paddingSizeDefault),

                            if(_fieldList != null && _fieldList!.isNotEmpty) ListView.builder(
                              shrinkWrap: true,
                              physics: const NeverScrollableScrollPhysics(),
                              itemCount: _fieldList!.length,
                              padding: const EdgeInsets.symmetric(
                                vertical: Dimensions.paddingSizeExtraSmall, horizontal: 10,
                              ),

                              itemBuilder: (context, index) => FieldItemWidget(
                                methodField:_fieldList![index],
                                textControllers: _textControllers,
                              ),
                            ),

                            if(_gridFieldList != null && _gridFieldList!.isNotEmpty)

                              GridView.builder(
                                padding: const EdgeInsets.symmetric(
                                  vertical: Dimensions.paddingSizeExtraSmall, horizontal: 10,
                                ),
                                shrinkWrap: true,
                                physics: const NeverScrollableScrollPhysics(),
                                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                                  crossAxisCount: 2,
                                  childAspectRatio: 2,
                                  crossAxisSpacing: 20,
                                  mainAxisSpacing: 20,
                                ),
                                itemCount: _gridFieldList!.length,

                                itemBuilder: (context, index) => FieldItemWidget(
                                  methodField: _gridFieldList![index],
                                  textControllers: _gridTextController,
                                ),
                              ),

                          ],),
                        ),

                      InputBoxWidget(
                        inputAmountController: _inputAmountController,
                        focusNode: _inputAmountFocusNode,
                        transactionType: widget.transactionType,
                      ),


                      TransactionChargeWidget(
                        isFavorite: widget.contactModel?.isFavourite ?? false,
                        transactionType: widget.transactionType,
                        inputAmountString: _inputAmountController.text,
                      ),


                      if(widget.transactionType == TransactionType.cashOut)
                        Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: Dimensions.paddingSizeLarge,
                            vertical:Dimensions.paddingSizeDefault,
                          ),
                          child: Row( children: [
                            Text(
                              'save_future_cash_out'.tr,
                              style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge),
                            ),
                            const Spacer(),

                            GetBuilder<ContactController>(
                              builder: (contactController) {
                                return Padding(
                                  padding: EdgeInsets.zero,
                                  child: CupertinoSwitch(
                                    value: contactController.isFutureSave,
                                    onChanged: contactController.onSaveChange,
                                  ),
                                );
                              }
                            ),

                          ],),
                        ),


                      widget.transactionType == TransactionType.sendMoney
                          ? transactionMoneyController.purposeList == null
                          ? CustomLoaderWidget(color: Theme.of(context).primaryColor)
                          :  transactionMoneyController.purposeList!.isEmpty
                          ? const SizedBox()
                          : MediaQuery.of(context).viewInsets.bottom > 10
                          ?
                      Container(
                        color: Theme.of(context).cardColor.withValues(alpha:0.92),
                        padding: const EdgeInsets.symmetric(
                          horizontal: Dimensions.paddingSizeLarge,
                          vertical: Dimensions.paddingSizeSmall,
                        ),
                        child: Row(children: [
                          transactionMoneyController.purposeList!.isEmpty ?
                          Center(child: CircularProgressIndicator(
                            color: Theme.of(context).textTheme.titleLarge!.color,
                          )) : const SizedBox(),

                          const SizedBox(width: Dimensions.paddingSizeSmall),

                          Text('change_purpose'.tr, style: rubikRegular.copyWith(
                            fontSize: Dimensions.fontSizeLarge,
                          ))
                        ],),

                      ): const PurposeWidget() : const SizedBox(),

                    ],
                  ),
                );
              }
          ),

          floatingActionButton: GetBuilder<TransactionMoneyController>(
              builder: (transactionMoneyController) {
                return  FloatingActionButton(

                  onPressed:() {
                    double amount;
                    if(_inputAmountController.text.isEmpty){
                      showCustomSnackBarHelper('please_input_amount'.tr,isError: true);
                    }else{
                      amount = PriceConverterHelper.getAmountFromInputFormatter(_inputAmountController.text);
                      if(amount <= 0) {
                        showCustomSnackBarHelper('transaction_amount_must_be'.tr,isError: true);
                      }else {
                        bool inSufficientBalance = false;


                        final bool isCheck = widget.transactionType != TransactionType.requestMoney
                            && widget.transactionType != TransactionType.addMoney;

                        if(isCheck && widget.transactionType == TransactionType.sendMoney) {
                          final double transactionCharge = _getSendMoneyCharge(amount);

                          inSufficientBalance = PriceConverterHelper.balanceWithCharge(amount: amount, charge: transactionCharge) > (profileController.userInfo?.balance ?? double.maxFinite);

                        }else if(isCheck && widget.transactionType == TransactionType.cashOut) {
                          final double cashOutCharge = _getCashOutCharge(amount);

                          inSufficientBalance = PriceConverterHelper.balanceWithCharge(amount: amount, charge: cashOutCharge) > (profileController.userInfo?.balance ?? double.maxFinite);

                        }else if(isCheck && widget.transactionType == TransactionType.withdrawRequest) {
                          inSufficientBalance = PriceConverterHelper.balanceWithCharge(amount: amount, charge: PriceConverterHelper.convertCharge(amount: amount, charge: splashController.configModel!.withdrawChargePercent)) > (profileController.userInfo?.balance ?? double.maxFinite);

                        }else if(isCheck){
                          inSufficientBalance = amount > (profileController.userInfo?.balance ?? double.maxFinite);
                        }


                        if(inSufficientBalance) {
                          showCustomSnackBarHelper('insufficient_balance'.tr, isError: true);
                        } else {
                         _confirmationRoute(amount);
                        }
                      }

                    }
                  },
                  backgroundColor: Theme.of(context).colorScheme.secondary,
                  child: transactionMoneyController.isLoading
                      ? const CircularProgressIndicator()
                      : const NextButtonWidget(isSubmittable: true),
                );
              }
          )

      ),
    );
  }



  double _getCashOutCharge(double amount) {
    final ConfigModel config = Get.find<SplashController>().configModel!;
    final isFavApplicable = (widget.contactModel?.isFavourite ?? false) &&
        (config.favCashOutChargeDiscount != 0);

    double charge = PriceConverterHelper.convertCharge(
      amount: amount,
      charge: config.cashOutChargePercent,
    );

    if (isFavApplicable) {
      final discount = PriceConverterHelper.convertCharge(
        amount: charge,
        charge: config.favCashOutChargeDiscount,
      );
      charge -= discount;
    }

    return charge;
  }

  double _getSendMoneyCharge( double amount) {
    final ConfigModel config = Get.find<SplashController>().configModel!;

    final isFavApplicable = (widget.contactModel?.isFavourite ?? false) &&
        (config.favSendMoneyChargeDiscount != 0);

    double charge = PriceConverterHelper.convertCharge(
      amount: amount,
      charge: config.sendMoneyChargeFlat,
      isPercent: false,
    );

    if (isFavApplicable) {
      final discount = PriceConverterHelper.convertCharge(
        amount: charge,
        charge: config.favSendMoneyChargeDiscount,
      );
      charge -= discount;
    }

    return charge;
  }


  void _confirmationRoute(double amount) {
    final transactionMoneyController = Get.find<TransactionMoneyController>();
    if(widget.transactionType == TransactionType.withdrawRequest) {
      String? message;
      WithdrawalMethod? withdrawMethod = transactionMoneyController.withdrawModel!.withdrawalMethods.
      firstWhereOrNull((method) => _selectedMethodId == method.id.toString());


      List<MethodField> list = [];
      String? validationKey;

      if(withdrawMethod != null) {
        for (var method in withdrawMethod.methodFields) {
          if(method.inputType == 'email') {
            validationKey  = method.inputName;
          }
          if(method.inputType == 'date') {
            validationKey  = method.inputName;
          }

        }
      }else{
        message = 'please_select_a_method'.tr;
      }


      _textControllers.forEach((key, textController) {
        list.add(MethodField(
          inputName: key, inputType: null,
          inputValue: textController.text,
          placeHolder: null,
        ));

        if((validationKey == key) && EmailCheckerHelper.isNotValid(textController.text)) {
          message = 'please_provide_valid_email'.tr;
        }else if((validationKey == key) && textController.text.contains('-')) {
          message = 'please_provide_valid_date'.tr;
        }

        if(textController.text.isEmpty && message == null) {
          message = 'please fill ${key!.replaceAll('_', ' ')} field';
        }
      });

      _gridTextController.forEach((key, textController) {
        list.add(MethodField(
          inputName: key, inputType: null,
          inputValue: textController.text,
          placeHolder: null,
        ));

        if((validationKey == key) && textController.text.contains('-')) {
          message = 'please_provide_valid_date'.tr;
        }
      });

      if(message != null) {
        showCustomSnackBarHelper(message);
        message = null;

      }
      else{


        Get.to(() => TransactionConfirmationScreen(
          inputBalance: amount,
          transactionType: TransactionType.withdrawRequest,
          contactModel: null,
          withdrawMethod: WithdrawalMethod(
            methodFields: list,
            methodName: withdrawMethod!.methodName,
            id: withdrawMethod.id,
          ),
          callBack: setFocus,
        ));
      }

    }

    else{
      Get.to(()=> TransactionConfirmationScreen(
        inputBalance: amount,
        transactionType:widget.transactionType,
        purpose:  widget.transactionType == TransactionType.sendMoney ?
        transactionMoneyController.purposeList != null && transactionMoneyController.purposeList!.isNotEmpty
            ? transactionMoneyController.purposeList![transactionMoneyController.selectedItem].title
            : PurposeModel().title
            : TransactionType.requestMoney.tr,

        contactModel: widget.contactModel,
        callBack: setFocus,

      ));
    }


  }
}





