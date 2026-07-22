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
          // AMIAL-NAV-002: شريط تنقّل بتصميم «المحفظة» — أبيض بحواف علوية
          // مستديرة + زرّ مسح QR عائم في المنتصف (أزرق بحلقة صفراء). لا فكّ
          // إجباري لألوان الثيم (كان bodyLarge!.color! مصدر رمادية محتملاً).
          builder : (context) => Scaffold(
            backgroundColor: const Color(0xFFF2F3F7),
            body: PageStorage(bucket: bucket, child: menuController.screen[menuController.currentTabIndex]),

            floatingActionButton: Container(
              width: 62, height: 62,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: const Color(0xFFFECA1E), width: 3),
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFF053391).withValues(alpha: 0.35),
                    blurRadius: 14, offset: const Offset(0, 5),
                  ),
                ],
              ),
              child: FloatingActionButton(
                shape: const CircleBorder(),
                backgroundColor: const Color(0xFF053391),
                elevation: 0,
                onPressed: ()=> Get.to(()=> const CameraScreen(
                  fromEditProfile: false, isBarCodeScan: true, isHome: true,
                )),
                child: const Icon(Icons.qr_code_scanner_rounded,
                    color: Colors.white, size: 27),
              ),
            ),

            floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,

            bottomNavigationBar: Container(
              padding: EdgeInsets.only(top: 10,
                bottom: padding.bottom > 15 ? 0 : 10,
              ),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(24),
                  topRight: Radius.circular(24),
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.08),
                    blurRadius: 20, offset: const Offset(0, -4),
                  ),
                ],
              ),

              child: SafeArea(
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
                  const SizedBox(width: 56), // مساحة الزرّ العائم

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

  /// عنصر تبويب حديث: أيقونة Material + تسمية، بألوان هوية أميال.
  Widget _navItem(MenuItemController c, int index, IconData selectedIcon,
      IconData icon, String label, VoidCallback onTap) {
    final selected = c.currentTabIndex == index;
    const active = Color(0xFF053391);
    const inactive = Color(0xFF8B97A8);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(selected ? selectedIcon : icon,
              size: 24, color: selected ? active : inactive),
          const SizedBox(height: 3),
          Text(label,
              style: TextStyle(
                fontSize: 10.5,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w400,
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



