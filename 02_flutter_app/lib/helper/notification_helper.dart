import 'dart:convert';
import 'dart:io';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:get/get.dart';
import 'package:path_provider/path_provider.dart';
import 'package:http/http.dart' as http;
import 'package:amyal_pay/common/models/notification_body.dart';
import 'package:amyal_pay/features/home/controllers/menu_controller.dart';
import 'package:amyal_pay/features/notification/controllers/notification_controller.dart';
import 'package:amyal_pay/features/requested_money/screens/requested_money_list_screen.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/requested_money/controllers/requested_money_controller.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:open_file/open_file.dart';



class NotificationHelper {

  static Future<void> initialize(FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin) async {
    var androidInitialize = const AndroidInitializationSettings('notification_icon');
    var iOSInitialize = const DarwinInitializationSettings();
    var initializationsSettings = InitializationSettings(android: androidInitialize, iOS: iOSInitialize);
    flutterLocalNotificationsPlugin.initialize(initializationsSettings, onDidReceiveNotificationResponse: (NotificationResponse notificationResponse) async {


      try{
        if(notificationResponse.payload != null && notificationResponse.payload != ''){
          NotificationBody notificationBody = convertNotification(jsonDecode(notificationResponse.payload!));
          final MenuItemController menuItemController = Get.find();
          final TransactionHistoryController transactionHistoryController = Get.find();
          final RequestedMoneyController requestedMoneyController = Get.find();


          if(notificationBody.type == 'general'){
            if(Get.currentRoute != RouteHelper.navbar){
              Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'notification'));
            }else{
              menuItemController.selectNotificationPage();
            }


          }else if(transactionHistoryController.transactionType.contains(notificationBody.type)){
            final int transactionTypeIndex = transactionHistoryController.transactionType.indexOf(notificationBody.type!);
            transactionHistoryController.setIndex(transactionTypeIndex, reload: true);
            await transactionHistoryController.getTransactionData(1, transactionType: transactionHistoryController.transactionType[transactionTypeIndex]);

            if(Get.currentRoute != RouteHelper.navbar){
              Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'history'));
            }else{
              menuItemController.selectHistoryPage();
            }


          } else if(notificationBody.type == 'add_money_bonus'){
            transactionHistoryController.setIndex(transactionHistoryController.transactionType.indexOf('add_money'), reload: false);

            if(Get.currentRoute != RouteHelper.navbar){
              Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'history'));
            }else{
              menuItemController.selectHistoryPage();
            }

          }else if(notificationBody.type == 'request_money'){
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.request));


          }else if(notificationBody.type == 'send_request_money'){
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.sendRequest));


          }else if(notificationBody.type == 'denied_money'){
            requestedMoneyController.setIndex(2, isUpdate: false);
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.sendRequest, isFromNotification: true));


          }else if(notificationBody.type == 'withdraw_money_denied'){
            requestedMoneyController.setIndex(2, isUpdate: false);
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.withdraw, isFromNotification: true));


          }else if(notificationBody.type == 'withdraw_money_approved'){
            requestedMoneyController.setIndex(1, isUpdate: false);
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.withdraw, isFromNotification: true));


          }else if (notificationBody.type == 'download' && notificationBody.filePath != null) {
            await OpenFile.open(notificationBody.filePath!);
          }

        }
      }catch(e){
        debugPrint('Error => $e');
      }


    });

    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      debugPrint('onMessage: ${message.notification!.title}/${message.notification!.body}/${message.notification!.titleLocKey} \n ${message.data}');


      showNotification(message, flutterLocalNotificationsPlugin);

      Get.find<ProfileController>().getProfileData(reload: true);
      final transactionHistoryController = Get.find<TransactionHistoryController>();

      if(message.data['type'] == 'general'){
        Get.find<NotificationController>().getNotificationList(true);

      }else if(message.data['type'] == 'send_request_money' || message.data['type'] == 'denied_money'){
        Get.find<RequestedMoneyController>().getOwnRequestedMoneyList(true);

      }else if(message.data['type'] == 'withdraw_money_denied' || message.data['type'] == 'withdraw_money_approved'){
        Get.find<RequestedMoneyController>().getWithdrawHistoryList(reload: true);

      }else if(transactionHistoryController.transactionType.contains(message.data['type']) || message.data['type'] == 'add_money_bonus'){
        transactionHistoryController.getRecentTransactionList();

        if(Get.find<MenuItemController>().currentTabIndex == 1) {
          transactionHistoryController.getTransactionData(1, transactionType: message.data['type'] == 'add_money_bonus' ? 'add_money' : message.data['type']);
        }else {
          transactionHistoryController.onClearTransactionModel();

        }

      } else{
        Get.find<RequestedMoneyController>().getRequestedMoneyList(true);
      }

    });

    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) async {
      debugPrint('onMessageOpenedApp: ${message.notification!.title}/${message.notification!.body}/${message.notification!.titleLocKey} \n ${message.data}');


      await Get.find<ProfileController>().getProfileData(reload: true);


      final TransactionHistoryController transactionHistoryController = Get.find<TransactionHistoryController>();

      if(message.data['type'] == 'general'){
        await Get.find<NotificationController>().getNotificationList(true);

      }else if(message.data['type'] == 'send_request_money' || message.data['type'] == 'denied_money'){
        await Get.find<RequestedMoneyController>().getOwnRequestedMoneyList(true, isUpdate: false);

      }else if(message.data['type'] == 'withdraw_money_denied' || message.data['type'] == 'withdraw_money_approved'){
        await Get.find<RequestedMoneyController>().getWithdrawHistoryList(reload: true);

      }else if(message.data['type'] == 'add_money_bonus'){
        transactionHistoryController.setIndex(transactionHistoryController.transactionType.indexOf('add_money'), reload: false);

        transactionHistoryController.onClearTransactionModel();

        transactionHistoryController.getRecentTransactionList();

      }else if(transactionHistoryController.transactionType.contains(message.data['type'])){
        transactionHistoryController.setIndex(transactionHistoryController.transactionType.indexOf(message.data['type']), reload: false);
        transactionHistoryController.onClearTransactionModel();

        await transactionHistoryController.getTransactionData(1, reload: true, transactionType: message.data['type']);
        await transactionHistoryController.getRecentTransactionList();

      }else{
        await Get.find<RequestedMoneyController>().getRequestedMoneyList(true);
      }



      try{
        if(message.data.isNotEmpty){
          NotificationBody notificationBody = convertNotification(message.data);
          final MenuItemController menuItemController = Get.find();
          final TransactionHistoryController transactionHistoryController = Get.find();
          final RequestedMoneyController requestedMoneyController = Get.find();

          if(notificationBody.type == 'general'){
            if(Get.currentRoute != RouteHelper.navbar){
              Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'notification'));
            }else{
              menuItemController.selectNotificationPage();
            }


          }else if(notificationBody.type == 'add_money' || notificationBody.type == 'add_money_bonus'){
            if(Get.currentRoute != RouteHelper.navbar){
              Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'history'));
            }else{
              menuItemController.selectHistoryPage();
            }


          }else if(transactionHistoryController.transactionType.contains(notificationBody.type)){
            if(Get.currentRoute != RouteHelper.navbar){
              Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'history'));
            }else{
              menuItemController.selectHistoryPage();
            }


          }else if(notificationBody.type == 'request_money'){
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.request));

          }else if(notificationBody.type == 'send_request_money'){
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.sendRequest));

          }else if(notificationBody.type == 'denied_money'){
            requestedMoneyController.setIndex(2, isUpdate: false);
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.sendRequest, isFromNotification: true));

          }else if(notificationBody.type == 'withdraw_money_denied'){
            requestedMoneyController.setIndex(2, isUpdate: false);
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.withdraw, isFromNotification: true));

          }else if(notificationBody.type == 'withdraw_money_approved'){
            requestedMoneyController.setIndex(1, isUpdate: false);
            Get.to(()=> const RequestedMoneyListScreen(requestType: RequestType.withdraw, isFromNotification: true));

          }

        }
      }catch(e){
        debugPrint('Error => $e');
      }


    });
  }

  static Future<void> showDownloadNotification(String payload, FlutterLocalNotificationsPlugin fln) async {
    const AndroidNotificationDetails androidPlatformChannelSpecifics = AndroidNotificationDetails(
      'download_channel',
      'Downloads',
      channelDescription: 'Notifications for completed downloads',
      importance: Importance.high,
      priority: Priority.high,
    );

    const NotificationDetails platformChannelSpecifics =
    NotificationDetails(android: androidPlatformChannelSpecifics);

    await fln.show(
      0,
      'Download Complete',
      'Tap to open transaction_statement.pdf',
      platformChannelSpecifics,
      payload: payload,
    );
  }

  static Future<void> showNotification(RemoteMessage message, FlutterLocalNotificationsPlugin? fln) async {
    String? title;
    String? body;
    String? orderID;
    String? image;
    String playLoad = jsonEncode(message.data);

    title = message.data['title'];
    body = message.data['body'];
    orderID = message.data['order_id'];
    image = (message.data['image'] != null && message.data['image'].isNotEmpty)
        ? message.data['image'].startsWith('http') ? message.data['image']
        : '${AppConstants.baseUrl}/storage/app/public/notification/${message.data['image']}' : null;

    if(image != null && image.isNotEmpty) {
      try{
        await showBigPictureNotificationHiddenLargeIcon(title, body, orderID, image, fln!, payload: playLoad);
      }catch(e) {
        await showBigTextNotification(title, body!, orderID, fln!, payload: playLoad);
      }
    }else {
      await showBigTextNotification(title, body!, orderID, fln!, payload: playLoad);
    }
  }

  static Future<void> showTextNotification(String title, String body, String orderID, FlutterLocalNotificationsPlugin fln) async {
    const AndroidNotificationDetails androidPlatformChannelSpecifics = AndroidNotificationDetails(
      AppConstants.appName, AppConstants.appName, playSound: true,
      importance: Importance.max, priority: Priority.max, sound: RawResourceAndroidNotificationSound('notification'),
    );
    const NotificationDetails platformChannelSpecifics = NotificationDetails(android: androidPlatformChannelSpecifics);
    await fln.show(0, title, body, platformChannelSpecifics, payload: orderID);
  }

  static Future<void> showBigTextNotification(String? title, String body, String? orderID, FlutterLocalNotificationsPlugin fln, {String? payload}) async {
    BigTextStyleInformation bigTextStyleInformation = BigTextStyleInformation(
      body, htmlFormatBigText: true,
      contentTitle: title, htmlFormatContentTitle: true,
    );
    AndroidNotificationDetails androidPlatformChannelSpecifics = AndroidNotificationDetails(
      AppConstants.appName, AppConstants.appName, importance: Importance.max,
      styleInformation: bigTextStyleInformation, priority: Priority.max, playSound: true,
      sound: const RawResourceAndroidNotificationSound('notification'),
    );
    NotificationDetails platformChannelSpecifics = NotificationDetails(android: androidPlatformChannelSpecifics);
    await fln.show(0, title, body, platformChannelSpecifics, payload: payload);
  }

  static Future<void> showBigPictureNotificationHiddenLargeIcon(String? title, String? body, String? orderID, String image, FlutterLocalNotificationsPlugin fln, {String? payload}) async {
    final String largeIconPath = await _downloadAndSaveFile(image, 'largeIcon');
    final String bigPicturePath = await _downloadAndSaveFile(image, 'bigPicture');
    final BigPictureStyleInformation bigPictureStyleInformation = BigPictureStyleInformation(
      FilePathAndroidBitmap(bigPicturePath), hideExpandedLargeIcon: true,
      contentTitle: title, htmlFormatContentTitle: true,
      summaryText: body, htmlFormatSummaryText: true,
    );
    final AndroidNotificationDetails androidPlatformChannelSpecifics = AndroidNotificationDetails(
      AppConstants.appName, AppConstants.appName,
      largeIcon: FilePathAndroidBitmap(largeIconPath), priority: Priority.max, playSound: true,
      styleInformation: bigPictureStyleInformation, importance: Importance.max,
      sound: const RawResourceAndroidNotificationSound('notification'),
    );
    final NotificationDetails platformChannelSpecifics = NotificationDetails(android: androidPlatformChannelSpecifics);
    await fln.show(0, title, body, platformChannelSpecifics, payload: payload);
  }

  static Future<String> _downloadAndSaveFile(String url, String fileName) async {
    final Directory directory = await getApplicationDocumentsDirectory();
    final String filePath = '${directory.path}/$fileName';
    final http.Response response = await http.get(Uri.parse(url));
    final File file = File(filePath);
    await file.writeAsBytes(response.bodyBytes);
    return filePath;
  }

  static NotificationBody convertNotification(Map<String, dynamic> data){
    return NotificationBody.fromJson(data);
  }

}



Future<dynamic> myBackgroundMessageHandler(RemoteMessage message) async {
  debugPrint("onBackground: ${message.notification!.title}/${message.notification!.body}/${message.notification!.titleLocKey}");
  // var androidInitialize = const AndroidInitializationSettings('notification_icon');
  // var iOSInitialize = const DarwinInitializationSettings();
  // var initializationsSettings = InitializationSettings(android: androidInitialize, iOS: iOSInitialize);
  // FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin = FlutterLocalNotificationsPlugin();
  // flutterLocalNotificationsPlugin.initialize(initializationsSettings);
  // NotificationHelper.showNotification(message, flutterLocalNotificationsPlugin);
}