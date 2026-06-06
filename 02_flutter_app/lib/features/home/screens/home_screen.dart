
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/show_custom_bottom_sheet.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/home/controllers/banner_controller.dart';
import 'package:amyal_pay/features/home/controllers/home_controller.dart';
import 'package:amyal_pay/features/home/controllers/menu_controller.dart';
import 'package:amyal_pay/features/home/domain/models/global_key_manager_model.dart';
import 'package:amyal_pay/features/home/widgets/recent_transaction_widget.dart';
import 'package:amyal_pay/features/home/widgets/show_case/showcaseview.dart';
import 'package:amyal_pay/features/home/widgets/welcome_bottom_sheet.dart';
import 'package:amyal_pay/features/notification/controllers/notification_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/requested_money/controllers/requested_money_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/home/controllers/websitelink_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/features/home/widgets/home_app_bar_widget.dart';
import 'package:amyal_pay/features/home/widgets/theme_one_widget.dart';
import 'package:amyal_pay/features/home/widgets/linked_website_widget.dart';
import 'package:amyal_pay/features/home/widgets/theme_two_widget.dart';
import 'package:amyal_pay/features/home/widgets/theme_three_widget.dart';


class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {

  ScrollController scrollController = ScrollController();

  List<GlobalKey<State<StatefulWidget>>>  visibleKeys = [];

  GlobalKey? lastGlobalKey;

  final GlobalKeyManagerModel keyManager = GlobalKeyManagerModel();


  @override
  void initState() {
    _loadData(context, false);
    super.initState();
    Get.find<MenuItemController>().updateHomeScrollPosition(scrollController: scrollController);
    if(Get.find<AuthController>().getTourWidgetStatus() && Get.find<AuthController>().showWelcomeBottomSheet()){
      WidgetsBinding.instance.addPostFrameCallback((_) {
        showCustomBottomSheet(
          child:  WelcomeBottomSheet(keys: _getVisibleGlobalKeys(), showCaseContext: context),
        );

        setState(() {
          lastGlobalKey = _getVisibleGlobalKeys().last;
          if (kDebugMode) {
            print("Last Global Key : $lastGlobalKey");
          }
        });
      });

    } else if (Get.find<AuthController>().getTourWidgetStatus()){
      WidgetsBinding.instance.addPostFrameCallback((_) {
        ShowCaseWidget.of(context).startShowCase(_getVisibleGlobalKeys());
        setState(() {
          lastGlobalKey = _getVisibleGlobalKeys().last;
          if (kDebugMode) {
            print("Last Global Key : $lastGlobalKey");
          }
        });
      });
    }
  }

  List<GlobalKey> _getVisibleGlobalKeys() {
    final systemFeature = Get.find<SplashController>().configModel?.systemFeature;
    final currentThemeIndex = Get.find<SplashController>().configModel?.themeIndex;
    bool hasScrollableItem = false;

    visibleKeys.clear();


    visibleKeys.add(keyManager.appbarBalanceKey);

    _addIfVisible(keyManager.sendMoneyKey, systemFeature?.sendMoneyStatus);
    _addIfVisible(keyManager.cashOutKey, systemFeature?.cashOutStatus);

    if(currentThemeIndex == 1) {
      if (_checkAndAddWithScroll(keyManager.requestMoneyKey, systemFeature?.sendMoneyRequestStatus)) {
        hasScrollableItem = true;
      }

      _addIfVisible(keyManager.sendMoneyRequestKey, systemFeature?.sendMoneyRequestStatus);

    }

    _addIfVisible(keyManager.addMoneyKey, systemFeature?.addMoneyStatus);

    if(currentThemeIndex != 1) {
      if (_checkAndAddWithScroll(keyManager.requestMoneyKey, systemFeature?.sendMoneyRequestStatus)) {
        hasScrollableItem = true;
      }
    }


    if (_checkAndAddWithScroll(keyManager.withdrawKey, systemFeature?.withdrawRequestStatus)) {
      hasScrollableItem = true;
    }

    if (hasScrollableItem) {
      visibleKeys.add(keyManager.scrollableKey);
    }

    return visibleKeys;
  }

  void _addIfVisible(GlobalKey key, bool? status) {
    if (key.currentContext != null && status == true) {
      visibleKeys.add(key);
    }
  }

  bool _checkAndAddWithScroll(GlobalKey key, bool? status) {
    if (status == true && key.currentContext != null) {
      if (_checkWidgetVisibility(key)) {
        visibleKeys.add(key);
      } else {
        return true;
      }
    }
    return false;
  }

  bool _checkWidgetVisibility(GlobalKey key) {
    final themeIndex = Get.find<SplashController>().configModel?.themeIndex ?? 1;
    final screenSize = MediaQuery.of(context).size;
    final renderBox = key.currentContext?.findRenderObject() as RenderBox?;

    if (renderBox == null) return false;

    final position = renderBox.localToGlobal(Offset.zero);
    final cardWidth = themeIndex == 3 ? Dimensions.themeThreeTransactionCardWidth : 0;

    return position.dx >= 0 &&
        position.dx + cardWidth <= screenSize.width &&
        position.dy >= 0 &&
        position.dy <= screenSize.height;
  }




  Future<void> _loadData(BuildContext context, bool reload) async {
    final TransactionHistoryController transactionHistoryController = Get.find<TransactionHistoryController>();

    if(reload){
      Get.find<SplashController>().getConfigData();
    }
    Get.find<ProfileController>().getProfileData(reload: reload);
    Get.find<ProfileController>().updateBalanceButtonTappedStatus(shouldUpdate: false);
    Get.find<BannerController>().getBannerList(reload);
    Get.find<RequestedMoneyController>().getRequestedMoneyList(reload, isUpdate: reload);
    Get.find<RequestedMoneyController>().getOwnRequestedMoneyList(reload, isUpdate: reload);
    if(transactionHistoryController.recentTransactionList == null || reload) {
      transactionHistoryController.getRecentTransactionList();
    }
    if(transactionHistoryController.transactionModel == null || reload) {
      transactionHistoryController.getTransactionData(1, reload: reload);
    }
    Get.find<WebsiteLinkController>().getWebsiteList(reload, isUpdate: reload);
    Get.find<NotificationController>().getNotificationList(reload);
    Get.find<TransactionMoneyController>().getPurposeList(reload,  isUpdate: reload);
    Get.find<TransactionMoneyController>().getWithdrawMethods(isReload: reload);
    Get.find<RequestedMoneyController>().getWithdrawHistoryList(reload: reload, isUpdate: reload);
    Get.find<FavNumberController>().getFromLocalStorage();

  }


  @override
  Widget build(BuildContext context) {
    return GetBuilder<HomeController>(
      builder: (_) {
        return Scaffold(
          appBar: HomeAppBarWidget(
            scrollController: scrollController,
            availableBalanceKey: keyManager.appbarBalanceKey,
            withdrawKey: keyManager.withdrawKey,
          ),
          body: RefreshIndicator(
            onRefresh: () async => await _loadData(context, true),
            child: SingleChildScrollView(
              controller: scrollController,
              physics: const AlwaysScrollableScrollPhysics(parent: ClampingScrollPhysics()),
              child: GetBuilder<SplashController>(
                builder: (splashController) {
                  final themeIndex = splashController.configModel?.themeIndex ?? 1;

                  return Column(
                    children: [
                      if (themeIndex == 1) ThemeOneWidget(keyManager: keyManager, lastKey: lastGlobalKey,),
                      if (themeIndex == 2) ThemeTwoWidget(keyManager: keyManager),
                      if (themeIndex == 3) ThemeThreeWidget(keyManager: keyManager, lastKey: lastGlobalKey),

                      const LinkedWebsiteWidget(),
                      const SizedBox(height: Dimensions.paddingSizeLarge),
                      const RecentTransactionWidget(),
                    ],
                  );
                },
              ),
            ),
          ),
        );
      },
    );
  }
}

