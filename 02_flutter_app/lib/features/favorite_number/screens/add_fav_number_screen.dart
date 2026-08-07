import 'package:country_code_picker/country_code_picker.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/common/widgets/custom_button_widget.dart';
import 'package:amial_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amial_pay/features/camera_verification/controllers/qr_code_scanner_controller.dart';
import 'package:amial_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amial_pay/features/favorite_number/domain/enums/fav_type.dart';
import 'package:amial_pay/features/favorite_number/domain/models/favorite_list_model.dart';
import 'package:amial_pay/features/favorite_number/screens/search_contact_screen.dart';
import 'package:amial_pay/features/favorite_number/widgets/fav_type_item_widget.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amial_pay/features/transaction_money/domain/models/contact_tag_model.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/helper/phone_cheker_helper.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class AddFavNumberScreen extends StatefulWidget {
  final FavouriteModel? favNumber;
  const AddFavNumberScreen({super.key, this.favNumber});

  @override
  State<AddFavNumberScreen> createState() => _AddFavNumberScreenState();
}

class _AddFavNumberScreenState extends State<AddFavNumberScreen> {

  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();


  UserShortDataModel? userData;
  TextEditingController phoneNumberTextController = TextEditingController();
  TextEditingController nameTextController = TextEditingController();

  String? _countryCode;


  @override
  void initState() {
    super.initState();

    userData = Get.find<AuthController>().getUserData();
    Get.find<FavNumberController>().updateSelectedFavType(widget.favNumber?.type ??  FavType.fAndF, isUpdate: false);

    final String? previousCountryCode = PhoneNumberHelper.getCountryCode(widget.favNumber?.phone);
    final String? previousPhoneNumber = widget.favNumber?.phone?.replaceAll(previousCountryCode ?? '', '');

    nameTextController.text = widget.favNumber?.name ?? '';
    phoneNumberTextController.text = previousPhoneNumber ?? widget.favNumber?.phone ?? '';
    _countryCode = previousCountryCode ?? Get.find<AuthController>().getUserData()?.countryCode;

  }


  @override
  Widget build(BuildContext context) {
    final bool isEdit = widget.favNumber != null;

    return Scaffold(
      appBar: AppBar(title: Text(isEdit ? 'edit_favorite_number'.tr : 'favorite_number'.tr), backgroundColor: AmialColors.primary, foregroundColor: Colors.white, elevation: 0),
      body: Padding(
        padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
        child: Form(key: _formKey, child: Column(children: [

          Expanded(child: SingleChildScrollView(child: Column(crossAxisAlignment: CrossAxisAlignment.start,children: [

            Text('add_favorite_number'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).primaryColor)),
            const SizedBox(height: Dimensions.paddingSizeLarge),

            GetBuilder<FavNumberController>(builder: (favNumberController) {
              return Container(
                padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
                alignment: Alignment.center,
                width: double.maxFinite,
                height: 50,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(Dimensions.paddingSizeSmall),
                  border: Border.all(width: 1, color: Theme.of(context).hintColor.withValues(alpha: 0.1)),
                ),
                child: Row(children: [
                  FavTypeItemWidget(favOption: FavType.fAndF, favNumberController: favNumberController),
                  const Spacer(),

                  FavTypeItemWidget(favOption: FavType.agent, favNumberController: favNumberController),
                  const Spacer(),

                  FavTypeItemWidget(favOption: FavType.others, favNumberController: favNumberController),
                ]),
              );
            }),
            const SizedBox(height: Dimensions.paddingSizeLarge),


            Text('name'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge)),
            const SizedBox(height: Dimensions.paddingSizeLarge),

            CustomTextFieldWidget(
              enabledBorderColor: Theme.of(context).hintColor.withValues(alpha: 0.1),
              focusedBorderColor: Theme.of(context).hintColor,
              hintText: 'type_name'.tr,
              controller: nameTextController,
              isShowBorder: true,
              inputType: TextInputType.name,
              capitalization: TextCapitalization.words,
              textColor: Theme.of(context).primaryColor,
              borderRadius: Dimensions.radiusSizeExtraSmall,
              onValidate: (name) {
                if(name?.isEmpty ?? true) {
                  return "please_input_name".tr;
                }
                return null;
              },
            ),
            const SizedBox(height: Dimensions.paddingSizeLarge),


            Text('phone_number'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge)),
            const SizedBox(height: Dimensions.paddingSizeLarge),

            CustomTextFieldWidget(
              enabledBorderColor: Theme.of(context).hintColor.withValues(alpha: 0.1),
              focusedBorderColor: Theme.of(context).hintColor,
              onCountryChanged: (CountryCode countryCode) {
                _countryCode = countryCode.dialCode;
              },
              countryDialCode: _countryCode,
              hintText: 'type_number'.tr,
              inputType: TextInputType.number,
              suffixIcon: InkWell(
                splashColor: Colors.transparent,
                highlightColor: Colors.transparent,
                onTap: () async {

                 Get.find<ContactController>().withContactPermission(
                   onSuccess: () async {
                     final QrCodeScannerController qrCodeScannerController = Get.find<QrCodeScannerController>();
                     qrCodeScannerController.onInitScanContact(Get.currentRoute);

                     final ContactTagModel? selectedContact = await Get.to(()=> const SearchContactScreen());

                     _onSelectFromContact(selectedContact ?? qrCodeScannerController.scannedContact);
                   },
                   onDenied: ()=> showCustomSnackBarHelper('you_need_to_allow_to_get_contact_list'.tr),
                 );



                },
                child: Icon(Icons.perm_contact_cal_rounded, color: Theme.of(context).textTheme.bodyLarge?.color),
              ),
              controller: phoneNumberTextController,
              borderRadius: Dimensions.radiusSizeExtraSmall,
              onValidate: (phone){
               return PhoneNumberHelper.onValidatePhone(countryCode: _countryCode!, controller: phoneNumberTextController);

              },
            ),
            const SizedBox(height: Dimensions.paddingSizeLarge),
          ]))),

          SizedBox(
            height: Dimensions.paddingSizeOverLarge,
            child: GetBuilder<FavNumberController>(
                builder: (favNumberController) {
                  return CustomButtonWidget(
                    isLoading: favNumberController.isLoading,
                    onTap: () async {
                      if(_formKey.currentState?.validate() ?? false) {
                        if(isEdit) {
                          _updateNumber();

                        }else {
                          _addNumber();
                        }
                      }
                    },
                    buttonText: isEdit ? 'update'.tr : 'save'.tr,
                    color: Theme.of(context).colorScheme.secondary,
                    buttonTextStyle: rubikSemiBold.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color),
                    borderRadius: Dimensions.radiusSizeExtraSmall,
                  );
                }
            ),
          ),
        ])),
      ),
    );
  }

  void _onSelectFromContact(ContactTagModel? selectedContact) {
    if(selectedContact != null) {
      final String? phoneNumber = selectedContact.contact?.phones.first.number.replaceAll(' ', '').replaceAll('-', '');
      final String? countryCode = PhoneNumberHelper.getCountryCode(phoneNumber);

      if (!mounted) return; // AMIAL-FIX-006
      setState(() {
        _countryCode = countryCode ?? _countryCode;

        phoneNumberTextController.text = (phoneNumber ?? '').replaceAll(countryCode ?? '','');
        nameTextController.text = selectedContact.contact?.displayName ?? '';
      });
    }
  }

  Future<void> _updateNumber() async {
    final FavNumberController favNumberController = Get.find<FavNumberController>();

     final bool isSuccess = await favNumberController.updateFavoriteNumber(
      widget.favNumber?.id,
      type: favNumberController.selectedType,
      phoneNumber: '$_countryCode${phoneNumberTextController.text}',
      name: nameTextController.text,
    );

    if(isSuccess) {
      Get.back();
      showCustomSnackBarHelper('favorite_number_updated_successfully'.tr, isError: false);
    }
  }

  void _addNumber() async {
    final enteredFavNumber = '$_countryCode${phoneNumberTextController.text}';

    if(Get.find<ProfileController>().userInfo?.phone == enteredFavNumber){
      showCustomSnackBarHelper('you_can_not_add_your_own_number'.tr);
      return;
    }

    final FavNumberController favNumberController = Get.find<FavNumberController>();

    final bool isSuccess = await favNumberController.addFavoriteNumber(
      type: favNumberController.selectedType,
      phoneNumber: '$_countryCode${phoneNumberTextController.text}',
      name: nameTextController.text,
    );


    if(isSuccess) {
      Get.back();

      showCustomSnackBarHelper('favorite_number_added_successfully'.tr, isError: false);

    }
  }
}
