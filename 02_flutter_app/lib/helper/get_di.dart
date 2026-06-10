import 'dart:convert';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/favorite_number/domain/repositories/fav_number_repo.dart';
import 'package:amyal_pay/features/forget_pin/domain/reposotories/forget_pin_repo.dart';
import 'package:amyal_pay/features/history/controllers/report_dispute_controller.dart';
import 'package:amyal_pay/features/history/domain/reposotories/report_dispute_repo.dart';
import 'package:amyal_pay/features/amyal/controllers/amyal_controller.dart';
import 'package:amyal_pay/features/amyal/domain/repositories/amyal_repo.dart';
import 'package:amyal_pay/features/receipts/controllers/receipts_controller.dart';
import 'package:amyal_pay/features/receipts/domain/repositories/receipts_repo.dart';
import 'package:amyal_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amyal_pay/features/family_fund/domain/repositories/funds_repo.dart';
import 'package:amyal_pay/features/agent/controllers/agent_controller.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';
import 'package:amyal_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amyal_pay/features/bill_pay/controllers/bill_pay_controller.dart';
import 'package:amyal_pay/features/bill_pay/domain/repositories/bill_pay_repo.dart';
import 'package:amyal_pay/features/donations/controllers/donations_controller.dart';
import 'package:amyal_pay/features/donations/domain/repositories/donations_repo.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/merchant_repo.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_pay_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/merchant_pay_repo.dart';
import 'package:amyal_pay/features/merchant/controllers/split_bill_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/split_bill_repo.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/cashier_repo.dart';
import 'package:amyal_pay/features/merchant/controllers/customer_credit_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/customer_credit_repo.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_refund_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/cashier_refund_repo.dart';
import 'package:amyal_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amyal_pay/features/requested_money/domain/repositories/payment_request_repo.dart';
import 'package:amyal_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amyal_pay/features/notification/domain/reposotories/notifications_center_repo.dart';
import 'package:amyal_pay/features/withdraw/controllers/customer_withdraw_controller.dart';
import 'package:amyal_pay/features/withdraw/domain/repositories/customer_withdraw_repo.dart';
import 'package:amyal_pay/features/me/domain/me_repo.dart';
import 'package:amyal_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amyal_pay/features/safe_payment/domain/repositories/safe_payment_repo.dart';
import 'package:amyal_pay/features/merchant_verification/controllers/merchant_verification_controller.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amyal_pay/features/fuel_station/domain/repositories/fuel_station_repo.dart';
import 'package:amyal_pay/features/pharmacy/controllers/pharmacy_controller.dart';
import 'package:amyal_pay/features/pharmacy/domain/repositories/pharmacy_repo.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/access/domain/repositories/access_repo.dart';
import 'package:amyal_pay/features/admin/controllers/admin_controller.dart';
import 'package:amyal_pay/features/admin/controllers/whatsapp_settings_controller.dart';
import 'package:amyal_pay/features/admin/controllers/settings_center_controller.dart';
import 'package:amyal_pay/features/admin/domain/repositories/admin_repo.dart';
import 'package:amyal_pay/features/barcode/domain/repositories/barcode_repo.dart';
import 'package:amyal_pay/features/wholesale/controllers/wholesale_controller.dart';
import 'package:amyal_pay/features/wholesale/domain/repositories/wholesale_repo.dart';
import 'package:amyal_pay/features/plans/controllers/plans_controller.dart';
import 'package:amyal_pay/features/plans/domain/repositories/plans_repo.dart';
import 'package:amyal_pay/features/admin/controllers/subscriptions_controller.dart';
import 'package:amyal_pay/features/admin/domain/repositories/subscriptions_repo.dart';
import 'package:amyal_pay/features/branches/controllers/branches_controller.dart';
import 'package:amyal_pay/features/branches/controllers/pos_rbac_controller.dart';
import 'package:amyal_pay/features/branches/domain/repositories/branches_repo.dart';
import 'package:amyal_pay/features/home/controllers/banner_controller.dart';
import 'package:amyal_pay/features/auth/controllers/create_account_controller.dart';
import 'package:amyal_pay/features/onboarding/controllers/on_boarding_controller.dart';
import 'package:amyal_pay/features/setting/controllers/edit_profile_controller.dart';
import 'package:amyal_pay/features/setting/controllers/faq_controller.dart';
import 'package:amyal_pay/features/forget_pin/controllers/forget_pin_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/bootom_slider_controller.dart';
import 'package:amyal_pay/features/add_money/controllers/add_money_controller.dart';
import 'package:amyal_pay/features/kyc_verification/controllers/kyc_verify_controller.dart';
import 'package:amyal_pay/features/home/controllers/menu_controller.dart';
import 'package:amyal_pay/features/notification/controllers/notification_controller.dart';
import 'package:amyal_pay/features/camera_verification/controllers/qr_code_scanner_controller.dart';
import 'package:amyal_pay/common/controllers/share_controller.dart';
import 'package:amyal_pay/features/requested_money/controllers/requested_money_controller.dart';
import 'package:amyal_pay/features/camera_verification/controllers/camera_screen_controller.dart';
import 'package:amyal_pay/features/home/controllers/home_controller.dart';
import 'package:amyal_pay/features/language/controllers/language_controller.dart';
import 'package:amyal_pay/features/language/controllers/localization_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/features/setting/controllers/theme_controller.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/transaction_money/domain/reposotories/contact_repo.dart';
import 'package:amyal_pay/features/verification/controllers/verification_controller.dart';
import 'package:amyal_pay/features/home/controllers/websitelink_controller.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/add_money/domain/reposotories/add_money_repo.dart';
import 'package:amyal_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amyal_pay/features/home/domain/reposotories/banner_repo.dart';
import 'package:amyal_pay/features/setting/domain/reposotories/faq_repo.dart';
import 'package:amyal_pay/features/notification/domain/reposotories/notification_repo.dart';
import 'package:amyal_pay/features/setting/domain/reposotories/profile_repo.dart';
import 'package:amyal_pay/features/transaction_money/domain/reposotories/transaction_repo.dart';
import 'package:amyal_pay/features/history/domain/reposotories/transaction_history_repo.dart';
import 'package:amyal_pay/features/home/domain/reposotories/websitelink_repo.dart';
import 'package:amyal_pay/features/splash/domain/reposotories/splash_repo.dart';
import 'package:amyal_pay/features/requested_money/domain/reposotories/requested_money_repo.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/common/models/language_model.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:get/get.dart';
import 'package:unique_identifier/unique_identifier.dart';

import '../features/kyc_verification/domain/reposotories/kyc_verify_repo.dart';


Future<Map<String, Map<String, String>>> init() async {
  // Core
  final sharedPreferences = await SharedPreferences.getInstance();

  final BaseDeviceInfo deviceInfo = await DeviceInfoPlugin().deviceInfo;

  // صلابة الإقلاع: غياب/فشل إضافة unique-identifier (منصّة غير مدعومة أو خطأ
  // keystore) يجب ألّا يُسقط التطبيق كاملاً — نسقط لمعرّف فارغ بأمان.
  String uniqueId = '';
  try {
    uniqueId = await UniqueIdentifier.serial ?? '';
  } catch (e) {
    if (kDebugMode) debugPrint('[DI] unique_identifier unavailable: $e');
  }

  Get.lazyPut(() => uniqueId);
  Get.lazyPut(() => sharedPreferences);
  Get.lazyPut(() => deviceInfo);


  Get.lazyPut(() => ApiClient(
    appBaseUrl: AppConstants.baseUrl,
    sharedPreferences: Get.find(),
    uniqueId: Get.find(),
    deiceInfo: Get.find(),
  ));

  // Repository
   Get.lazyPut(() => SplashRepo(sharedPreferences: Get.find(), apiClient: Get.find()));
  Get.lazyPut(() => TransactionRepo(apiClient: Get.find(), sharedPreferences: Get.find()));
  Get.lazyPut(() => AuthRepo(apiClient: Get.find(),sharedPreferences: Get.find()));
  Get.lazyPut(() => ProfileRepo(apiClient: Get.find(), sharedPreferences: Get.find()));
  Get.lazyPut(() => ProfileRepo(apiClient: Get.find(), sharedPreferences: Get.find()));
  Get.lazyPut(() => WebsiteLinkRepo(apiClient: Get.find()));
  Get.lazyPut(() => BannerRepo(apiClient: Get.find()));
  Get.lazyPut(() => AddMoneyRepo(apiClient: Get.find()));
  Get.lazyPut(() => FaqRepo(apiClient: Get.find()));
  Get.lazyPut(() => NotificationRepo(apiClient: Get.find()));
  Get.lazyPut(() => RequestedMoneyRepo(apiClient: Get.find()));
  Get.lazyPut(() => TransactionHistoryRepo(apiClient: Get.find()));
  Get.lazyPut(() => KycVerifyRepo(apiClient: Get.find()));
  Get.lazyPut(() => ForgetPinRepo(apiClient: Get.find()));
  Get.lazyPut(() => ContactRepo(apiClient: Get.find(), sharedPreferences: Get.find()));
  Get.lazyPut(() => FavNumberRepo(apiClient: Get.find(), sharedPreferences: Get.find()));
  Get.lazyPut(() => ReportDisputeRepo(apiClient: Get.find()));

  // Controller
  Get.lazyPut(() => ThemeController(sharedPreferences: Get.find()));
   Get.lazyPut(() => SplashController(splashRepo: Get.find()));
  Get.lazyPut(() => LocalizationController(sharedPreferences: Get.find()));
  Get.lazyPut(() => LanguageController(sharedPreferences: Get.find()));
  Get.lazyPut(() => TransactionMoneyController(transactionRepo: Get.find(), authRepo: Get.find()));
  Get.lazyPut(() => AddMoneyController(addMoneyRepo:Get.find() ));
  Get.lazyPut(() => NotificationController(notificationRepo: Get.find()));
  Get.lazyPut(() => ProfileController(profileRepo: Get.find()));
  Get.lazyPut(() => FaqController(faqrepo: Get.find()));
  Get.lazyPut(() => BottomSliderController());

  Get.lazyPut(() => MenuItemController());
  Get.lazyPut(() => AuthController(authRepo: Get.find()));
  Get.lazyPut(() => HomeController());
  Get.lazyPut(() => CreateAccountController());
  Get.lazyPut(() => VerificationController());
  Get.lazyPut(() => CameraScreenController());
  Get.lazyPut(() => ForgetPinController(forgetPinRepo: Get.find()));
  Get.lazyPut(() => WebsiteLinkController(websiteLinkRepo: Get.find()));
  Get.lazyPut(() => QrCodeScannerController());
  Get.lazyPut(() => BannerController(bannerRepo: Get.find()));
  Get.lazyPut(() => TransactionHistoryController(transactionHistoryRepo: Get.find()));
  Get.lazyPut(() => EditProfileController(authRepo: Get.find()));
  Get.lazyPut(() => RequestedMoneyController(requestedMoneyRepo: Get.find()));
  Get.lazyPut(() => ShareController());
  Get.lazyPut(() => KycVerifyController(kycVerifyRepo: Get.find()));
  Get.lazyPut(() => OnBoardingController());
  Get.lazyPut(() => ContactController(contactRepo: Get.find()));
  Get.lazyPut(() => FavNumberController(favNumberRepo: Get.find()));
  Get.lazyPut(() => ReportDisputeController(reportDisputeRepo: Get.find()));

  // ====== AMYAL v0.7-D: Legal + Zone + Recovery ======
  Get.lazyPut(() => AmyalRepo(apiClient: Get.find()));
  Get.lazyPut(() => AmyalController(repo: Get.find()), fenix: true);

  // ====== AMYAL v0.9-D: Receipts + Family Fund + Bill Pay ======
  Get.lazyPut(() => ReceiptsRepo(apiClient: Get.find()));
  Get.lazyPut(() => ReceiptsController(repo: Get.find()), fenix: true);

  Get.lazyPut(() => FundsRepo(apiClient: Get.find()));
  Get.lazyPut(() => FundsController(repo: Get.find()), fenix: true);

  Get.lazyPut(() => BillPayRepo(apiClient: Get.find()));
  Get.lazyPut(() => BillPayController(repo: Get.find()), fenix: true);

  // ====== AMYAL v1.1: Safe Payment ======
  Get.lazyPut(() => SafePaymentRepo(apiClient: Get.find()));
  Get.lazyPut(() => SafePaymentController(repo: Get.find()), fenix: true);

  // ====== AMYAL v1.2: Donations ======
  Get.lazyPut(() => DonationsRepo(apiClient: Get.find()));
  Get.lazyPut(() => DonationsController(repo: Get.find()), fenix: true);

  // ====== AMYAL v1.5: Unified Auth ======
  Get.lazyPut(() => UnifiedAuthRepo(apiClient: Get.find()));
  Get.lazyPut(() => UnifiedAuthController(repo: Get.find()), fenix: true);

  // ====== AMYAL v1.6: Agent App ======
  Get.lazyPut(() => AgentRepo(apiClient: Get.find()));
  Get.lazyPut(() => AgentController(repo: Get.find()), fenix: true);

  // ====== AMYAL v1.6: Merchant App ======
  Get.lazyPut(() => MerchantRepo(apiClient: Get.find()));
  Get.lazyPut(() => MerchantController(repo: Get.find()), fenix: true);

  // AMIAL-MERCHANT-PAY-001 — دفع العميل للتاجر (QR/POS)
  Get.lazyPut(() => MerchantPayRepo(apiClient: Get.find()));
  Get.lazyPut(() => MerchantPayController(repo: Get.find()), fenix: true);

  // AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة
  Get.lazyPut(() => SplitBillRepo(apiClient: Get.find()));
  Get.lazyPut(() => SplitBillController(repo: Get.find()), fenix: true);

  // AMIAL-CUSTOMER-CREDIT-001 — نظام ديون العملاء
  Get.lazyPut(() => CustomerCreditRepo(apiClient: Get.find()));
  Get.lazyPut(() => CustomerCreditController(repo: Get.find()), fenix: true);

  // AMIAL-CASHIER-REFUND-001 — مرتجعات بيوع الكاشير
  Get.lazyPut(() => CashierRefundRepo(apiClient: Get.find()));
  Get.lazyPut(() => CashierRefundController(repo: Get.find()), fenix: true);

  // AMIAL-PAYMENT-REQUESTS-001 — طلب أموال
  Get.lazyPut(() => PaymentRequestRepo(apiClient: Get.find()));
  Get.lazyPut(() => PaymentRequestController(repo: Get.find()), fenix: true);

  // AMIAL-MERCHANT-VERIFY-001 — توثيق التاجر
  Get.lazyPut(() => MerchantVerificationRepo(apiClient: Get.find()));
  Get.lazyPut(() => MerchantVerificationController(repo: Get.find()), fenix: true);

  // AMIAL-FUEL-001 — محطة الوقود (Cashier متخصّص)
  Get.lazyPut(() => FuelStationRepo(apiClient: Get.find()));
  Get.lazyPut(() => FuelStationController(repo: Get.find()), fenix: true);

  // AMIAL-PHARMACY-001 — الصيدلية (Cashier متخصّص)
  Get.lazyPut(() => PharmacyRepo(apiClient: Get.find()));
  Get.lazyPut(() => PharmacyController(repo: Get.find()), fenix: true);

  // CRITICAL-001 — Access Control (Foundation للأدوار + الخطط + Features)
  // permanent لأنه يُستخدم في كل شاشة عبر AccessGate
  Get.put(AccessRepo(apiClient: Get.find()), permanent: true);
  Get.put(AccessController(repo: Get.find()), permanent: true);

  // CRITICAL-001-ADMIN — لوحة الإدارة
  Get.lazyPut(() => AdminRepo(apiClient: Get.find()));
  Get.lazyPut(() => AdminController(repo: Get.find()), fenix: true);

  // AMIAL-WHATSAPP-OTP-001 — إعدادات قناة واتساب (لوحة الأدمن)
  Get.lazyPut(() => WhatsappSettingsController(repo: Get.find()), fenix: true);

  // AMIAL-SETTINGS-CENTER-001 — مركز الإعدادات الموحّد
  Get.lazyPut(() => SettingsCenterController(repo: Get.find()), fenix: true);

  // AMIAL-BARCODE-001 — Continuous Scanner (موحّد لكل القطاعات)
  Get.lazyPut(() => BarcodeRepo(apiClient: Get.find()));

  // AMIAL-WHOLESALE-001 — قطاع الجملة
  Get.lazyPut(() => WholesaleRepo(apiClient: Get.find()));
  Get.lazyPut(() => WholesaleController(repo: Get.find()), fenix: true);

  // CRITICAL-001-PLANS — الخطط + الاستخدام
  Get.lazyPut(() => PlansRepo(apiClient: Get.find()));
  Get.lazyPut(() => PlansController(repo: Get.find()), fenix: true);

  // CRITICAL-001-SUBS — إدارة الاشتراكات (للأدمن)
  Get.lazyPut(() => SubscriptionsRepo(apiClient: Get.find()));
  Get.lazyPut(() => SubscriptionsController(repo: Get.find()), fenix: true);

  // P1-BRANCHES — إدارة الفروع
  Get.lazyPut(() => BranchesRepo(apiClient: Get.find()));
  Get.lazyPut(() => BranchesController(repo: Get.find()), fenix: true);

  // P1-RBAC — الأدوار والصلاحيات
  Get.lazyPut(() => PosRbacRepo(apiClient: Get.find()));
  Get.lazyPut(() => PosRbacController(repo: Get.find()), fenix: true);

  // AMIAL-NOTIFICATIONS-001 — مركز الإشعارات
  Get.lazyPut(() => NotificationsCenterRepo(apiClient: Get.find()));
  Get.lazyPut(() => NotificationsCenterController(repo: Get.find()), fenix: true);

  // AMIAL-CUSTOMER-WITHDRAW-001 — السحب من العميل
  Get.lazyPut(() => CustomerWithdrawRepo(apiClient: Get.find()));
  Get.lazyPut(() => CustomerWithdrawController(repo: Get.find()), fenix: true);

  // AMIAL-ME-001 — بيانات المستخدم الحالي
  Get.lazyPut(() => MeRepo(apiClient: Get.find()));
  Get.lazyPut(() => MeController(repo: Get.find()), fenix: true);

  // AMIAL-CASHIER-001 — كاشير التاجر
  Get.lazyPut(() => CashierRepo(apiClient: Get.find()));
  Get.lazyPut(() => CashierController(repo: Get.find()), fenix: true);



  // Retrieving localized data
  Map<String, Map<String, String>> languages = {};
  for(LanguageModel languageModel in AppConstants.languages) {
    String jsonStringValues =  await rootBundle.loadString('assets/language/${languageModel.languageCode}.json');
    Map<String, dynamic> mappedJson = jsonDecode(jsonStringValues);
    Map<String, String> json = {};
    mappedJson.forEach((key, value) {
      json[key] = value.toString();
    });
    languages['${languageModel.languageCode}_${languageModel.countryCode}'] = json;
  }
  return languages;
}
