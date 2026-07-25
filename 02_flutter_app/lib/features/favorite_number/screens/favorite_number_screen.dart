import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:modal_progress_hud_nsn/modal_progress_hud_nsn.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/no_data_widget.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/favorite_number/domain/models/favorite_list_model.dart';
import 'package:amyal_pay/features/favorite_number/screens/add_fav_number_screen.dart';
import 'package:amyal_pay/features/favorite_number/widgets/fav_num_limit_reached_dialog_widget.dart';
import 'package:amyal_pay/features/favorite_number/widgets/fav_number_list_item_widget.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:skeletonizer/skeletonizer.dart';

class FavoriteNumberScreen extends StatefulWidget {
  final String? transactionType;
  const FavoriteNumberScreen({super.key, this.transactionType});

  @override
  State<FavoriteNumberScreen> createState() => _FavoriteNumberScreenState();
}

class _FavoriteNumberScreenState extends State<FavoriteNumberScreen> {

  bool _isFavoriteAddOrUpdate() => widget.transactionType == null;



  @override
  void initState() {
    super.initState();

    Get.find<FavNumberController>().getList(isUpdate: false, isReload: _isFavoriteAddOrUpdate());
  }

  @override
  Widget build(BuildContext context) {

    return GetBuilder<FavNumberController>(
        builder: (favNumberController) {
          final bool isLimitReached = (favNumberController.favouriteListModel?.total ?? 0) >=
              (favNumberController.favouriteListModel?.limit ?? 0);
          final int availableCount = _availableCount(favNumberController);

          return GetBuilder<ContactController>(builder: (contactController) => ModalProgressHUD(
            inAsyncCall: contactController.isContactListLoading,
            progressIndicator: CircularProgressIndicator(color: Theme.of(context).primaryColor),
            child: Scaffold(
              appBar: AppBar(title: Text('favorite_number'.tr), backgroundColor: AmyalColors.primary, foregroundColor: Colors.white, elevation: 0),
              body: Skeletonizer(
                enabled: favNumberController.favouriteListModel == null,
                child: Column(children: [

                  if(_isFavoriteAddOrUpdate())
                    Container(
                      alignment: Alignment.center,
                      width: double.maxFinite,
                      padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeExtraSmall),
                      color: Theme.of(context).hintColor.withValues(alpha: 0.04),
                      child: Text(
                        isLimitReached ? 'you_have_reached_maximum_Limit'.tr
                            : '$availableCount ${ availableCount > 1
                            ? 'numbers_left'.tr : 'number_left'.tr }',
                        style: rubikRegular.copyWith(
                          fontSize: Dimensions.radiusSizeSmall,
                          color: isLimitReached ? Theme.of(context).colorScheme.error
                              : Theme.of(context).textTheme.bodyLarge?.color,
                        ),
                      ),
                    ),


                  if(favNumberController.favouriteListModel != null && favNumberController.favouriteListModel?.total == 0)
                    NoDataFoundWidget(
                      icon: Icons.star_border_rounded,
                      title: 'no_contact_found'.tr,
                      subtitle: 'أضف أرقامك المتكرّرة لتحويل أسرع دون كتابة الرقم كل مرة.',
                    ),


                  Expanded(child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min,children: [
                    const SizedBox(height: Dimensions.paddingSizeLarge),

                    FavNumberListItemWidget(
                      label: 'friends_and_family'.tr,
                      transactionType: widget.transactionType,
                      favouriteList: favNumberController.favouriteListModel?.fAndF ?? _shimmerData,
                    ),
                    const SizedBox(height: Dimensions.paddingSizeLarge),

                    FavNumberListItemWidget(
                      label: 'agent'.tr,
                      transactionType: widget.transactionType,
                      favouriteList: favNumberController.favouriteListModel?.agent ?? _shimmerData,
                    ),
                    const SizedBox(height: Dimensions.paddingSizeLarge),

                    FavNumberListItemWidget(
                      label: 'other'.tr,
                      transactionType: widget.transactionType,
                      favouriteList: favNumberController.favouriteListModel?.others ?? _shimmerData,
                    ),
                    const SizedBox(height: 80),

                  ]))),

                ]),
              ),
              floatingActionButton: _isFavoriteAddOrUpdate() && favNumberController.favouriteListModel != null ? InkWell(
                splashColor: Colors.transparent,
                highlightColor: Colors.transparent,
                onTap: (){
                  if(isLimitReached){
                    showDialog(context: context, builder: (BuildContext context)=> const FavNumLimitReachedDialogWidget());
                  }else{
                    Get.to(()=> const AddFavNumberScreen());
                  }
                },
                child: Container(
                  padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
                  decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: Theme.of(context).colorScheme.secondary
                  ),
                  child: const Icon(Icons.add, size: Dimensions.paddingSizeLarge),
                ),
              ) : null,
            ),
          ));
        }
    );
  }

  int _availableCount(FavNumberController favNumberController) => (favNumberController.favouriteListModel?.limit ?? 0) - (favNumberController.favouriteListModel?.total ?? 0);

  final _shimmerData = [
    FavouriteModel(name: 'John Doe', phone: '1234567890'),
    FavouriteModel(name: 'John Doe', phone: '1234567890'),
    FavouriteModel(name: 'John Doe', phone: '1234567890'),
    FavouriteModel(name: 'John Doe', phone: '1234567890'),
    FavouriteModel(name: 'John Doe', phone: '1234567890'),
  ];
}
