# دليل البنود المؤجّلة — للمطوّرين
### Deferred Work Technical Guide — أميال باي

**السياق:** بعد تدقيق ما قبل الإنتاج (`PRODUCTION_READINESS_AUDIT.md`)، نُفِّذت كل البنود الحرجة والعالية والمتوسطة عالية القيمة. هذا المستند يشرح **البنود المؤجّلة عمداً**: لماذا أُجّلت، وكيف تُنفَّذ خطوة بخطوة عندما يحين وقتها.

**قاعدة عامة:** لا شيء هنا يمنع التشغيل. كلها إمّا **refactor عالي الخطورة بلا فائدة وظيفية** (يُؤجَّل لِما بعد التجربة المغلقة)، أو **قرار منتج/UX**، أو **تجميلي**.

---

## M-2 — تقسيم الملفات الضخمة (أولوية: بعد التجربة)

### لماذا مؤجّل؟
تقسيم ملفٍ يعمل ويُغطّيه اختبار = **مخاطرة بلا عائد وظيفي**. قيمة التقسيم هي قابلية الصيانة طويلة المدى، لا سلوك المستخدم. قبل التجربة المغلقة، أي لمسة لمحرّك المال تفتح باب انحدار (regression). لذا يُنفَّذ **بعد** استقرار التجربة، بحذر، مع تشغيل كامل الاختبارات بعد كل خطوة.

### القاعدة الذهبية للتقسيم الآمن
1. لا تغيّر أي منطق — **نقل فقط** (extract).
2. خطوة واحدة = ملف واحد، ثم `php artisan test` كامل (يجب أن يبقى أخضر).
3. استخدم Traits/خدمات مساعدة — لا تكسر التواقيع العامة (public signatures) التي تناديها الـControllers.

### الملفّات وخطط التقسيم المقترحة

#### 1. `app/Traits/TransactionTrait.php` (1303 سطراً) — الأولوية القصوى
يحوي كل العمليات المالية في trait واحد. حدود التقسيم الطبيعية **جاهزة** (كل دالة عامة مستقلّة):

| الدوال العامة | تُنقل إلى Trait جديد |
|---|---|
| `customer_send_money_transaction`, `send_money_with_fee_engine` | `Concerns/HandlesSendMoney.php` |
| `customer_cash_out_transaction`, `cash_out_with_fee_engine`, `accept_withdraw_transaction` | `Concerns/HandlesCashOut.php` |
| `merchant_payment_transaction` | `Concerns/HandlesMerchantPayment.php` |
| `customer_request_money_transaction`, `cash_in_transaction` | `Concerns/HandlesCashIn.php` |
| `disputeTransaction` | `Concerns/HandlesDisputes.php` |
| المساعدات (`guard`, `audit`, `assertFinancialEligibility`, `screenAml`, `recordTransaction`, `newTransactionId`, `safeIssueReceipts`) | تبقى في `TransactionTrait` الأساس، وتستعملها بقية الـTraits |

**التنفيذ:** أنشئ الـTraits الخمسة، انقل كل مجموعة دوال كما هي، ثم في `TransactionTrait` استعملها:
```php
trait TransactionTrait {
    use Concerns\HandlesSendMoney, Concerns\HandlesCashOut,
        Concerns\HandlesMerchantPayment, Concerns\HandlesCashIn,
        Concerns\HandlesDisputes;
    // تبقى هنا المساعدات المشتركة فقط
}
```
كل مستهلكي `TransactionTrait` يعملون بلا تغيير (نفس الدوال، نفس التواقيع). شغّل الاختبارات بعد كل Trait.

#### 2. `app/Services/Whatsapp/WhatsappBotService.php` (919 سطراً، 41 دالة)
قسّم حسب المسؤولية: `WhatsappTransferFlow` (تدفّق التحويل)، `WhatsappQueryFlow` (استعلامات الرصيد/الإيصالات)، `WhatsappOnboardingFlow` (الربط والتسجيل). الخدمة الأساس تفوّض إليها.

#### 3. `app/CentralLogics/Helpers.php` (918 سطراً، 43 دالة) — إرث 6cash
دالة ثابتة عملاقة (God object). قسّم حسب الموضوع: `Support/BusinessSettings.php`, `Support/CurrencyFormat.php`, `Support/UserHelpers.php`. **حذّر:** مُستهلَك في أماكن كثيرة — كل نقل يتطلب تحديث الاستدعاءات. الأخطر في القائمة، أجّله للأخير.

#### 4. `app/Http/Controllers/Api/V1/Amial/SupportConsoleController.php` (858 سطراً، 15 دالة)
هذا **بنيناه حديثاً** (منصة العمليات). قسّمه بـtraits حسب التبويب: `Concerns/HandlesSupportSearch`, `HandlesApprovals`, `HandlesTickets`, `HandlesInsiderWatch`. سهل ومنخفض الخطورة نسبياً.

#### 5. `app/Services/SafePaymentService.php` (802 سطراً)
افصل منطق دورة الحياة (تجميد/إفراج/استرجاع/نزاع) عن الاستعلامات.

**بقية الملفّات >600 سطر** (تُقسَّم عند لمسها فقط، لا داعي لحملة مخصّصة):
`LandingPageSettingsController` (785)، `BusinessSettingsController` (772)، `Customer/TransactionController` (701)، `WholesaleController` (657)، `FuelStationController` (637)، `SmsGateway` (618).

---

## H-3 (المتبقّي) — شاشات Flutter غير الموصولة

نُفِّذ منها: `SplitBillCreateScreen` + `SplitBillMySharesScreen` (وُصِلتا بلوحة التاجر). التالي مؤجّل:

### 1. `UnifiedLoginScreen` — قرار منتج (ليست ميتة!)
`lib/features/auth/screens/unified_login_screen.dart` — شاشة دخول موحّدة لكل الأدوار (عميل/تاجر/وكيل) لها **باكند كامل**: `routes/api/unified-auth.php` + `unified_auth_controller.dart`. المشروع حالياً يستخدم `LoginScreen` القديمة.

**القرار المطلوب من المنتج:**
- **إن اعتُمدت:** استبدل في `lib/helper/route_helper.dart` السطر ~181 `page: () => LoginScreen(...)` بـ `UnifiedLoginScreen()`، واختبر تدفّق الأدوار الثلاثة على جهاز.
- **إن رُفضت:** احذف الشاشة + `unified_auth_controller` + `unified-auth.php` (وإلا تبقى مساراً معرّضاً بلا واجهة).

**لا تُحذف قبل القرار** — حذف ميزة لها باكند خطأ.

### 2. `DonationsHomeScreen` — يحتاج مدخلاً في شبكة خدمات العميل
`lib/features/donations/screens/donations_home_screen.dart` (بلا معطيات). الباكند جاهز (`DonationsController`).
**التنفيذ:** أضِف بطاقة "التبرّعات" في شبكة خدمات الشاشة الرئيسية للعميل، ثم:
```dart
onTap: () => Get.to(() => const DonationsHomeScreen()),
```
**ملاحظة:** شبكة الخدمات ليست في `home_screen.dart` مباشرة — ابحث عن widget الخدمات المستعمَل في `nav_bar_screen.dart`.

### 3. `MerchantPayScreen` — جانب العميل (دفع لتاجر)
`lib/features/merchant/screens/merchant_pay_screen.dart` — كل معطياته اختيارية (`prefillMerchantPhone`, `merchantUserId`, `channel='qr'`, `posUserId`)، فيُستدعى `const MerchantPayScreen()`. المدخل الطبيعي: بعد **مسح QR الخاص بالتاجر** أو من زر "ادفع لتاجر" في خدمات العميل.
**التنفيذ:** في نتيجة ماسح QR (حيث يُحلَّل رمز التاجر)، وجّه إلى:
```dart
Get.to(() => MerchantPayScreen(merchantUserId: scannedId, channel: 'qr'));
```

### 4. `CashierRefundScreen` — يحتاج شاشة تفاصيل بيع أولاً
`lib/features/merchant/screens/cashier_refund_screen.dart` — **يتطلّب `saleUlid`** (`required this.saleUlid`). لا يمكن فتحه إلا من صفٍّ في **قائمة مبيعات**، وهي غير موجودة حالياً (`cashier_report_screen` يعرض إجماليات فقط لا قائمة مبيعات بمعرّفاتها).
**التنفيذ (خطوتان):**
1. أنشئ قائمة مبيعات في `cashier_report_screen` (أو شاشة جديدة) تعرض كل عملية بـ`ulid`.
2. من كل صف: `Get.to(() => CashierRefundScreen(saleUlid: sale.ulid))`.
**الأولوية:** منخفضة — يوجد بالفعل `MerchantRefundScreen` عام موصول باللوحة يغطّي الاسترجاع الأساسي.

**تنبيه اختبار:** كل تغييرات Flutter لا تُختبر إلا بـ`flutter analyze` (تحقّق ترجمة) + **تشغيل فعلي على جهاز/محاكي**. لا يوجد اختبار تشغيل آلي في هذه البيئة.

---

## M-1 — `google-services.json` (مقبول، توثيق فقط)

`02_flutter_app/android/app/google-services.json` مرفوع في المستودع. **هذا مقبول** — إنه تهيئة عميل Firebase للأندرويد (مفتاح API عميل مقيّد ببصمة SHA-256 لتطبيقك، لا يُستغَل منفرداً). ممارسة قياسية.

**تحسين اختياري (إن طلب البنك):** مرّره عبر متغيّرات بناء CI بدل التتبّع:
1. احذفه من Git وأضِفه لـ`.gitignore`.
2. في CI، أنشئه من سرّ مُخزَّن (GitHub Secret) قبل البناء.
لا حاجة له للتجربة المغلقة.

---

## البنود البسيطة (L) — تجميلية

### L-1: تعليق TODO متبقٍّ (1)
`app/Services/ZoneAssignmentService.php` — راجع التعليق ونفّذه أو احذفه.

### L-2: تحذيرات PHPUnit (توثيق الاختبارات)
الاختبارات تستخدم `/** @test */` في تعليقات doc، وهو مهجور في PHPUnit 12.
**التنفيذ:** استبدل بـ Attributes:
```php
use PHPUnit\Framework\Attributes\Test;
#[Test]
public function it_does_something(): void { ... }
```
أو أعِد تسمية الدوال بادئة `test_`. تجميلي بحت — لا يؤثّر على النتائج.

### L-3: 17 اختباراً "risky"
اختبارات لا تُنفّذ تأكيداً صريحاً (assertion) أو تطبع مخرجات — لا تفشل لكن PHPUnit يعلّمها. راجعها وأضِف تأكيداً أو `$this->expectNotToPerformAssertions()` صراحةً حيث يكون ذلك مقصوداً.

### L-4: اعتماد الاختبارات على MariaDB
الاختبارات تحتاج تشغيل MariaDB. فشل عابر يعني إعادة تشغيل الخدمة، لا خطأ كود. **بيئي.**

---

## الأصناف الثلاثة المتبقّية (AML — تُترك عمداً)

`AmlRulesCacheService`, `CacheService`, `VelocityCounterService` — مساعدات أداء لمحرّك AML (تخزين مؤقّت للقواعد وعدّ السرعة) **ليست على المسار الحالي** للمحرّك (`AmlScreeningService` يقرأ القواعد مباشرة). **تُترك** لأنها:
1. صغيرة وغير ضارة.
2. قد تُوصَل كتحسين أداء عند نمو الأحمال (تخزين القواعد بدل استعلام كل معاملة).

**قرار مستقبلي:** إمّا وصلها بـ`AmlScreeningService::getApplicableRules()` (لأداء أفضل)، أو حذفها إن استقرّ القرار على القراءة المباشرة.

---

## خلاصة الأولويات للمطوّر القادم

| متى | ماذا |
|---|---|
| **قبل التجربة** | لا شيء — كل الحرِج مُنفَّذ |
| **أثناء/بعد التجربة** | قرار المنتج حول `UnifiedLoginScreen`؛ وصل شاشات العميل المؤجّلة |
| **بعد استقرار التجربة** | تقسيم الملفّات الضخمة (M-2) بالترتيب: SupportConsole → SafePayment → WhatsappBot → TransactionTrait → Helpers |
| **عند طلب البنك** | نقل `google-services.json` لـCI؛ تنظيف اختبارات risky |
| **عند نمو الأحمال** | وصل مساعدات AML للأداء |
