# سجل التغييرات — v0.9-D (إغلاق + Flutter UI + Tests)

**التاريخ:** 2026-05-17
**النطاق:**
- إغلاق ثغرات v0.9 الباطنية (Receipt integration, Bill Pay reconcile, Seeders)
- Flutter UI لكل ميزات v0.9 (Receipts + Family Fund + Bill Pay)
- اختبارات Feature شاملة لكل ميزات v0.9

---

## المرحلة 1 — Backend Gap Closure

### 1.1) Receipt Integration في TransactionTrait
- `customer_send_money_transaction` يصدر إيصالاً مزدوجاً بعد commit
- `customer_cash_out_transaction` يصدر إيصال debit للعميل
- helpers جديدة: `safeIssueReceipts` و `safeIssueSingleReceipt` (لا ترمي exceptions تفشل العملية)

### 1.2) Bill Pay Reconcile
| الملف | الغرض |
|---|---|
| `app/Jobs/ReconcilePendingBillOrdersJob.php` | Job يفحص الـ pending_provider_confirmation orders |
| `app/Console/Commands/ReconcileBillPayCommand.php` | `php artisan amial:bill-pay:reconcile` |
| `routes/console.php` | Schedule يشغل الـ Job كل دقيقة |

### 1.3) Bill Providers Seeder (للاختبار)
| الملف | الغرض |
|---|---|
| `database/seeders/BillProvidersStubSeeder.php` | Stub Telecom + خدمتين + 5 منتجات |

تشغيل (development فقط):
```bash
php artisan db:seed --class=BillProvidersStubSeeder
```

---

## المرحلة 2 — Flutter UI لـ v0.9

### الـ Module structure (موحد عبر الميزات الثلاث)

```
lib/features/
├── receipts/
│   ├── controllers/receipts_controller.dart
│   ├── domain/
│   │   ├── models/receipt_models.dart
│   │   └── repositories/receipts_repo.dart
│   └── screens/
│       ├── receipts_list_screen.dart
│       └── receipt_detail_screen.dart
│
├── family_fund/
│   ├── controllers/funds_controller.dart
│   ├── domain/
│   │   ├── models/fund_models.dart
│   │   └── repositories/funds_repo.dart
│   └── screens/
│       ├── my_funds_screen.dart
│       ├── create_fund_screen.dart
│       └── fund_detail_screen.dart
│
└── bill_pay/
    ├── controllers/bill_pay_controller.dart
    ├── domain/
    │   ├── models/bill_pay_models.dart
    │   └── repositories/bill_pay_repo.dart
    └── screens/
        ├── bill_pay_providers_screen.dart
        └── bill_pay_form_screen.dart
```

### الشاشات المُسلَّمة (7)

| الشاشة | الميزة | الوظيفة |
|---|---|---|
| `ReceiptsListScreen` | Receipts | قائمة chronological مع pull-to-refresh + infinite scroll |
| `ReceiptDetailScreen` | Receipts | تفاصيل + تحميل PDF + share + verification code |
| `MyFundsScreen` | Family Fund | قائمة الصناديق + الدعوات + role badges |
| `CreateFundScreen` | Family Fund | form لإنشاء صندوق جديد |
| `FundDetailScreen` | Family Fund | رصيد + حركات + bottom sheet للمساهمة |
| `BillPayProvidersScreen` | Bill Pay | قائمة مزودين + خدماتهم |
| `BillPayFormScreen` | Bill Pay | نموذج دفع مع منتجات/مبلغ متغير + dialog النتيجة |

### قرارات هندسية حاسمة

**1. Idempotency في Bill Pay UI**
الـ `BillPayController._pendingIdempotencyKey` يُحفَظ بعد `prepareNewPayment()`. لو الـ user ضغط "ادفع" ثم انقطع الشبكة وأعاد، يُرسل نفس الـ key — backend يعرف هذا duplicate ولا يخصم مرتين. الـ key يُمسح فقط عند success كامل.

**2. Receipt Status UI**
لكل receipt 3 حالات: `pending_pdf` (جارٍ التحضير) / `pdf_generated` (جاهز) / `pdf_failed` (فشل). الـ UI يظهرها بصرياً، ولا يعطي زر "تحميل" إلا للجاهزة.

**3. Family Fund — Append-only في الـ UI**
شاشة الحركات تعرض كل tx بـ status badge (pending_approval / completed / rejected). لا يوجد "حذف" — متماشٍ مع backend.

**4. Reactive state via Obx**
كل الـ controllers تستخدم `Rx*` types. الـ widgets تلتف بـ `Obx(...)` — re-render تلقائي عند تغيير state. لا setState يدوي للـ data.

### تسجيل DI

`lib/helper/get_di.dart` الآن يسجل 3 zoja جديدة:
- `ReceiptsRepo` + `ReceiptsController` (fenix:true)
- `FundsRepo` + `FundsController` (fenix:true)
- `BillPayRepo` + `BillPayController` (fenix:true)

### Endpoints المُضافة (`app_constants.dart`)

13 endpoint جديد:
- 4 Receipts
- 9 Family Fund
- 5 Bill Pay (يستخدمون existing methods + URL builders)

---

## المرحلة 3 — اختبارات Feature (إلزام احترافي)

التزاماً بقاعدة الجلسة "اختبارات لكل دفعة تطوير"، أضيفت **4 test files** بـ **35+ assertion**:

| الملف | عدد الاختبارات | التغطية |
|---|---|---|
| `tests/Feature/ReceiptServiceTest.php` | 8 | إصدار، uniqueness, dispatch, dual transfer, verification |
| `tests/Feature/FamilyFundServiceTest.php` | 15 | كل lifecycle: create→invite→accept→contribute→propose→approve/reject + append-only |
| `tests/Feature/BillPayServiceTest.php` | 7 | success/failed/pending paths + reconcile + exception handling + zone enforcement |
| `tests/Feature/ReceiptIntegrationInTransactionTraitTest.php` | 3 | hook في send_money + failure doesn't issue + service exception swallowed |

### الاختبارات الأكثر أهمية

**1. `family_fund_transaction_is_append_only` و `family_fund_transaction_cannot_be_deleted`**
يتأكد أن update/delete على FamilyFundTransaction يرميان exception. هذه ضمانة قسم 18 من الوثيقة (شفافية الصندوق).

**2. `failed_payment_refunds_the_user_completely`**
بعد فشل provider، رصيد المحفظة = الرصيد الأصلي تماماً (لا "أموال مفقودة"). الـ test يفحص بـ assertion دقيق على decimal.

**3. `safe_issue_receipts_swallows_exceptions_silently`**
يفحص أن فشل ReceiptService **لا يفشل** التحويل. هذا critical للـ resilience — الـ user حصل على ماله، حتى لو فشل الإيصال.

**4. `provider_exception_triggers_refund`**
network timeout من المزود → refund تلقائي. الـ test يحقن exception ويفحص النتيجة.

### تشغيل الاختبارات

```bash
# كل اختبارات v0.9
php artisan test --filter="ReceiptService|FamilyFund|BillPay|ReceiptIntegration"

# مع تفاصيل
php artisan test --filter="FamilyFundServiceTest" --verbose

# coverage (يحتاج xdebug)
php artisan test --coverage --min=80 --filter="ReceiptService|FamilyFund|BillPay"
```

### ملاحظة مهمة

الاختبارات **تستخدم `Queue::fake()`** لتجنب توليد PDF فعلياً (مكلف). الـ `assertPushed` يتحقق فقط أن الـ Job دُفع للـ queue. اختبارات الـ PDF rendering نفسها تُترك للـ integration tests الأكبر.

---



| # | المعيار | حالة |
|---|---|---|
| 12 | كل عملية ناجحة لها إيصال | ✅ مكامل + Flutter UI |
| 13 | كل مرفق حساس في private storage | ✅ |
| 14 | كل تقرير كبير عبر job export | ✅ مع PDFs |

**13/15 → 14/15** (المتبقي: monitoring + backups — deploy-side)

---

## النسبة الإجمالية

```
v0.9:    █████████████████████ 88%
v0.9-D:  ██████████████████████ 92%
```

### تفصيل بالقسم

| قسم في الوثيقة | قبل | بعد |
|---|---|---|
| Section 14 (Safe Payment) | 5% | 5% — يحتاج merchant source |
| Section 15 (Split Bill) | 5% | 5% — يحتاج merchant source |
| Section 16 (Merchant Ledger) | 10% | 10% — يحتاج merchant source |
| Section 17 (Receipts) | 95% | **100%** ✅ |
| Section 18 (Family Fund) | 95% | **100%** ✅ |
| Section 19 (Bill Pay) | 70% | **90%** (يحتاج عقد مزود حقيقي + production secrets) |
| Section 20 (Flutter UI) | 60% | **90%** (Section 14/15/16 screens مؤجلة لـ merchant batch) |

---

## ربط الشاشات الجديدة بالـ Flow

### Receipts — إضافة لـ HomeScreen

```dart
// في bottom navigation أو menu، أضف:
ListTile(
  leading: const Icon(Icons.receipt_long, color: AmyalColors.primary),
  title: const Text('الإيصالات'),
  onTap: () => Get.to(() => const ReceiptsListScreen()),
),
```

### Family Fund — إضافة لـ Services Menu

```dart
ListTile(
  leading: const Icon(Icons.diversity_3, color: AmyalColors.primary),
  title: const Text('الصناديق العائلية'),
  onTap: () => Get.to(() => const MyFundsScreen()),
),
```

### Bill Pay — إضافة لـ Services Menu

```dart
ListTile(
  leading: const Icon(Icons.payment, color: AmyalColors.primary),
  title: const Text('دفع الفواتير'),
  onTap: () => Get.to(() => const BillPayProvidersScreen()),
),
```

---

## التحقق بعد البناء

```bash
flutter pub get
flutter clean
flutter build apk --debug

# تحقق:
# 1. تسجيل دخول
# 2. افتح Bill Pay → اختر Stub Telecom → شحن جوال → 100 ر.س → ادفع
#    (90% احتمال نجاح، 5% pending، 5% فشل)
# 3. افتح الإيصالات → يجب أن ترى receipt الـ bill pay
# 4. افتح صندوق عائلي → أنشئ → ساهم
# 5. تأكد أن الـ banner لا يظهر (لأنك SOUTH)
```

---

## مَن لا يزال يحتاج (للوصول 100%)

| البند | يحتاج |
|---|---|
| Section 11 (Merchant POS) | شراء كود التاجر |
| Section 12 (Merchant Refund) | شراء كود التاجر |
| Section 13 (Merchant Verification UI) | شراء كود التاجر |
| Section 14 (Safe Payment) | شراء كود التاجر |
| Section 15 (Split Bill) | شراء كود التاجر |
| Section 16 (Merchant Ledger) | شراء كود التاجر |
| v1.0 Pilot prep | deploy work: load test، monitoring (Sentry/Telescope)، backups |
| Bill Pay real provider | عقد مع شركة اتصالات |
| Real release keystore | قرار signing strategy |

النسبة المتبقية **8%** كلها مشروطة بقرارات/مشتريات خارج نطاق التطوير.
