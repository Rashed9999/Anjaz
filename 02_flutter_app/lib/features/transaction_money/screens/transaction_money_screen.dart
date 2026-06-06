import 'package:country_code_picker/country_code_picker.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:modal_progress_hud_nsn/modal_progress_hud_nsn.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/common/widgets/custom_app_bar_widget.dart';
import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/common/widgets/custom_button_widget.dart';
import 'package:amyal_pay/common/widgets/custom_image_widget.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amyal_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amyal_pay/features/favorite_number/screens/favorite_number_screen.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';
import 'package:amyal_pay/features/transaction_money/widgets/contact_list_widget.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/helper/phone_cheker_helper.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';

import '../../camera_verification/screens/camera_screen.dart';

class TransactionMoneyScreen extends StatefulWidget {
  final bool? fromEdit;
  final String? phoneNumber;
  final String? transactionType;
  const TransactionMoneyScreen({super.key, this.fromEdit, this.phoneNumber, this.transactionType});

  @override
  State<TransactionMoneyScreen> createState() => _TransactionMoneyScreenState();
}

class _TransactionMoneyScreenState extends State<TransactionMoneyScreen> {
  String? customerImageBaseUrl = Get.find<SplashController>().configModel!.baseUrls!.customerImageUrl;

  String? agentImageBaseUrl = Get.find<SplashController>().configModel!.baseUrls!.agentImageUrl;
  final ScrollController _scrollController = ScrollController();
  String? _countryCode;

  UserShortDataModel? userData;

  @override
  void initState() {
    super.initState();

    onInit();
  }

  Future<void> onInit() async {
    Get.find<SplashController>().getConfigData();

    final ContactController contactController =  Get.find<ContactController>();

    contactController.getContactList();

    contactController.getSuggestList(type: widget.transactionType);
    contactController.onSearchContact(searchTerm: '');

    userData = Get.find<AuthController>().getUserData();
    _countryCode = userData?.countryCode;

  }

  @override
  Widget build(BuildContext context) {
    final TextEditingController searchController = TextEditingController();
    widget.fromEdit!? searchController.text = widget.phoneNumber!: const SizedBox();


    return Scaffold(
      appBar:  CustomAppbarWidget(title: widget.transactionType!.tr),

      body: GetBuilder<ContactController>(builder: (contactController) => Column(children: [
        Expanded(child: ModalProgressHUD(
          inAsyncCall: contactController.isContactListLoading,
          progressIndicator: CircularProgressIndicator(color: Theme.of(context).primaryColor),
          child: CustomScrollView(
            controller: _scrollController,
            slivers: [

              SliverPersistentHeader(pinned: true, delegate: SliverDelegate(
                child: Container(
                  color: Theme.of(context).cardColor,
                  padding: const EdgeInsets.all(Dimensions.paddingSizeLarge).copyWith(top: Dimensions.paddingSizeDefault),
                  child: Column(children: [

                    Row(children: [
                      Text('enter_number'.tr, style: rubikMedium.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color, fontSize: Dimensions.fontSizeLarge)),

                      const Spacer(),

                      GetBuilder<SplashController>(builder: (splashController) {
                        return splashController.configModel?.isFavoriteNumberActive ?? false ? InkWell(
                          onTap: () => Get.to(()=> FavoriteNumberScreen(transactionType: widget.transactionType)),
                          child: GetBuilder<ProfileController>(builder: (controller) {
                            return Container(
                              decoration: BoxDecoration(
                                color: Theme.of(context).hintColor.withValues(alpha: 0.04),
                                borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                              ),
                              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: Dimensions.radiusSizeExtraSmall),
                              child: const CustomAssetImageWidget(
                                Images.starIcon,
                                height: Dimensions.paddingSizeLarge,
                                width: Dimensions.paddingSizeLarge,
                                fit: BoxFit.contain,
                              ),
                            );
                          }),
                        ) : const SizedBox();
                      }),
                      const SizedBox(width: Dimensions.paddingSizeSmall),

                      InkWell(
                        onTap: () => Get.to(()=> CameraScreen(
                          fromEditProfile: false, isBarCodeScan: true, isHome: false, transactionType: widget.transactionType,
                        )),
                        child: GetBuilder<ProfileController>(builder: (controller) {
                          return Container(
                            decoration: BoxDecoration(
                              color: Theme.of(context).hintColor.withValues(alpha: 0.04),
                              borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                            ),
                            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: Dimensions.radiusSizeExtraSmall),
                            child: Icon(Icons.qr_code_2_outlined, size: Dimensions.paddingSizeLarge, color: Theme.of(context).primaryColor),
                          );
                        }),
                      ),
                    ]),
                    const SizedBox(height: Dimensions.paddingSizeLarge),

                    CustomTextFieldWidget(
                      onCountryChanged: (CountryCode countryCode)=> _countryCode = countryCode.dialCode,
                      countryDialCode: _countryCode,
                      hintText: 'type_number'.tr,
                      inputType: TextInputType.number,
                      controller: searchController,
                      onChanged: (inputText) => contactController.onSearchContact(
                        searchTerm: inputText.toLowerCase(),
                      ),
                      borderRadius: Dimensions.radiusSizeExtraSmall,
                      suffixIcon: contactController.getContactPermissionStatus ? Icon(Icons.perm_contact_cal_rounded, color: Theme.of(context).textTheme.bodyLarge?.color) : null,
                    ),
                  ]),
                ),
              )),

              SliverToBoxAdapter(child: Column( children: [
                contactController.sendMoneySuggestList.isNotEmpty &&  widget.transactionType == 'send_money'?
                GetBuilder<TransactionMoneyController>(builder: (sendMoneyController) {
                  return  Padding(
                    padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall, horizontal: Dimensions.paddingSizeLarge),
                    child: Column(mainAxisAlignment: MainAxisAlignment.start, crossAxisAlignment: CrossAxisAlignment.start,
                      children: [

                        Padding(padding: const EdgeInsets.only(bottom: Dimensions.paddingSizeSmall),
                          child: Text('suggested'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge)),
                        ),

                        SizedBox(height: 80.0,
                          child: ListView.builder(itemCount: contactController.sendMoneySuggestList.length, scrollDirection: Axis.horizontal,
                              itemBuilder: (context, index)=> CustomInkWellWidget(
                                radius : Dimensions.radiusSizeVerySmall,
                                highlightColor: Theme.of(context).textTheme.titleLarge!.color!.withValues(alpha:0.3),
                                onTap: () => contactController.onTapSuggest(index, widget.transactionType),
                                child: Container(
                                  margin: const EdgeInsets.only(right: Dimensions.paddingSizeSmall),
                                  child: Column(children: [
                                    SizedBox(
                                      height: Dimensions.radiusSizeExtraExtraLarge,width:Dimensions.radiusSizeExtraExtraLarge,
                                      child: ClipRRect(
                                        borderRadius: BorderRadius.circular(Dimensions.radiusSizeOverLarge),
                                        child: CustomImageWidget(
                                          fit: BoxFit.cover,
                                          image: "$customerImageBaseUrl/${contactController.sendMoneySuggestList[index].avatarImage.toString()}",
                                          placeholder: Images.avatar,
                                        ),
                                      ),
                                    ), Padding(padding: const EdgeInsets.only(top: Dimensions.paddingSizeSmall),
                                      child: Text(contactController.sendMoneySuggestList[index].name == null ? contactController.sendMoneySuggestList[index].phoneNumber! : contactController.sendMoneySuggestList[index].name! ,
                                          style: contactController.sendMoneySuggestList[index].name == null ? rubikLight.copyWith(fontSize: Dimensions.fontSizeSmall) : rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault)),
                                    )
                                  ],
                                  ),
                                ),
                              )
                          ),
                        ),
                      ],
                    ),
                  );
                }) :
                ((contactController.requestMoneySuggestList.isNotEmpty) && widget.transactionType == 'request_money') ?
                GetBuilder<TransactionMoneyController>(builder: (requestMoneyController) {
                  return requestMoneyController.isLoading ? const Center(child: CircularProgressIndicator()) : Padding(
                    padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall, horizontal: Dimensions.paddingSizeLarge),
                    child: Column(mainAxisAlignment: MainAxisAlignment.start, crossAxisAlignment: CrossAxisAlignment.start,
                      children: [

                        Padding(padding: const EdgeInsets.only(bottom: Dimensions.paddingSizeSmall),
                          child: Text('suggested'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge)),
                        ),

                        SizedBox(
                          height: 80.0,
                          child: ListView.builder(itemCount: contactController.requestMoneySuggestList.length, scrollDirection: Axis.horizontal,
                            itemBuilder: (context, index)=> CustomInkWellWidget(
                              radius : Dimensions.radiusSizeVerySmall,
                              highlightColor: Theme.of(context).textTheme.titleLarge!.color!.withValues(alpha: 0.3),
                              onTap: ()=> contactController.onTapSuggest(index, widget.transactionType),
                              child: Container(
                                margin: const EdgeInsets.only(right: Dimensions.paddingSizeSmall),
                                child: Column(children: [
                                  SizedBox(
                                    height: Dimensions.radiusSizeExtraExtraLarge,width:Dimensions.radiusSizeExtraExtraLarge,
                                    child: ClipRRect(
                                      borderRadius: BorderRadius.circular(Dimensions.radiusSizeOverLarge),
                                      child: CustomImageWidget(image: "$customerImageBaseUrl/${contactController.requestMoneySuggestList[index].avatarImage.toString()}",
                                          fit: BoxFit.cover, placeholder: Images.avatar),
                                    ),
                                  ),

                                  Padding(padding: const EdgeInsets.only(top: Dimensions.paddingSizeSmall),
                                    child: Text(contactController.requestMoneySuggestList[index].name == null ? contactController.requestMoneySuggestList[index].phoneNumber! : contactController.requestMoneySuggestList[index].name! ,
                                        style: contactController.requestMoneySuggestList[index].name == null ? rubikLight.copyWith(fontSize: Dimensions.fontSizeLarge) : rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge)),
                                  ),
                                ]),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                }
                ) :
                ((contactController.cashOutSuggestList.isNotEmpty) && widget.transactionType == TransactionType.cashOut)?
                GetBuilder<TransactionMoneyController>(builder: (cashOutController) {
                  return cashOutController.isLoading ? const Center(child: CircularProgressIndicator()) : Column(mainAxisAlignment: MainAxisAlignment.start, crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Padding(padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall, horizontal: Dimensions.paddingSizeLarge),
                        child: Text('recent_agent'.tr, style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge)),
                      ),

                      ListView.builder(
                          itemCount: contactController.cashOutSuggestList.length, scrollDirection: Axis.vertical, shrinkWrap:true,physics: const NeverScrollableScrollPhysics(),
                          itemBuilder: (context, index)=> CustomInkWellWidget(
                            highlightColor: Theme.of(context).textTheme.titleLarge!.color!.withValues(alpha: 0.3),
                            onTap: () => contactController.onTapSuggest(index, widget.transactionType),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge,vertical: Dimensions.paddingSizeSmall),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.start,
                                crossAxisAlignment: CrossAxisAlignment.center,
                                children: [
                                  SizedBox(
                                    height: Dimensions.radiusSizeExtraExtraLarge,width:Dimensions.radiusSizeExtraExtraLarge,
                                    child: ClipRRect(
                                      borderRadius: BorderRadius.circular(Dimensions.radiusSizeOverLarge),
                                      child: CustomImageWidget(
                                        fit: BoxFit.cover,
                                        image: "$agentImageBaseUrl/${
                                            contactController.cashOutSuggestList[index].avatarImage.toString()}",
                                        placeholder: Images.avatar,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: Dimensions.paddingSizeSmall,),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(contactController.cashOutSuggestList[index].name == null ?'Unknown':contactController.cashOutSuggestList[index].name!,style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge,color: Theme.of(context).textTheme.bodyLarge!.color)),
                                      Text(contactController.cashOutSuggestList[index].phoneNumber != null ? contactController.cashOutSuggestList[index].phoneNumber! : 'No Number',style: rubikLight.copyWith(fontSize: Dimensions.fontSizeDefault,color: Theme.of(context).hintColor.withValues(alpha: 0.4)),),
                                    ],
                                  )
                                ],
                              ),
                            ),
                          )

                      ),
                    ],
                  );
                }) : const SizedBox(),
              ])),

              ContactListWidget(transactionType: widget.transactionType, showNoContactFound: false),

            ],
          ),
        )),

        Container(
          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge, vertical: Dimensions.paddingSizeDefault),
          decoration: BoxDecoration(
              color: Theme.of(context).cardColor,
              boxShadow: [BoxShadow(
                blurRadius: 10,
                color: Colors.black.withValues(alpha: 0.08),
              )]
          ),
          child: SizedBox(
            height: Dimensions.paddingSizeOverLarge,
            child: CustomButtonWidget(
              buttonText: 'next'.tr,
              buttonTextStyle: rubikMedium.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color),
              borderRadius: Dimensions.radiusSizeExtraSmall,
              color: Theme.of(context).colorScheme.secondary,
              onTap: searchController.text.isEmpty ? null : (){
                if(searchController.text.isEmpty){
                  showCustomSnackBarHelper('input_field_is_empty'.tr,isError: true);
                }else {

                  final String phoneNumber = PhoneNumberHelper.getValidatePhoneNumberWithPhoneParser(
                    countryCode: _countryCode,
                    phoneNumber: searchController.text,
                  );


                  if (widget.transactionType == "cash_out") {
                    contactController.checkAgentNumber(phoneNumber: phoneNumber).then((value) {
                      if (value.isOk) {
                        String? agentName = value.body['data']['name'];
                        String? agentImage = value.body['data']['image'];
                        bool isFavorite = value.body['data']['is_favourite'] ?? false;
                        Get.to(() => TransactionBalanceInputScreen(transactionType: widget.transactionType, contactModel: ContactModel(
                          phoneNumber: '$_countryCode${searchController.text.trim()}',
                          name: agentName,
                          avatarImage: agentImage,
                          isFavourite: isFavorite,
                        )));
                      }
                    });
                  } else {
                    contactController.checkCustomerNumber(phoneNumber: phoneNumber).then((value) {
                      if (value!.isOk) {
                        String? customerName = value.body['data']['name'];
                        String? customerImage = value.body['data']['image'];
                        bool isFavorite = value.body['data']['is_favourite'] ?? false;

                        Get.to(() =>  TransactionBalanceInputScreen(
                          transactionType: widget.transactionType,
                          contactModel: ContactModel(
                            phoneNumber: phoneNumber,
                            name: customerName,
                            avatarImage: customerImage,
                            isFavourite: isFavorite,
                          ),
                        ));
                      }
                    });
                  }
                }
              },
            ),
          ),
        ),

      ])),
    );
  }
}



class SliverDelegate extends SliverPersistentHeaderDelegate {
  Widget child;
  SliverDelegate({required this.child});

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return child;
  }

  @override
  double get maxExtent => 140;

  @override
  double get minExtent => 140;

  @override
  bool shouldRebuild(SliverDelegate oldDelegate) {
    return oldDelegate.maxExtent != 140 || oldDelegate.minExtent != 140 || child != oldDelegate.child;
  }
}
