import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_pop_scope_widget.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/camera_verification/screens/camera_screen.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/home/controllers/menu_controller.dart';
import 'package:amyal_pay/features/home/domain/enums/nav_bar_page_enum.dart';
import 'package:amyal_pay/features/home/widgets/show_case/showcaseview.dart';

class NavBarScreen extends StatefulWidget {
  final String? selectedPage;
  const NavBarScreen({super.key, this.selectedPage});

  @override
  State<NavBarScreen> createState() => _NavBarScreenState();
}

class _NavBarScreenState extends State<NavBarScreen> {
  final PageStorageBucket bucket = PageStorageBucket();

  @override
  void initState() {
    super.initState();

    final MenuItemController menuItemController = Get.find();

    NavBarPageEnum navBarPageEnum = _getNavPageEnum(widget.selectedPage);

    switch(navBarPageEnum){
      case NavBarPageEnum.home :
        menuItemController.selectHomePage(isUpdate: false);
        break;
      case NavBarPageEnum.history :
        menuItemController.selectHistoryPage(isUpdate: false);
        break;
      case NavBarPageEnum.notification :
        menuItemController.selectNotificationPage(isUpdate: false);
        break ;
      case NavBarPageEnum.profile :
        menuItemController.selectProfilePage(isUpdate: false);
        break;
    }


    Get.find<AuthController>().checkBiometricWithPin();
    Get.find<FavNumberController>().getList(isUpdate: false, isReload: true);

  }

  @override
  Widget build(BuildContext context) {
    return GetBuilder<MenuItemController>(builder: (menuController) {

      final padding = MediaQuery.of(context).padding;

      return CustomPopScopeWidget(
        onPopInvoked: (){
          if(menuController.currentTabIndex != 0) {
            menuController.resetNavBarTabIndex(isUpdate: true);
          }
        },
        isExit: menuController.currentTabIndex == 0 && !Get.find<AuthController>().getTourWidgetStatus(),
        child: ShowCaseWidget(
          onFinish: (){
            Get.find<AuthController>().setTourWidgetStatus(false);
            if(GetPlatform.isAndroid){
              FirebaseMessaging.instance.requestPermission();
            }
          },
          // AMIAL-NAV-003: شريط تنقّل «كبسولة عائمة» (كما في مراجع المحافظ
          // الاحترافية): حاوية داكنة مستديرة بالكامل تطفو فوق المحتوى، وزرّ
          // المسح في مركزها بلون البراند الأصفر. كان شريطاً أبيض بعرض الشاشة.
          builder : (context) => Scaffold(
            backgroundColor: const Color(0xFFF2F3F7),
            extendBody: true,
            body: PageStorage(bucket: bucket, child: menuController.screen[menuController.currentTabIndex]),

            bottomNavigationBar: Padding(
              padding: EdgeInsets.fromLTRB(
                  16, 0, 16, padding.bottom > 15 ? padding.bottom - 4 : 14),
              child: Container(
                height: 68,
                decoration: BoxDecoration(
                  color: const Color(0xFF0A2A6B),
                  borderRadius: BorderRadius.circular(34),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFF053391).withValues(alpha: 0.30),
                      blurRadius: 22,
                      offset: const Offset(0, 8),
                    ),
                  ],
                ),
                child: Row(children: [
                  Expanded(
                    child: Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                      _navItem(menuController, 0, Icons.home_rounded,
                          Icons.home_outlined, 'الرئيسية',
                          () => menuController.selectHomePage()),
                      _navItem(menuController, 1, Icons.receipt_long_rounded,
                          Icons.receipt_long_outlined, 'السجل', () {
                        Get.find<TransactionHistoryController>().setIndex(0, reload: false);
                        menuController.selectHistoryPage();
                      }),
                    ]),
                  ),

                  // زرّ المسح في مركز الكبسولة
                  GestureDetector(
                    onTap: () => Get.to(() => const CameraScreen(
                          fromEditProfile: false, isBarCodeScan: true, isHome: true,
                        )),
                    child: Container(
                      width: 54, height: 54,
                      decoration: BoxDecoration(
                        color: const Color(0xFFFECA1E),
                        shape: BoxShape.circle,
                        boxShadow: [
                          BoxShadow(
                            color: const Color(0xFFFECA1E).withValues(alpha: 0.45),
                            blurRadius: 14,
                            offset: const Offset(0, 4),
                          ),
                        ],
                      ),
                      child: const Icon(Icons.qr_code_scanner_rounded,
                          color: Color(0xFF053391), size: 26),
                    ),
                  ),

                  Expanded(
                    child: Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                      _navItem(menuController, 2, Icons.notifications_rounded,
                          Icons.notifications_none_rounded, 'الإشعارات',
                          () => menuController.selectNotificationPage()),
                      _navItem(menuController, 3, Icons.person_rounded,
                          Icons.person_outline_rounded, 'حسابي',
                          () => menuController.selectProfilePage()),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
        ),
      );
    });
  }

  /// عنصر تبويب داخل الكبسولة الداكنة: أصفر البراند للمحدَّد، أبيض باهت لغيره.
  Widget _navItem(MenuItemController c, int index, IconData selectedIcon,
      IconData icon, String label, VoidCallback onTap) {
    final selected = c.currentTabIndex == index;
    const active = Color(0xFFFECA1E);
    final inactive = Colors.white.withValues(alpha: 0.55);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(selected ? selectedIcon : icon,
              size: 22, color: selected ? active : inactive),
          const SizedBox(height: 3),
          Text(label,
              style: TextStyle(
                fontSize: 9.5,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                color: selected ? active : inactive,
              )),
        ]),
      ),
    );
  }

  NavBarPageEnum _getNavPageEnum(String? page){
    switch(page ?? false){
      case 'home':
        return NavBarPageEnum.home;
      case 'notification':
        return NavBarPageEnum.notification;
      case 'profile':
        return NavBarPageEnum.profile;
      case 'history':
        return NavBarPageEnum.history;
      default:
        return NavBarPageEnum.home;
    }
  }

}



