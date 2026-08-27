import 'dart:convert';
import 'package:get/get.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/common/models/signup_body_model.dart';
import 'package:amial_pay/common/models/contact_model.dart';
import 'package:amial_pay/features/auth/screens/unified_login_screen.dart';
import 'package:amial_pay/features/auth/screens/amial_registration_wizard_screen.dart';
import 'package:amial_pay/features/auth/screens/pin_set_screen.dart';
import 'package:amial_pay/features/camera_verification/screens/camera_screen.dart';
import 'package:amial_pay/features/home/screens/nav_bar_screen.dart';
import 'package:amial_pay/features/forget_pin/screens/forget_pin_screen.dart';
import 'package:amial_pay/features/forget_pin/screens/reset_pin_screen.dart';
import 'package:amial_pay/features/access/screens/home_dispatcher_screen.dart';
import 'package:amial_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amial_pay/features/setting/screens/profile_screen.dart';
import 'package:amial_pay/features/setting/widgets/change_pin_screen.dart';
import 'package:amial_pay/features/setting/screens/edit_profile_screen.dart';
import 'package:amial_pay/features/setting/widgets/faq_screen.dart';
import 'package:amial_pay/features/setting/screens/html_view_screen.dart';
import 'package:amial_pay/features/setting/screens/qr_code_download_or_share_screen.dart';
import 'package:amial_pay/features/setting/screens/support_screen.dart';
import 'package:amial_pay/features/splash/screens/splash_screen.dart';
import 'package:amial_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';
import 'package:amial_pay/features/transaction_money/screens/transaction_confirmation_screen.dart';
import 'package:amial_pay/features/transaction_money/screens/transaction_money_screen.dart';
import 'package:amial_pay/features/transaction_money/widgets/share_statement_widget.dart';
import 'package:amial_pay/features/splash/screens/welcome_screen.dart';
import 'package:amial_pay/features/language/screens/change_language_screen.dart';
import 'package:amial_pay/features/onboarding/screens/on_boarding_sceen.dart';
import 'package:amial_pay/features/verification/screens/varification_screen.dart';
// AMIAL-ENTITLEMENTS-ROUTES-001 — this list is the client half of the
// capability manifest served by the backend.  A capability must never be
// advertised as available unless its named route resolves to a real screen.
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/merchant/screens/offline_sales_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_pos_devices_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_dashboard_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_promotions_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_loyalty_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_expenses_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_backup_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_currencies_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_installments_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_api_keys_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_customers_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_ops_center_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_catalog_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_prices_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_locations_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_transfers_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_counts_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_wastes_screen.dart';
import 'package:amial_pay/features/suppliers/screens/suppliers_screen.dart';
import 'package:amial_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amial_pay/features/reports/screens/amial_reports_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_owner_console_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_tanks_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_variances_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_screens.dart';
import 'package:amial_pay/features/restaurant/screens/restaurant_screen.dart';
import 'package:amial_pay/features/corporate/screens/corporate_accounts_screen.dart';

class RouteHelper {
  static const String splash = '/splash';
  static const String home = '/home';
  static const String navbar = '/navbar';
  static const String history = '/history';
  static const String notification = '/notification';
  static const String themeAndLanguage = '/themeAndLanguage';
  static const String profile = '/profile';
  static const String changePinScreen = '/change_pin_screen';
  static const String verifyOtpScreen = '/verify_otp_screen';
  static const String noInternetScreen = '/no_internet_screen';
  static const String sendMoney = '/send_money';
  static const String choseLoginOrRegScreen = '/chose_login_or_reg';
  static const String createAccountScreen = '/create_account';
  static const String verifyScreen = '/verify_account';
  static const String selfieScreen = '/selfie_screen';
  static const String otherInfoScreen = '/other_info_screen';
  static const String pinSetScreen = '/pin_set_screen';
  static const String welcomeScreen = '/welcome_screen';
  static const String loginScreen = '/login_screen';
  static const String fPhoneNumberScreen = '/f_phone_number';
  static const String fVerificationScreen = '/f_verification_screen';
  static const String resetPassScreen = '/f_reset_pass_screen';

  static const String qrCodeScannerScreen = '/qr_code_scanner_screen';
  static const String showWebViewScreen = '/show_web_view_screen';


  // AMIAL-ROUTE-NAME-001 — **مساران فاسدان قِيسا في جدول المسارات.**
  //
  //   كان: '/send_money_balance_inputsend_money_balance_input'  — نصٌّ مكرَّرٌ حرفيّاً
  //   وكان: '/transaction_confirmation_screen.dart'             — اسمُ مسارٍ ينتهي بـ‎.dart
  //
  // ولا يُنتجان خطأً: المسارُ يُسجَّل ويُفتح بالاسم نفسِه، فيعمل. لكنّه
  // يظهر في أيّ رابطٍ عميقٍ أو سجلٍّ أو تحليل — واسمٌ فيه اسمُ ملفٍّ
  // يكشف بنيةَ الشيفرة لمن يقرأ الرابط.
  static const String sendMoneyBalanceInput = '/send_money_balance_input';
  static const String sendMoneyConfirmation = '/transaction_confirmation';

  static const String requestMoney = '/request_money';
  static const String requestMoneyBalanceInput = '/requestMoney_balance_input';
  static const String requestMoneyConfirmation = '/requestMoney_confirmation';

  static const String cashOut = '/cash_out';
  static const String cashOutBalanceInput = '/cash_out_balance_input';
  static const String cashOutConfirmation = '/cash_out_confirmation';

  static const String addMoney = '/add_money';
  static const String addMoneyInput = '/add_money_input';
  static const String bankSelect = '/bank_select';
  static const String bankList = '/bank_listbank_list';
  static const String addMoneySuccessful = '/add_money_successful';
  static const String editProfileScreen = '/edit_profile_screen';
  static const String faq = '/faq';
  static const String aboutUs = '/about_us';
  static const String terms = '/terms';
  static const String privacy = '/privacy_policy';
  static const String requestedMoney = '/requested_money';
  static const String shareStatement = '/share_statement';
  static const String support = '/support';
  static const String choseLanguageScreen = '/chose_language_screen';
  static const String unifiedLoginScreen = '/unified_login';  // AMIAL: دخول أميال باي الموحّد
  static const String qrCodeDownloadOrShare = '/qr_code_download_or_share';

  // Merchant capability manifest routes. Keep these values equal to
  // CapabilityRegistry::screen() in the backend; the contract test checks it.
  static const String quickSale = '/quick-sale';
  static const String cashier = '/cashier';
  static const String offlineSales = '/offline-sales';
  static const String posDevices = '/pos-devices';
  static const String refunds = '/refunds';
  static const String retailReturns = '/retail/returns';
  static const String credit = '/credit';
  static const String products = '/products';
  static const String retailCatalog = '/retail/catalog';
  static const String retailVariants = '/retail/variants';
  static const String retailPrices = '/retail/prices';
  static const String promotions = '/promotions';
  static const String loyalty = '/loyalty';
  static const String retail = '/retail';
  static const String retailLocations = '/retail/locations';
  static const String retailTransfers = '/retail/transfers';
  static const String retailCounts = '/retail/counts';
  static const String retailWastes = '/retail/wastes';
  static const String suppliers = '/suppliers';
  static const String purchaseOrders = '/purchase-orders';
  static const String customers = '/customers';
  static const String staff = '/staff';
  static const String retailRoles = '/retail/roles';
  static const String shifts = '/shifts';
  static const String reportsDaily = '/reports/daily';
  static const String reportsProfit = '/reports/profit';
  static const String reports = '/reports';
  static const String export = '/export';
  static const String expenses = '/expenses';
  static const String auditLog = '/audit-log';
  static const String backup = '/backup';
  static const String branches = '/branches';
  static const String currencies = '/currencies';
  static const String installments = '/installments';
  static const String fuel = '/fuel';
  static const String fuelTanks = '/fuel/tanks';
  static const String fuelVariances = '/fuel/variances';
  static const String pharmacy = '/pharmacy';
  static const String wholesale = '/wholesale';
  static const String restaurant = '/restaurant';
  static const String apiKeys = '/api-keys';
  static const String corporate = '/corporate';

  static String getSplashRoute() => splash;
  static String getHomeRoute(String name) => '$home?name=$name';

  static  String getLoginRoute({required String? countryCode, required String? phoneNumber, required String? userName}) {
    return '$loginScreen?country-code=$countryCode&phone-number=$phoneNumber&user-name=$userName';
  }
  static  String getRegistrationRoute() => createAccountScreen;
  static  String getVerifyRoute({String? phoneNumber}) => '$verifyScreen?phone_number=${Uri.encodeComponent(phoneNumber ?? 'null')}';

  static String getWelcomeRoute({String? countryCode,String? phoneNumber, String? password}) {
    return '$welcomeScreen?country-code=$countryCode&phone-number=$phoneNumber&password=$password';
  }
  static  String getSelfieRoute({required bool fromEditProfile}) => '$selfieScreen?page=${fromEditProfile?'edit-profile':'verify'}';
  static  String getNavBarRoute({String? selectedPage}) => (selectedPage?.isNotEmpty ?? false) ? '$navbar?selectedPage=$selectedPage' : navbar;
  static  String getOtherInformationRoute() => otherInfoScreen;
  static  String getPinSetRoute({required SignUpBodyModel signUpBody}) {
    String signUpData =  base64Url.encode(utf8.encode(jsonEncode(signUpBody.toJson())));
    return '$pinSetScreen?signup=$signUpData';
  }
  static  String getRequestMoneyRoute({String? phoneNumber,required bool fromEdit}) => '$requestMoney?phone-number=$phoneNumber&from-edit=${fromEdit?'edit-number':'home'}';
  static String  getForgetPassRoute({required String? countryCode, required String phoneNumber}) => '$fPhoneNumberScreen?country-code=$countryCode&phone-number=$phoneNumber';
  static String  getRequestMoneyBalanceInputRoute() => requestMoneyBalanceInput;
  static String  getRequestMoneyConfirmationRoute({required String inputBalanceText}) => '$requestMoneyConfirmation?input-balance=$inputBalanceText';
  static String  getNoInternetRoute() => noInternetScreen;
  static String  getChoseLoginRegRoute() => choseLoginOrRegScreen;
  static String  getSendMoneyRoute({String? phoneNumber,required bool fromEdit}) => '$sendMoney?phone-number=$phoneNumber&from-edit=${fromEdit?'edit-number':'home'}';
  static String  getSendMoneyInputRoute({required String transactionType}) => '$sendMoneyBalanceInput?transaction-type=$transactionType';
  static String  getSendMoneyConfirmationRoute({required String inputBalanceText,required String transactionType}) => '$sendMoneyConfirmation?input-balance=$inputBalanceText&transaction-type=$transactionType';
  static String  getChoseLanguageRoute() => choseLanguageScreen;
  static String  getUnifiedLoginRoute() => unifiedLoginScreen;  // AMIAL
  static String  getCashOutScreenRoute({String? phoneNumber,required bool fromEdit}) => '$cashOut?phone-number=$phoneNumber&from-edit=${fromEdit?'edit-number':'home'}';
  static String  getCashOutBalanceInputRoute() => cashOutBalanceInput;
  static String  getFResetPassRoute({String? phoneNumber, String? otp}) => '$resetPassScreen?phone-number=$phoneNumber&otp=$otp';
  static String  getEditProfileRoute() => editProfileScreen;
  static String  getChangePinRoute() => changePinScreen;
  static String  getAddMoneyInputRoute() => addMoneyInput;
  // static  getFVerificationRoute({required String phoneNumber}) => '$fVerificationScreen?phone-number=$phoneNumber';

  static String getSupportRoute() => support;
  static String getCashOutConfirmationRoute({required String inputBalanceText}) => '$cashOutConfirmation?input-balance=$inputBalanceText';
  static String  getShareStatementRoute({ required String amount,  required String transactionType, required ContactModel contactModel}) {
    String data =  base64Url.encode(utf8.encode(jsonEncode(contactModel.toJson())));
    String transactionType0 = base64Url.encode(utf8.encode(transactionType));
    return '$shareStatement?amount=$amount&transaction-type=$transactionType0&contact=$data';
  }
  static String getQrCodeDownloadOrShareRoute({required String qrCode, required String phoneNumber}) {
    String qrCode0 = base64Url.encode(utf8.encode(qrCode));
    String phoneNumber0 = base64Url.encode(utf8.encode(phoneNumber));

    return '$qrCodeDownloadOrShare?qr-code=$qrCode0&phone-number=$phoneNumber0';
  }


  static List<GetPage> routes = [
    GetPage(name: splash, page: () => const SplashScreen()),
    GetPage(name: home, page: () => const HomeDispatcherScreen(userHomeFallback: NavBarScreen())),
    GetPage(name: navbar, page: () =>  NavBarScreen(
      selectedPage: (Get.parameters['selectedPage']?.isNotEmpty ?? false) ? Get.parameters['selectedPage'] : null,
    )),
    GetPage(name: shareStatement, page: () => ShareStatementWidget(amount: Get.parameters['amount'], charge: null, trxId: null,
            transactionType: utf8.decode(base64Url.decode(Get.parameters['transaction-type']!.replaceAll(' ', '+'))), contactModel: ContactModel.fromJson(jsonDecode(utf8.decode(base64Url.decode(Get.parameters['contact']!)))))),

    GetPage(name: notification, page: () => const NotificationsCenterScreen()),
    // GetPage(name: themeAndLanguage, page: () => ThemeAndLanguage()),
    GetPage(name: profile, page: () => const ProfileScreen()),
    GetPage(name: changePinScreen, page: () => const ChangePinScreen()),
    GetPage(name: sendMoney, page: () => TransactionMoneyScreen(phoneNumber: Get.parameters['phone-number'],fromEdit: Get.parameters['from-edit']== 'edit-number')),
    GetPage(name: sendMoneyBalanceInput, page: () => TransactionBalanceInputScreen(transactionType: Get.parameters['transaction-type'])),
    GetPage(name: sendMoneyConfirmation, page: () => TransactionConfirmationScreen(inputBalance:double.tryParse(Get.parameters['input-balance']!),transactionType: Get.parameters['transaction-type'])),

    GetPage(name: choseLoginOrRegScreen, page: () => const OnBoardingScreen()),
    GetPage(name: unifiedLoginScreen, page: () => const UnifiedLoginScreen()),  // AMIAL
    GetPage(name: verifyScreen, page: () {
      final String? phoneNumber = Uri.decodeComponent(Get.parameters['phone_number']!)
          != 'null' ? Uri.decodeComponent(Get.parameters['phone_number']!) : null ;
      return VerificationScreen(
        phoneNumber: phoneNumber,
      );
    }),
    GetPage(name: selfieScreen, page: () => CameraScreen(fromEditProfile: Get.parameters['page'] == 'edit-profile')),
    GetPage(name: otherInfoScreen, page: () => const AmialRegistrationWizardScreen()),
    GetPage(name: pinSetScreen, page: () => PinSetScreen(
      signUpBody: SignUpBodyModel.fromJson(jsonDecode(utf8.decode(base64Url.decode(Get.parameters['signup']!)))),
    )),

    GetPage(name: welcomeScreen, page: () => WelcomeScreen(
      countryCode: Get.parameters['country-code']!.replaceAll(' ', '+'),
      phoneNumber: Get.parameters['phone-number'],
      password: Get.parameters['password'],
    )),

    GetPage(name: fPhoneNumberScreen, page: () => ForgetPinScreen(countryCode: Get.parameters['country-code']!.replaceAll(' ', '+'),phoneNumber: Get.parameters['phone-number'],)),
    // GetPage(name: fVerificationScreen, page: () => PhoneVerification(phoneNumber: Get.parameters['phone-number']!.replaceAll(' ', '+'),)),
    GetPage(name: resetPassScreen, page: () => ResetPinScreen(
      phoneNumber: Get.parameters['phone-number']!.replaceAll(' ', '+'),
      otp: Get.parameters['otp']!.replaceAll(' ', '+'),
    )),
    GetPage(name: choseLanguageScreen, page: () => const ChooseLanguageScreen()),
    GetPage(name: editProfileScreen, page: () => const EditProfileScreen()),
    GetPage(name: faq, page: () => FaqScreen(title: 'faq'.tr)),
    GetPage(name: terms, page: () => HtmlViewScreen(title: 'terms'.tr, url: Get.find<SplashController>().configModel!.termsAndConditions)),
    GetPage(name: aboutUs, page: () => HtmlViewScreen(title: 'about_us'.tr, url: Get.find<SplashController>().configModel!.aboutUs)),
    GetPage(name: privacy, page: () => HtmlViewScreen(title: 'privacy_policy'.tr, url: Get.find<SplashController>().configModel!.privacyPolicy)),
    GetPage(name: support, page: () => const SupportScreen()),
    GetPage(name: qrCodeDownloadOrShare, page: () => QrCodeDownloadOrShareScreen(qrCode:  utf8.decode(base64Url.decode(Get.parameters['qr-code']!.replaceAll(' ', '+'))),
        phoneNumber: utf8.decode(base64Url.decode(Get.parameters['phone-number']!.replaceAll(' ', '+'))),)),

    // The named entries below are deliberately explicit.  The backend returns
    // these paths in its capability manifest, so an available card can always
    // open a concrete workflow rather than a generic placeholder or a dead end.
    GetPage(name: quickSale, page: () => const CashierPosScreen()),
    GetPage(name: cashier, page: () => const CashierPosScreen()),
    GetPage(name: offlineSales, page: () => const OfflineSalesScreen()),
    GetPage(name: posDevices, page: () => const MerchantPosDevicesScreen()),
    GetPage(name: refunds, page: () => const MerchantRefundScreen()),
    GetPage(name: retailReturns, page: () => const MerchantRefundScreen()),
    GetPage(name: credit, page: () => const CreditDashboardScreen()),
    GetPage(name: products, page: () => const CashierProductsScreen()),
    GetPage(name: retailCatalog, page: () => const RetailCatalogScreen()),
    // Variants are edited from the product catalogue, never from an orphaned
    // empty editor that has no selected product.
    GetPage(name: retailVariants, page: () => const CashierProductsScreen()),
    GetPage(name: retailPrices, page: () => const RetailPricesScreen()),
    GetPage(name: promotions, page: () => const MerchantPromotionsScreen()),
    GetPage(name: loyalty, page: () => const MerchantLoyaltyScreen()),
    GetPage(name: retail, page: () => const RetailOpsCenterScreen()),
    GetPage(name: retailLocations, page: () => const RetailLocationsScreen()),
    GetPage(name: retailTransfers, page: () => const RetailTransfersScreen()),
    GetPage(name: retailCounts, page: () => const RetailCountsScreen()),
    GetPage(name: retailWastes, page: () => const RetailWastesScreen()),
    // Suppliers contains both supplier and purchase-order tabs; using one
    // operational hub avoids a misleading, duplicate purchase-order screen.
    GetPage(name: suppliers, page: () => const SuppliersScreen()),
    GetPage(name: purchaseOrders, page: () => const SuppliersScreen()),
    GetPage(name: customers, page: () => const CreditCustomersScreen()),
    GetPage(name: staff, page: () => const MerchantStaffScreen()),
    // Staff is the current operational role-assignment surface.  It includes
    // role controls per employee, so permissions do not lead to a faux screen.
    GetPage(name: retailRoles, page: () => const MerchantStaffScreen()),
    GetPage(name: shifts, page: () => const CashierShiftScreen()),
    GetPage(name: reportsDaily, page: () => const CashierReportScreen()),
    GetPage(name: reportsProfit, page: () => const FinancialTruthReportScreen()),
    GetPage(name: reports, page: () => const AmialReportsScreen()),
    GetPage(name: export, page: () => const MerchantExcelExportScreen()),
    GetPage(name: expenses, page: () => const MerchantExpensesScreen()),
    GetPage(name: auditLog, page: () => const MerchantAuditLogScreen()),
    GetPage(name: backup, page: () => const MerchantBackupScreen()),
    GetPage(name: branches, page: () => const BranchesManagementScreen()),
    GetPage(name: currencies, page: () => const MerchantCurrenciesScreen()),
    GetPage(name: installments, page: () => const MerchantInstallmentsScreen()),
    GetPage(name: fuel, page: () => const FuelOwnerConsoleScreen()),
    GetPage(name: fuelTanks, page: () => const FuelTanksScreen()),
    GetPage(name: fuelVariances, page: () => const FuelVariancesScreen()),
    GetPage(name: pharmacy, page: () => const PharmacyDashboardScreen()),
    GetPage(name: wholesale, page: () => const WholesaleDashboardScreen()),
    GetPage(name: restaurant, page: () => const RestaurantScreen()),
    GetPage(name: apiKeys, page: () => const MerchantApiKeysScreen()),
    GetPage(name: corporate, page: () => const CorporateAccountsScreen()),

    ];

}
