import 'package:amyal_pay/features/history/screens/history_screen.dart';
import 'package:amyal_pay/features/home/screens/amial_customer_home_screen.dart';
import 'package:amyal_pay/features/notification/screens/notification_screen.dart';
import 'package:amyal_pay/features/setting/screens/profile_screen.dart';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

class MenuItemController extends GetxController implements GetxService{
  int _currentTabIndex = 0;
  int get currentTabIndex => _currentTabIndex;

  double _homePageScrollPosition = 0.0;
  double get homePageScrollPosition => _homePageScrollPosition;


  // AMIAL-CUSTOMER-HOME-001: تبويب «البيت» صار الشاشة الرئيسية بهوية أميال باي
  // (كان HomeScreen من 6cash). باقي التبويبات كما هي داخل نفس الغلاف السفلي.
  final List<Widget> screen = [
    const AmialCustomerHomeScreen(),
    const HistoryScreen(),
    const NotificationScreen(),
    const ProfileScreen()
  ];

  void resetNavBarTabIndex({bool isUpdate = false}){
    _currentTabIndex = 0;

    if(isUpdate) {
      update();
    }
  }

  void selectHomePage({bool isUpdate = true}) {
    _currentTabIndex = 0;
    if(isUpdate) {
      update();
    }
  }

  void selectHistoryPage({bool isUpdate = true}) {
    _currentTabIndex = 1;
    if(isUpdate){
      update();
    }
  }

  void selectNotificationPage({bool isUpdate = true}) {
    _currentTabIndex = 2;
    if(isUpdate){
      update();
    }
  }

  void selectProfilePage({bool isUpdate = true}) {
    _currentTabIndex = 3;
    if(isUpdate){
      update();
    }
  }

  void updateHomeScrollPosition({required ScrollController scrollController, bool isUpdate = false}){

    _homePageScrollPosition = 0;

    scrollController.addListener((){
      if(scrollController.position.pixels > 150){
        _homePageScrollPosition = scrollController.position.pixels;

      } else{
        _homePageScrollPosition = 0;
      }
      update();
    });



  }
}
