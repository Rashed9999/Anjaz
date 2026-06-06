import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_app_bar_widget.dart';
import 'package:amyal_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amyal_pay/features/camera_verification/screens/camera_screen.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/features/transaction_money/widgets/contact_list_widget.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';

class SearchContactScreen extends StatefulWidget {
  const SearchContactScreen({super.key});

  @override
  State<SearchContactScreen> createState() => _SearchContactScreenState();
}

class _SearchContactScreenState extends State<SearchContactScreen> {
  final TextEditingController searchController = TextEditingController();

  @override
  void initState() {
    super.initState();

    final ContactController contactController =  Get.find<ContactController>();

    WidgetsBinding.instance.addPostFrameCallback((_) {
      contactController.getContactList();
    });
  }
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: CustomAppbarWidget(title: 'favorite_number'.tr),
      body: GetBuilder<ContactController>(
        builder: (contactController) {
          return CustomScrollView(slivers: [
            SliverPersistentHeader(pinned: true, delegate: _SliverDelegate(
              child: Container(
                color: Theme.of(context).cardColor,
                padding: const EdgeInsets.all(Dimensions.paddingSizeLarge).copyWith(top: Dimensions.paddingSizeDefault),
                child: Row(children: [

                  Expanded(child: CustomTextFieldWidget(
                    controller: searchController,
                    onChanged: (inputText) => contactController.onSearchContact(
                      searchTerm: inputText.toLowerCase(),
                    ),
                    borderRadius: Dimensions.radiusSizeExtraSmall,
                    hintText: 'search_name_or_number'.tr,
                    suffixIcon: Icon(Icons.search, color: Theme.of(context).hintColor),
                  )),
                  const SizedBox(width: Dimensions.paddingSizeDefault),

                  InkWell(
                    onTap: () => Get.to(()=> const CameraScreen(
                      fromEditProfile: false, isBarCodeScan: true, isHome: false, fromSearchContact: true,
                    )),
                    child: GetBuilder<ProfileController>(builder: (controller) {
                      return Container(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                          border: Border.all(width: 1, color: Theme.of(context).hintColor.withValues(alpha: 0.1)),
                        ),
                        padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                        child: Icon(Icons.qr_code_2_outlined, size: Dimensions.paddingSizeExtraLarge, color: Theme.of(context).primaryColor),
                      );
                    }),
                  ),
                ]),
              ),
            )),

            ContactListWidget(onSelect: (contact) {
              if(contact?.favouriteModel != null) {
                showCustomSnackBarHelper('this_contact_is_already_favorite'.tr, isError: true);

              }else {
                Get.back(result: contact);
              }


            }),
          ]);
        }
      ),
    );
  }
}
class _SliverDelegate extends SliverPersistentHeaderDelegate {
  Widget child;
  _SliverDelegate({required this.child});

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return child;
  }

  @override
  double get maxExtent => 84;

  @override
  double get minExtent => 84;

  @override
  bool shouldRebuild(_SliverDelegate oldDelegate) {
    return oldDelegate.maxExtent != 84 || oldDelegate.minExtent != 84 || child != oldDelegate.child;
  }
}
