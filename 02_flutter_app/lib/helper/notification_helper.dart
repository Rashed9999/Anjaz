import 'dart:convert';
import 'dart:io';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:get/get.dart';
import 'package:path_provider/path_provider.dart';
import 'package:http/http.dart' as http;
import 'package:amial_pay/common/models/notification_body.dart';
import 'package:amial_pay/features/home/controllers/menu_controller.dart';
import 'package:amial_pay/features/notification/controllers/notification_controller.dart';
import 'package:amial_pay/features/requested_money/screens/requested_money_list_screen.dart';
import 'package:amial_pay/features/requested_money/screens/incoming_requests_screen.dart';
import 'package:amial_pay/features/requested_money/screens/outgoing_requests_screen.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/requested_money/controllers/requested_money_controller.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:open_file/open_file.dart';



class NotificationHelper {
  /// هذا هو القناة الوحيدة لإشعارات أميال على Android. يطابق المعرّف
  /// المعلن في AndroidManifest وFCM، فلا يفقد إشعار الخلفية صوته بسبب
  /// إنشاء قناة باسم مختلف عن القناة التي يرسل إليها الخادم.
  static const String androidChannelId = 'amial_pay_default';
  static const AndroidNotificationChannel _androidChannel =
      AndroidNotificationChannel(
    androidChannelId,
    'إشعارات أميال باي',
    description: 'تنبيهات العمليات والطلبات المهمة',
    importance: Importance.max,
    playSound: true,
    sound: RawResourceAndroidNotificationSound('notification'),
  );

  static Future<void> _ensureAndroidChannel(
      FlutterLocalNotificationsPlugin plugin) async {
    final android = plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(_androidChannel);
  }

  /// تهيئة آمنة للعامل الخلفي: بلا GetX أو تنقل، فقط قناة النظام وعرضه.
  static Future<void> initializeBackground(
      FlutterLocalNotificationsPlugin plugin) async {
    const settings = InitializationSettings(
      android: AndroidInitializationSettings('notification_icon'),
      iOS: DarwinInitializationSettings(),
    );
    await plugin.initialize(settings);
    await _ensureAndroidChannel(plugin);
  }

  static Future<void> initialize(FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin) async {
    var androidInitialize = const AndroidInitializationSettings('notification_icon');
    var iOSInitialize = const DarwinInitializationSettings();
    var initializationsSettings = InitializationSettings(android: androidInitialize, iOS: iOSInitialize);
    await flutterLocalNotificationsPlugin.initialize(initializationsSettings, onDidReceiveNotificationResponse: (NotificationResponse notificationResponse) async {


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

          }else if(notificationBody.type == 'payment_request_received'){
            // AMIAL-REQUEST-DIRECT-003 — **إشعارٌ يصل ولا يقود إلى شيء.**
            //
            // `payment_request_received` يُرسَل من `PaymentRequestService`
            // منذ بُني الطلبُ المباشر، **ولم يكن له فرعٌ هنا إطلاقاً**:
            // يرنّ الهاتفُ فيضغط المستلمُ الإشعار فلا يفتح شيء. فيبقى
            // المالُ معلّقاً، ويعود الطالبُ إلى واتساب — ومنه إلى الرابط.
            Get.to(()=> const IncomingRequestsScreen());

          }else if(notificationBody.type == 'payment_request_declined'){
            // ورفضُ الطلب يقود صاحبَه إلى طلباته المرسلة لا إلى قائمةٍ
            // من النظام القديم لا يجد فيها طلبَه.
            Get.to(()=> const OutgoingRequestsScreen());

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
    await _ensureAndroidChannel(flutterLocalNotificationsPlugin);

    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      debugPrint('onMessage: ${message.notification?.title ?? message.data['title']}/${message.notification?.body ?? message.data['body']} \n ${message.data}');


      showNotification(message, flutterLocalNotificationsPlugin);

      Get.find<ProfileController>().getProfileData(reload: true);
      final transactionHistoryController = Get.find<TransactionHistoryController>();

      if(message.data['type'] == 'general'){
        Get.find<NotificationController>().getNotificationList(true);

      }else if(message.data['type'] == 'payment_request_received'){
        // الطلب الجديد ينتمي إلى payment_requests لا صندوق 6cash القديم.
        Get.find<PaymentRequestController>().loadList('incoming', status: 'pending');

      }else if(message.data['type'] == 'payment_request_declined'){
        Get.find<PaymentRequestController>().loadList('outgoing');

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
      debugPrint('onMessageOpenedApp: ${message.notification?.title ?? message.data['title']}/${message.notification?.body ?? message.data['body']} \n ${message.data}');


      await Get.find<ProfileController>().getProfileData(reload: true);


      final TransactionHistoryController transactionHistoryController = Get.find<TransactionHistoryController>();

      if(message.data['type'] == 'general'){
        await Get.find<NotificationController>().getNotificationList(true);

      }else if(message.data['type'] == 'payment_request_received'){
        await Get.find<PaymentRequestController>().loadList('incoming', status: 'pending');

      }else if(message.data['type'] == 'payment_request_declined'){
        await Get.find<PaymentRequestController>().loadList('outgoing');

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


          }else if(notificationBody.type == 'payment_request_received'){
            // AMIAL-REQUEST-DIRECT-003 — **إشعارٌ يصل ولا يقود إلى شيء.**
            //
            // `payment_request_received` يُرسَل من `PaymentRequestService`
            // منذ بُني الطلبُ المباشر، **ولم يكن له فرعٌ هنا إطلاقاً**:
            // يرنّ الهاتفُ فيضغط المستلمُ الإشعار فلا يفتح شيء. فيبقى
            // المالُ معلّقاً، ويعود الطالبُ إلى واتساب — ومنه إلى الرابط.
            Get.to(()=> const IncomingRequestsScreen());

          }else if(notificationBody.type == 'payment_request_declined'){
            // ورفضُ الطلب يقود صاحبَه إلى طلباته المرسلة لا إلى قائمةٍ
            // من النظام القديم لا يجد فيها طلبَه.
            Get.to(()=> const OutgoingRequestsScreen());

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
    final plugin = fln;
    if (plugin == null) return;
    final notification = message.notification;
    final notificationTitle = notification?.title?.trim();
    final title = message.data['title']?.toString().trim().isNotEmpty == true
        ? message.data['title'].toString()
        : notificationTitle?.isNotEmpty == true
            ? notificationTitle!
            : AppConstants.appName;
    final body = message.data['body']?.toString().trim().isNotEmpty == true
        ? message.data['body'].toString()
        : notification?.body ?? 'لديك إشعار جديد من أميال باي';
    String? orderID;
    String? image;
    String playLoad = jsonEncode(message.data);

    orderID = message.data['order_id'];
    final imageValue = message.data['image']?.toString().trim() ?? '';
    image = imageValue.isNotEmpty
        ? imageValue.startsWith('http') ? imageValue
        : '${AppConstants.baseUrl}/storage/app/public/notification/$imageValue' : null;

    if(image != null && image.isNotEmpty) {
      try{
        await showBigPictureNotificationHiddenLargeIcon(title, body, orderID, image, plugin, payload: playLoad);
      }catch(e) {
        await showBigTextNotification(title, body, orderID, plugin, payload: playLoad);
      }
    }else {
      await showBigTextNotification(title, body, orderID, plugin, payload: playLoad);
    }
  }

  static Future<void> showTextNotification(String title, String body, String orderID, FlutterLocalNotificationsPlugin fln) async {
    const AndroidNotificationDetails androidPlatformChannelSpecifics = AndroidNotificationDetails(
      androidChannelId, 'إشعارات أميال باي', playSound: true,
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
      androidChannelId, 'إشعارات أميال باي', importance: Importance.max,
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
      androidChannelId, 'إشعارات أميال باي',
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



@pragma('vm:entry-point')
Future<void> myBackgroundMessageHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } catch (_) {
    // قد تكون Firebase مهيّأة بالفعل في isolate الاختبار؛ نكمل عرض الإشعار.
  }
  try {
    final plugin = FlutterLocalNotificationsPlugin();
    await NotificationHelper.initializeBackground(plugin);
    await NotificationHelper.showNotification(message, plugin);
  } catch (error) {
    debugPrint('AMIAL-FCM background notification failed: $error');
  }
}
