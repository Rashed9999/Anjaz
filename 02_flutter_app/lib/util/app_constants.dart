import 'package:amial_pay/common/models/language_model.dart';
import 'images.dart';

class AppConstants {
  // AMIAL-BRANDING-001
  static const String appName = 'Amial Pay';
  static const String appNameAr = 'أميال باي';
  static const String appTagline = 'دفع سريع وآمن';
  // ══════════════════════════════════════════════════════════════════
  // AMIAL-DOMAIN-001 — عنوان الخادم: **موضعٌ واحد في التطبيق كلّه.**
  //
  // مرّره وقت البناء دون تعديل الشيفرة:
  //   flutter build apk --dart-define=BASE_URL=https://amialpay.com
  //
  // **وقد كان الافتراضيّ عنواناً رقميّاً على HTTP.**
  //
  // بشرطٍ مكتوبٍ هنا: يبقى على ما يعمل الآن، ويتحوّل «يوم يصير النطاق
  // جاهزاً — لا قبله». والشرط تحقّق: النطاق يترجم، والشهادة تعمل،
  // ولوحتا الإدارة والوكيل تُفتحان عليه.
  //
  // فصار الافتراضيّ النطاق. وأثرُه أنّ **نسخة الإصدار صارت تُبنى وتعمل**:
  // كانت تمنع الاتّصال غير المشفَّر (عمداً) فتفشل على العنوان القديم في
  // كلّ شاشة. ولم يعد في الشيفرة عنوانٌ رقميّ أصلاً.
  //
  // ولمن أراد خادماً آخر (تجربةٌ محلّيّة مثلاً) — بلا تعديل شيفرة:
  //   flutter run --dart-define=BASE_URL=http://192.168.1.5:8000
  //
  // خطواتُ التحوّل بالترتيب في: docs/التحوّل-إلى-النطاق.md
  static const String productionDomain = 'https://amialpay.com';

  static const String baseUrl =
      String.fromEnvironment('BASE_URL', defaultValue: productionDomain);

  /// أهو اتّصالٌ مشفَّر؟
  ///
  /// **يُقاس من العنوان نفسه لا من رايةٍ منفصلة.** فرايةٌ تُضبط بيدٍ
  /// تكذب أوّل ما يُنسى تحديثُها — وهذه تصدق دائماً.
  static bool get isSecureBackend => baseUrl.startsWith('https://');
  static const bool demo = false;
  static const double appVersion = 0.7; //flutter version 3.38.5
  static const String customerPhoneCheckUri = '/api/v1/customer/auth/check-phone';
  static const String customerPhoneVerifyUri = '/api/v1/customer/auth/verify-phone';
  static const String customerRegistrationUri = '/api/v1/customer/auth/register';
  static const String customerUpdateProfile = '/api/v1/customer/update-profile';
  static const String customerLoginUri = '/api/v1/customer/auth/login';
  static const String customerLogoutUri = '/api/v1/customer/logout';
  static const String customerForgetPassOtpUri = '/api/v1/customer/auth/forgot-password';
  static const String customerForgetPassVerification = '/api/v1/customer/auth/verify-token';
  static const String customerForgetPassReset = '/api/v1/customer/auth/reset-password';
  static const String customerLinkedWebsite= '/api/v1/customer/linked-website';
  static const String customerBanner= '/api/v1/customer/get-banner';
  static const String customerTransactionHistory= '/api/v1/customer/transaction-history';
  static const String customerTransactionHistoryDownload= '/api/v1/customer/transaction/download-pdf';
  static const String customerPurposeUrl = '/api/v1/customer/get-purpose';
  static const String configUri = '/api/v1/config';
  static const String imageConfigUrlApiNeed = '/storage/app/public/purpose/';
  static const String customerProfileInfo = '/api/v1/customer/get-customer';
  static const String customerCheckOtp = '/api/v1/customer/check-otp';
  static const String customerVerifyOtp = '/api/v1/customer/verify-otp';
  static const String customerChangePin = '/api/v1/customer/change-pin';
  static const String customerUpdateTwoFactor = '/api/v1/customer/update-two-factor';
  static const String customerSendMoney = '/api/v1/customer/send-money';
  static const String customerRequestMoney = '/api/v1/customer/request-money';
  static const String customerCashOut = '/api/v1/customer/cash-out';
  static const String customerPinVerify = '/api/v1/customer/verify-pin';
  static const String customerAddMoney = '/api/v1/customer/add-money';
  static const String faqUri = '/api/v1/faq';
  static const String faqCategoryUri = '/api/v1/faq/category';
  static const String notificationUri = '/api/v1/customer/get-notification';
  static const String requestedMoneyUri = '/api/v1/customer/get-requested-money';
  static const String acceptedRequestedMoneyUri = '/api/v1/customer/request-money/approve';
  static const String deniedRequestedMoneyUri = '/api/v1/customer/request-money/deny';
  static const String tokenUri = '/api/v1/customer/update-fcm-token';
  static const String checkCustomerUri = '/api/v1/check-customer';
  static const String checkAgentUri = '/api/v1/check-agent';
  static const String wonRequestedMoney = '/api/v1/customer/get-own-requested-money';
  static const String customerRemove = '/api/v1/customer/remove-account';
  static const String updateKycInformation = '/api/v1/customer/update-kyc-information';
  static const String withdrawMethodList = '/api/v1/customer/withdrawal-methods';
  static const String withdrawRequest = '/api/v1/customer/withdraw';
  static const String getWithdrawalRequest = '/api/v1/customer/withdrawal-requests';
  //fav number
  static const String addFavouriteNumber = '/api/v1/customer/favourite-number/store';
  static const String updateFavouriteNumber = '/api/v1/customer/favourite-number/update';
  static const String deleteFavouriteNumber = '/api/v1/customer/favourite-number/delete';
  static const String getFavouriteNumberList = '/api/v1/customer/favourite-number/list';

  //report dispute
  static const String reportReasonList = '/api/v1/customer/dispute/reason/list';
  static const String createReportDispute = '/api/v1/customer/dispute/create';

  // ============================================================
  // AMIAL Endpoints (v0.7-A backend)
  // ============================================================

  // Zone Policy
  static const String amialPolicySession = '/api/v1/amial/policy/session';

  // Legal Terms
  static const String amialLegalStatus = '/api/v1/amial/legal/status';
  static const String amialLegalCurrent = '/api/v1/amial/legal/current';
  static const String amialLegalAccept = '/api/v1/amial/legal/accept';

  // Account Recovery
  static const String amialRecoveryInitiateSelf = '/api/v1/amial/recovery/initiate-self';
  static const String amialRecoveryInitiateLost = '/api/v1/amial/recovery/initiate-lost';
  static const String amialRecoveryVerifyOtp = '/api/v1/amial/recovery/'; // + {ulid}/verify-otp
  static const String amialRecoveryComplete = '/api/v1/amial/recovery/';  // + {ulid}/complete
  static const String amialRecoveryShow = '/api/v1/amial/recovery/';      // + {ulid}

  // ============================================================
  // AMIAL v0.9 Endpoints
  // ============================================================

  // AMIAL-RECEIPTS-001
  static const String amialReceiptsList = '/api/v1/amial/receipts';
  static const String amialReceiptShow = '/api/v1/amial/receipts/';        // + {id}
  static const String amialReceiptDownload = '/api/v1/amial/receipts/';    // + {id}/download
  static const String amialReceiptVerifyPublic = '/v/';                    // + {code}

  // AMIAL-FUND-FAMILY-001
  static const String amialFundsList = '/api/v1/amial/funds';
  static const String amialFundsCreate = '/api/v1/amial/funds';
  static const String amialFundShow = '/api/v1/amial/funds/';              // + {ulid}
  static const String amialFundInvite = '/api/v1/amial/funds/';            // + {ulid}/invite
  static const String amialFundContribute = '/api/v1/amial/funds/';        // + {ulid}/contribute
  static const String amialFundPropose = '/api/v1/amial/funds/';           // + {ulid}/propose-disbursement
  static const String amialFundTransactions = '/api/v1/amial/funds/';      // + {ulid}/transactions
  static const String amialFundApproveDisb = '/api/v1/amial/funds/disbursements/';  // + {ulid}/approve
  static const String amialFundRejectDisb = '/api/v1/amial/funds/disbursements/';   // + {ulid}/reject
  static const String amialFundAcceptInvite = '/api/v1/amial/funds/memberships/';   // + {id}/accept

  // AMIAL-BILL-PAY-001
  static const String amialBillProviders = '/api/v1/amial/bill-pay/providers';
  static const String amialBillProducts = '/api/v1/amial/bill-pay/services/';       // + {id}/products
  static const String amialBillPay = '/api/v1/amial/bill-pay/pay';
  static const String amialBillOrders = '/api/v1/amial/bill-pay/orders';
  static const String amialBillOrderShow = '/api/v1/amial/bill-pay/orders/';        // + {ulid}

  // AMIAL-SAFE-PAYMENT-001 (v1.1)
  static const String amialSafePayments = '/api/v1/amial/safe-payments';
  static const String amialSafePaymentShow = '/api/v1/amial/safe-payments/';        // + {ulid}
  static const String amialSafePaymentSellerAccept = '/api/v1/amial/safe-payments/';// + {ulid}/seller-accept
  static const String amialSafePaymentSellerReject = '/api/v1/amial/safe-payments/';// + {ulid}/seller-reject
  static const String amialSafePaymentSellerInDelivery = '/api/v1/amial/safe-payments/';
  static const String amialSafePaymentSellerDelivered = '/api/v1/amial/safe-payments/';
  static const String amialSafePaymentBuyerConfirm = '/api/v1/amial/safe-payments/';
  static const String amialSafePaymentBuyerCancel = '/api/v1/amial/safe-payments/';
  static const String amialSafePaymentBuyerDispute = '/api/v1/amial/safe-payments/';

  // AMIAL-DONATIONS-001 (v1.2)
  static const String amialDonationsCategories = '/api/v1/amial/donations/categories';
  static const String amialDonationsOrgs = '/api/v1/amial/donations/organizations';
  static const String amialDonationsCampaigns = '/api/v1/amial/donations/campaigns';
  static const String amialDonationCampaignShow = '/api/v1/amial/donations/campaigns/'; // + {ulid}
  static const String amialDonationsDonate = '/api/v1/amial/donations/donate';
  static const String amialDonationsMy = '/api/v1/amial/donations/my-donations';


  // Shared Key
  static const String theme = 'theme';
  static const String token = 'token';
  static const String customerCountryCode = 'customer_country_code';//not in project
  static const String languageCode = 'language_code';
  static const String topic = 'notify';

  static const String sendMoneySuggestList = 'send_money_suggest';
  static const String requestMoneySuggestList = 'request_money_suggest';
  static const String recentAgentList = 'recent_agent_list';

  static const String pending = 'pending';
  static const String approved = 'approved';
  static const String denied = 'denied';
  static const String cashIn = 'cash_in';
  static const String cashOut = 'cash_out';
  static const String sendMoney = 'send_money';
  static const String receivedMoney = 'received_money';
  static const String adminCharge = 'admin_charge';
  static const String addMoney = 'add_money';
  static const String withdraw = 'withdraw';
  static const String payment = 'payment';
  static const String deductDisputedMoney = 'deducted_dispute_money';
  static const String addDisputedMoney = 'added_dispute_money';

  static const String biometricAuth = 'biometric_auth';
  static const String biometricPin = 'biometric';
  static const String hideUserBalance = 'hide_balance';
  static const String contactPermission = '';
  static const String userData = 'user';
  static const String showTourWidget = 'show_tour';
  static const String showWelcomeBottomSheet = 'welcome_bottom_sheet';
  static const String favNumberListKey = 'favourite_number_list';
  static const String contactPermissionDeniedStatus = 'contact_permission_denied_status';



  //topic
  static const String all = 'all';
  static const String users = 'customers';

  // App Theme
  static const String theme1 = 'theme_1';
  static const String theme2 = 'theme_2';
  static const String theme3 = 'theme_3';

  //input balance digit length
  static const int balanceInputLen = 10;
  static const int balanceHideDurationInSecond = 3;
  static const int dynamicDecimalPoint = 2;


  static List<LanguageModel> languages = [
    // العربية هي اللغة الأساسية الافتراضية (AMIAL-I18N-001)
    LanguageModel(imageUrl: Images.arabic, languageName: 'العربية', countryCode: 'SA', languageCode: 'ar'),
    LanguageModel(imageUrl: Images.english, languageName: 'English', countryCode: 'US', languageCode: 'en'),
  ];

  static const List<String> transactionTypeList = ['both','credit', 'debit'];
  static const List<String> filterDateRangeList = ['this_week', 'last_7_days', 'last_15_days', 'this_month', 'last_30_days', 'last_60_days', 'this_year', 'last_year', 'custom'];

  /// Allowed image file extensions for upload
  static const List<String> allowedImageExtensions = [
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
  ];

  /// Default image quality for image picker (0-100)
  static const int defaultImageQuality = 80;

}
