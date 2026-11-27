import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:get/get.dart';
import 'package:path_provider/path_provider.dart';
import 'package:http/http.dart' as http;
import 'package:amial_pay/common/models/notification_body.dart';
import 'package:amial_pay/firebase_options.dart';
import 'package:amial_pay/features/bill_pay/controllers/bill_pay_controller.dart';
import 'package:amial_pay/features/bill_pay/screens/bill_pay_history_screen.dart';
import 'package:amial_pay/features/family_fund/screens/fund_detail_screen.dart';
import 'package:amial_pay/features/family_fund/screens/my_funds_screen.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/home/controllers/menu_controller.dart';
import 'package:amial_pay/features/notification/controllers/notification_controller.dart';
import 'package:amial_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amial_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/features/requested_money/controllers/requested_money_controller.dart';
import 'package:amial_pay/features/requested_money/screens/incoming_requests_screen.dart';
import 'package:amial_pay/features/requested_money/screens/outgoing_requests_screen.dart';
import 'package:amial_pay/features/requested_money/screens/requested_money_list_screen.dart';
import 'package:amial_pay/features/safe_payment/screens/my_safe_payments_screen.dart';
import 'package:amial_pay/features/safe_payment/screens/safe_payment_detail_screen.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_history_screen.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_pending_screen.dart';
import 'package:amial_pay/helper/payment_feedback.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:open_file/open_file.dart';

class NotificationHelper {
  static int _notificationSeq = 0;

  static int nextNotificationId([Map<String, dynamic>? data]) {
    final parsed = int.tryParse('${data?['notification_id'] ?? ''}');
    if (parsed != null && parsed > 0) {
      return parsed & 0x7fffffff;
    }
    _notificationSeq = (_notificationSeq + 1) % 1000;
    return (((DateTime.now().millisecondsSinceEpoch ~/ 1000) & 0x001fffff) *
            1000) +
        _notificationSeq;
  }

  static bool _isBillPaymentType(dynamic type) => const {
        'bill_payment_success',
        'bill_payment_pending',
        'bill_payment_failed',
      }.contains(type?.toString());

  static bool isAudibleFinancialEvent(String? type) {
    if (type == null || type.isEmpty) return false;
    final t = type.toLowerCase();
    return t.contains('received') ||
        t.contains('transfer') ||
        t.contains('cash_in') ||
        t.contains('cash_out') ||
        t.contains('add_money') ||
        t.contains('withdraw') ||
        t.contains('safe_payment') ||
        t.contains('family_fund') ||
        t.contains('donation') ||
        t.contains('payment_request') ||
        t.contains('bill_payment') ||
        t.contains('refund') ||
        t.contains('credit');
  }

  /// يصوغ جملة عربية نظيفة للنطق الصوتي دون معرّفات ULID أو رموز تقنية.
  static String buildSpokenSummary(String title, String body) {
    final cleanTitle = title.trim();
    final cleanBody = body
        .replaceAll(RegExp(r'[A-Z0-9]{16,}'), '')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
    if (cleanTitle.isEmpty) return cleanBody;
    if (cleanBody.isEmpty || cleanBody == cleanTitle) return cleanTitle;
    return '$cleanTitle. $cleanBody';
  }

  static Future<void> _refreshNotificationsCenterIfRegistered() async {
    try {
      if (Get.isRegistered<NotificationsCenterController>()) {
        final center = Get.find<NotificationsCenterController>();
        await Future.wait([
          center.refreshUnreadCount(),
          center.load(),
        ]);
      }
    } catch (_) {}
  }

  static Future<void> _refreshPaymentRequestsIfRegistered(dynamic type) async {
    if (!Get.isRegistered<PaymentRequestController>()) return;
    final t = type?.toString() ?? '';
    final controller = Get.find<PaymentRequestController>();
    if (t == 'payment_request_received' || t == 'request_money') {
      await controller.loadList('incoming', status: 'pending');
    } else if (t == 'payment_request_declined' ||
        t == 'payment_request_cancelled' ||
        t == 'denied_money') {
      await controller.loadList('outgoing');
    } else if (t == 'payment_request_paid' ||
        t == 'transfer_received' ||
        t == 'transfer_sent' ||
        t == 'money_received' ||
        t == 'transfer_completed' ||
        t == 'received_money' ||
        t == 'send_money') {
      await Future.wait([
        controller.loadList('incoming', status: 'pending'),
        controller.loadList('outgoing'),
      ]);
    }
  }

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

  static const DarwinNotificationDetails _darwinDetails =
      DarwinNotificationDetails(
    presentAlert: true,
    presentBadge: true,
    presentSound: true,
  );

  static Future<void> _ensureAndroidChannel(
      FlutterLocalNotificationsPlugin plugin) async {
    final android = plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(_androidChannel);
  }

  // AMIAL-NOTIFY-DEEPLINK-002 — إشعار cold-start يبقى معلّقاً حتى
  // ينجح تسجيل الدخول. لا نفتح بيانات مالية فوق شاشة الدخول ولا نرمي الحدث.
  static NotificationBody? _pendingInitialNotification;

  static void setPendingInitialNotification(NotificationBody? body) {
    _pendingInitialNotification = body;
  }

  static Future<bool> consumePendingInitialNotification() async {
    final body = _pendingInitialNotification;
    if (body == null) return false;

    // consume-once: الضغط نفسه لا يعيد فتح الشاشة عند rebuild أو login ثانٍ.
    _pendingInitialNotification = null;
    await _routeNotificationBody(body);
    return true;
  }

  static Future<void> _openNotificationCenter() async {
    await _refreshNotificationsCenterIfRegistered();
    try {
      if (Get.isRegistered<NotificationController>()) {
        await Get.find<NotificationController>().getNotificationList(true);
      }
    } catch (_) {}

    if (Get.isRegistered<MenuItemController>()) {
      final MenuItemController menuItemController = Get.find();
      if (Get.currentRoute != RouteHelper.navbar) {
        Get.toNamed(RouteHelper.getNavBarRoute(selectedPage: 'notification'));
      } else {
        menuItemController.selectNotificationPage();
      }
    } else {
      Get.toNamed(RouteHelper.notification);
    }
  }

  static void _openReceiptsOrHistory() {
    if (Get.isRegistered<MenuItemController>() &&
        Get.currentRoute == RouteHelper.navbar) {
      Get.find<MenuItemController>().selectHistoryPage();
    } else {
      Get.to(() => const ReceiptsListScreen());
    }
  }

  /// توجيه موحّد عند الضغط على إشعار (سواء من مركز الإشعارات أو البانر العلوي أو شريط النظام).
  static Future<void> routeNotificationData(Map<String, dynamic> raw) async {
    final type = (raw['type'] ?? '').toString().trim();
    final actionUrl = (raw['action_url'] ?? '').toString().trim();
    final nestedData =
        raw['data'] is Map ? Map<String, dynamic>.from(raw['data'] as Map) : const <String, dynamic>{};

    // 1) مسار action_url الصريح إن وُجد
    if (actionUrl.startsWith('/safe-payments/')) {
      final ulid = actionUrl.substring('/safe-payments/'.length).trim();
      if (ulid.isNotEmpty) {
        Get.to(() => SafePaymentDetailScreen(ulid: ulid));
        return;
      }
      Get.to(() => const MySafePaymentsScreen());
      return;
    }
    if (actionUrl.startsWith('/family-funds/')) {
      final ulid = actionUrl.substring('/family-funds/'.length).trim();
      if (ulid.isNotEmpty) {
        Get.to(() => FundDetailScreen(fundUlid: ulid));
        return;
      }
      Get.to(() => const MyFundsScreen());
      return;
    }
    if (actionUrl == '/receipts') {
      _openReceiptsOrHistory();
      return;
    }

    // 2) حسب نوع الإشعار الحديث في أميال
    if (type.startsWith('safe_payment')) {
      final ulid = (nestedData['payment_ulid'] ?? raw['payment_ulid'] ?? '').toString().trim();
      if (ulid.isNotEmpty) {
        Get.to(() => SafePaymentDetailScreen(ulid: ulid));
      } else {
        Get.to(() => const MySafePaymentsScreen());
      }
      return;
    }
    if (type.startsWith('family_fund')) {
      final ulid = (nestedData['fund_ulid'] ?? raw['fund_ulid'] ?? '').toString().trim();
      if (ulid.isNotEmpty) {
        Get.to(() => FundDetailScreen(fundUlid: ulid));
      } else {
        Get.to(() => const MyFundsScreen());
      }
      return;
    }
    if (type == 'withdrawal_pending' || type == 'withdraw_pending') {
      Get.to(() => const WithdrawPendingScreen());
      return;
    }
    if (type == 'withdrawal_completed' ||
        type == 'withdrawal_failed' ||
        type == 'withdraw_cancelled') {
      Get.to(() => const WithdrawHistoryScreen());
      return;
    }
    if (type == 'money_received' ||
        type == 'transfer_completed' ||
        type == 'transfer_refunded' ||
        type == 'transfer_received' ||
        type == 'transfer_sent' ||
        type == 'cash_in' ||
        type == 'cash_out' ||
        type == 'donation_completed' ||
        type == 'merchant_payment_received' ||
        type == 'refund_received') {
      _openReceiptsOrHistory();
      return;
    }

    // 3) التوجيه المتوافق مع الأنواع الأقدم
    await _routeNotificationBody(convertNotification(raw));
  }

  static Future<void> _routeNotificationBody(
      NotificationBody notificationBody) async {
    final String? type = notificationBody.type;
    if (type == null || type.isEmpty || type == 'general') {
      await _openNotificationCenter();
      return;
    }

    if (_isBillPaymentType(type)) {
      if (Get.isRegistered<BillPayController>()) {
        await Get.find<BillPayController>().loadOrders();
      }
      Get.to(() => const BillPayHistoryScreen());
      return;
    }

    if (type == 'payment_request_received') {
      Get.to(() => const IncomingRequestsScreen());
      return;
    }

    if (type == 'payment_request_declined') {
      Get.to(() => const OutgoingRequestsScreen());
      return;
    }

    if (type == 'request_money') {
      Get.to(() =>
          const RequestedMoneyListScreen(requestType: RequestType.request));
      return;
    }

    if (type == 'send_request_money') {
      Get.to(() =>
          const RequestedMoneyListScreen(requestType: RequestType.sendRequest));
      return;
    }

    if (type == 'download' && notificationBody.filePath != null) {
      await OpenFile.open(notificationBody.filePath!);
      return;
    }

    if (Get.isRegistered<TransactionHistoryController>()) {
      final TransactionHistoryController transactionHistoryController =
          Get.find();

      if (transactionHistoryController.transactionType.contains(type)) {
        final int index =
            transactionHistoryController.transactionType.indexOf(type);
        transactionHistoryController.setIndex(index, reload: true);
        await transactionHistoryController.getTransactionData(
          1,
          transactionType: transactionHistoryController.transactionType[index],
        );
        _openReceiptsOrHistory();
        return;
      }

      if (type == 'add_money_bonus') {
        transactionHistoryController.setIndex(
          transactionHistoryController.transactionType.indexOf('add_money'),
          reload: false,
        );
        _openReceiptsOrHistory();
        return;
      }
    }

    if (Get.isRegistered<RequestedMoneyController>()) {
      final RequestedMoneyController requestedMoneyController = Get.find();
      if (type == 'denied_money') {
        requestedMoneyController.setIndex(2, isUpdate: false);
        Get.to(() => const RequestedMoneyListScreen(
              requestType: RequestType.sendRequest,
              isFromNotification: true,
            ));
        return;
      }

      if (type == 'withdraw_money_denied') {
        requestedMoneyController.setIndex(2, isUpdate: false);
        Get.to(() => const RequestedMoneyListScreen(
              requestType: RequestType.withdraw,
              isFromNotification: true,
            ));
        return;
      }

      if (type == 'withdraw_money_approved') {
        requestedMoneyController.setIndex(1, isUpdate: false);
        Get.to(() => const RequestedMoneyListScreen(
              requestType: RequestType.withdraw,
              isFromNotification: true,
            ));
        return;
      }
    }

    await _openNotificationCenter();
  }

  static void _showForegroundBanner(
    String title,
    String body,
    Map<String, dynamic> data,
  ) {
    try {
      if (Get.context == null) return;
      Get.snackbar(
        title.isNotEmpty ? title : AppConstants.appName,
        body,
        snackPosition: SnackPosition.TOP,
        backgroundColor: AmialColors.primary.withValues(alpha: 0.96),
        colorText: Colors.white,
        margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        borderRadius: 14,
        duration: const Duration(seconds: 4),
        icon: const Icon(
          Icons.notifications_active_rounded,
          color: Color(0xFFFECA1E),
          size: 26,
        ),
        onTap: (_) {
          unawaited(routeNotificationData(data));
        },
      );
    } catch (_) {}
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

  static Future<void> initialize(
      FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin) async {
    var androidInitialize =
        const AndroidInitializationSettings('notification_icon');
    var iOSInitialize = const DarwinInitializationSettings(
      requestAlertPermission: false,
      requestBadgePermission: false,
      requestSoundPermission: false,
    );
    var initializationsSettings =
        InitializationSettings(android: androidInitialize, iOS: iOSInitialize);
    await flutterLocalNotificationsPlugin.initialize(
      initializationsSettings,
      onDidReceiveNotificationResponse:
          (NotificationResponse notificationResponse) async {
        try {
          if (notificationResponse.payload != null &&
              notificationResponse.payload!.isNotEmpty) {
            final decoded = jsonDecode(notificationResponse.payload!);
            if (decoded is Map) {
              await routeNotificationData(Map<String, dynamic>.from(decoded));
            }
          }
        } catch (e) {
          debugPrint('Error => $e');
        }
      },
    );
    await _ensureAndroidChannel(flutterLocalNotificationsPlugin);

    try {
      await FirebaseMessaging.instance
          .setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );
    } catch (_) {}

    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      final title = (message.data['title']?.toString().trim().isNotEmpty == true)
          ? message.data['title'].toString().trim()
          : (message.notification?.title?.trim().isNotEmpty == true
              ? message.notification!.title!.trim()
              : AppConstants.appName);
      final body = (message.data['body']?.toString().trim().isNotEmpty == true)
          ? message.data['body'].toString().trim()
          : (message.notification?.body?.trim() ?? 'notif_default_body'.tr);
      final messageType = message.data['type']?.toString();

      if (kDebugMode) {
        debugPrint('onMessage: $title/$body \n ${message.data}');
      }

      // 1) إشعار النظام المحلي (بمعرّف مستقلّ لا يمسح سابقه) + بانر داخل التطبيق
      showNotification(message, flutterLocalNotificationsPlugin);
      _showForegroundBanner(title, body, message.data);

      // 2) الإشعار الصوتي العربي عند وصول حدث مالي وارد والتطبيق مفتوح
      if (isAudibleFinancialEvent(messageType)) {
        unawaited(
          PaymentFeedback.notify(speech: buildSpokenSummary(title, body)),
        );
      }

      // 3) تحديث فوري لمركز الإشعارات والشارات والرصيد والسجلات
      unawaited(_refreshNotificationsCenterIfRegistered());
      try {
        if (Get.isRegistered<ProfileController>()) {
          Get.find<ProfileController>().getProfileData(reload: true);
        }
      } catch (_) {}

      unawaited(_refreshPaymentRequestsIfRegistered(messageType));

      try {
        if (messageType == 'general' &&
            Get.isRegistered<NotificationController>()) {
          Get.find<NotificationController>().getNotificationList(true);
        } else if (_isBillPaymentType(messageType) &&
            Get.isRegistered<BillPayController>()) {
          Get.find<BillPayController>().loadOrders();
        } else if ((messageType == 'send_request_money' ||
                messageType == 'denied_money') &&
            Get.isRegistered<RequestedMoneyController>()) {
          Get.find<RequestedMoneyController>().getOwnRequestedMoneyList(true);
        } else if ((messageType == 'withdraw_money_denied' ||
                messageType == 'withdraw_money_approved') &&
            Get.isRegistered<RequestedMoneyController>()) {
          Get.find<RequestedMoneyController>()
              .getWithdrawHistoryList(reload: true);
        } else if (Get.isRegistered<TransactionHistoryController>()) {
          final transactionHistoryController =
              Get.find<TransactionHistoryController>();
          if (transactionHistoryController.transactionType
                  .contains(messageType) ||
              messageType == 'add_money_bonus') {
            transactionHistoryController.getRecentTransactionList();
            if (Get.isRegistered<MenuItemController>() &&
                Get.find<MenuItemController>().currentTabIndex == 1) {
              transactionHistoryController.getTransactionData(
                1,
                transactionType: messageType == 'add_money_bonus'
                    ? 'add_money'
                    : messageType,
              );
            } else {
              transactionHistoryController.onClearTransactionModel();
            }
          }
        }
      } catch (_) {}
    });

    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) async {
      if (kDebugMode) {
        debugPrint(
            'onMessageOpenedApp: ${message.notification?.title ?? message.data['title']}/${message.notification?.body ?? message.data['body']} \n ${message.data}');
      }

      await _refreshNotificationsCenterIfRegistered();
      try {
        if (Get.isRegistered<ProfileController>()) {
          await Get.find<ProfileController>().getProfileData(reload: true);
        }
      } catch (_) {}

      final messageType = message.data['type'];
      await _refreshPaymentRequestsIfRegistered(messageType);

      if (message.data.isNotEmpty) {
        await routeNotificationData(message.data);
      } else {
        await _openNotificationCenter();
      }
    });
  }

  static Future<void> showDownloadNotification(
      String payload, FlutterLocalNotificationsPlugin fln) async {
    const AndroidNotificationDetails androidPlatformChannelSpecifics =
        AndroidNotificationDetails(
      'download_channel',
      'Downloads',
      channelDescription: 'Notifications for completed downloads',
      importance: Importance.high,
      priority: Priority.high,
    );

    const NotificationDetails platformChannelSpecifics = NotificationDetails(
      android: androidPlatformChannelSpecifics,
      iOS: _darwinDetails,
    );

    await fln.show(
      nextNotificationId(),
      'Download Complete',
      'Tap to open transaction_statement.pdf',
      platformChannelSpecifics,
      payload: payload,
    );
  }

  static Future<void> showNotification(
      RemoteMessage message, FlutterLocalNotificationsPlugin? fln) async {
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
        : notification?.body ?? 'notif_default_body'.tr;
    String? orderID;
    String? image;
    String playLoad = jsonEncode(message.data);
    final int id = nextNotificationId(message.data);

    orderID = message.data['order_id'];
    final imageValue = message.data['image']?.toString().trim() ?? '';
    image = imageValue.isNotEmpty
        ? imageValue.startsWith('http')
            ? imageValue
            : '${AppConstants.baseUrl}/storage/app/public/notification/$imageValue'
        : null;

    if (image != null && image.isNotEmpty) {
      try {
        await showBigPictureNotificationHiddenLargeIcon(
          title,
          body,
          orderID,
          image,
          plugin,
          payload: playLoad,
          notificationId: id,
        );
      } catch (e) {
        await showBigTextNotification(
          title,
          body,
          orderID,
          plugin,
          payload: playLoad,
          notificationId: id,
        );
      }
    } else {
      await showBigTextNotification(
        title,
        body,
        orderID,
        plugin,
        payload: playLoad,
        notificationId: id,
      );
    }
  }

  static Future<void> showTextNotification(String title, String body,
      String orderID, FlutterLocalNotificationsPlugin fln,
      {int? notificationId}) async {
    const AndroidNotificationDetails androidPlatformChannelSpecifics =
        AndroidNotificationDetails(
      androidChannelId,
      'إشعارات أميال باي',
      playSound: true,
      importance: Importance.max,
      priority: Priority.max,
      sound: RawResourceAndroidNotificationSound('notification'),
    );
    const NotificationDetails platformChannelSpecifics = NotificationDetails(
      android: androidPlatformChannelSpecifics,
      iOS: _darwinDetails,
    );
    await fln.show(
      notificationId ?? nextNotificationId(),
      title,
      body,
      platformChannelSpecifics,
      payload: orderID,
    );
  }

  static Future<void> showBigTextNotification(String? title, String body,
      String? orderID, FlutterLocalNotificationsPlugin fln,
      {String? payload, int? notificationId}) async {
    BigTextStyleInformation bigTextStyleInformation = BigTextStyleInformation(
      body,
      htmlFormatBigText: true,
      contentTitle: title,
      htmlFormatContentTitle: true,
    );
    AndroidNotificationDetails androidPlatformChannelSpecifics =
        AndroidNotificationDetails(
      androidChannelId,
      'إشعارات أميال باي',
      importance: Importance.max,
      styleInformation: bigTextStyleInformation,
      priority: Priority.max,
      playSound: true,
      sound: const RawResourceAndroidNotificationSound('notification'),
    );
    NotificationDetails platformChannelSpecifics = NotificationDetails(
      android: androidPlatformChannelSpecifics,
      iOS: _darwinDetails,
    );
    await fln.show(
      notificationId ?? nextNotificationId(),
      title,
      body,
      platformChannelSpecifics,
      payload: payload,
    );
  }

  static Future<void> showBigPictureNotificationHiddenLargeIcon(
      String? title,
      String? body,
      String? orderID,
      String image,
      FlutterLocalNotificationsPlugin fln,
      {String? payload,
      int? notificationId}) async {
    final String largeIconPath = await _downloadAndSaveFile(image, 'largeIcon');
    final String bigPicturePath =
        await _downloadAndSaveFile(image, 'bigPicture');
    final BigPictureStyleInformation bigPictureStyleInformation =
        BigPictureStyleInformation(
      FilePathAndroidBitmap(bigPicturePath),
      hideExpandedLargeIcon: true,
      contentTitle: title,
      htmlFormatContentTitle: true,
      summaryText: body,
      htmlFormatSummaryText: true,
    );
    final AndroidNotificationDetails androidPlatformChannelSpecifics =
        AndroidNotificationDetails(
      androidChannelId,
      'إشعارات أميال باي',
      largeIcon: FilePathAndroidBitmap(largeIconPath),
      priority: Priority.max,
      playSound: true,
      styleInformation: bigPictureStyleInformation,
      importance: Importance.max,
      sound: const RawResourceAndroidNotificationSound('notification'),
    );
    final NotificationDetails platformChannelSpecifics = NotificationDetails(
      android: androidPlatformChannelSpecifics,
      iOS: _darwinDetails,
    );
    await fln.show(
      notificationId ?? nextNotificationId(),
      title,
      body,
      platformChannelSpecifics,
      payload: payload,
    );
  }

  static Future<String> _downloadAndSaveFile(
      String url, String fileName) async {
    final Directory directory = await getApplicationDocumentsDirectory();
    final String filePath = '${directory.path}/$fileName';
    final http.Response response = await http.get(Uri.parse(url));
    final File file = File(filePath);
    await file.writeAsBytes(response.bodyBytes);
    return filePath;
  }

  static NotificationBody convertNotification(Map<String, dynamic> data) {
    return NotificationBody.fromJson(data);
  }
}

@pragma('vm:entry-point')
Future<void> myBackgroundMessageHandler(RemoteMessage message) async {
  try {
    final firebaseOptions = AmialFirebaseOptions.forCurrentPlatform();
    if (firebaseOptions != null) {
      await Firebase.initializeApp(options: firebaseOptions);
    } else {
      await Firebase.initializeApp();
    }
  } catch (_) {
    // قد تكون Firebase مهيّأة بالفعل في isolate الخلفية.
  }
  try {
    final plugin = FlutterLocalNotificationsPlugin();
    await NotificationHelper.initializeBackground(plugin);
    // إذا حملت الرسالة حقل notification فإنّ نظام Android/iOS يعرضها تلقائياً
    // على قناة amial_pay_default؛ نعرضها محليّاً فقط لرسائل data-only منعاً للتكرار.
    if (message.notification == null) {
      await NotificationHelper.showNotification(message, plugin);
    }
  } catch (error) {
    debugPrint('AMIAL-FCM background notification failed: $error');
  }
}
