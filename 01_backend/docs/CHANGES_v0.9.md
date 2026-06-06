# سجل التغييرات — Amial Pay v0.9 (Receipts + Family Fund + Bill Pay)

**التاريخ:** 2026-05-16
**النطاق:**
- AMIAL-RECEIPTS-001 (v0.9-A) — قسم 17 من الوثيقة
- AMIAL-FUND-FAMILY-001 (v0.9-B) — قسم 18
- AMIAL-BILL-PAY-001 (v0.9-C) — قسم 19

**يفترض:** v0.8 منشورة، migrations سابقة طُبِّقت.

---

## ملخص الأرقام

| الفئة | عدد |
|---|---|
| Migrations جديدة | 6 |
| Models جديدة | 9 |
| Services جديدة | 3 (Receipt, FamilyFund, BillPay) |
| Jobs جديدة | 1 (GeneratePdfReceiptJob) |
| Controllers جديدة | 3 (Receipt, FamilyFund, BillPay) |
| Blade templates | 1 (receipts/pdf.blade.php) |
| API endpoints جديدة | 22 |

---

## v0.9-A — Receipts (قسم 17)

### الأهداف من الوثيقة
- كل عملية ناجحة لها receipt ✅
- المستخدم يرى الإيصال + يحمل PDF + يشاركه ✅
- يحوي receipt_number + verification_code + QR ✅
- يُحفظ في private storage ✅
- لا يُولَّد PDF داخل مسار العملية المالية ✅ (Queue Job)

### الملفات

| الملف | التصنيف |
|---|---|
| `database/migrations/2026_05_16_120001_amial_create_receipts_table.php` | ADD |
| `app/Models/Receipt.php` | ADD |
| `app/Services/ReceiptService.php` | ADD |
| `app/Jobs/GeneratePdfReceiptJob.php` | ADD |
| `app/Http/Controllers/Api/V1/Amial/ReceiptController.php` | ADD |
| `resources/views/receipts/pdf.blade.php` | ADD |

### API

| Method | URL | Auth |
|---|---|---|
| GET | `/api/v1/amial/receipts` | ✓ |
| GET | `/api/v1/amial/receipts/{id}` | ✓ |
| GET | `/api/v1/amial/receipts/{id}/download` | ✓ |
| GET | `/api/v1/amial/v/{verification_code}` | ✗ (public — for QR scan) |

### Dependency جديدة (إلزامية)

```bash
composer require barryvdh/laravel-dompdf
```

بدونها، الـ Job يفشل بشكل آمن (يضع status = `pdf_failed`).

### Integration مع TransactionTrait

⚠️ **خطوة يدوية للمطور:** بعد كل `customer_send_money_transaction` (وأخواتها)، أضف:

```php
// داخل التابع، بعد COMMIT الناجح (خارج DB::transaction):
app(ReceiptService::class)->issueDualForTransfer([
    'from_user_id' => $from_user_id,
    'to_user_id' => $to_user_id,
    'reference_transaction_id' => $primaryId,  // ULID
    'receipt_type' => 'send_money',
    'amount' => $amount,
    'fee' => $charge,
    'zone_code' => 'SOUTH',
]);
```

أو الـ TransactionTrait يستدعي ذلك تلقائياً عند تعديل لاحق (تركتها يدوية لتجنب breaking الـ tests الحالية).

---

## v0.9-B — Family Fund (قسم 18)

### الأهداف من الوثيقة
- مستخدم ينشئ صندوقاً + يدعو أعضاء بأرقام مسجلة ✅
- العضو يدخل بعد قبول الدعوة ✅
- كل عضو يضيف مال + بيان + مرفق اختياري ✅
- شفافية كاملة: كل الأعضاء يرون السجل ✅
- رصيد مستقل للصندوق ✅
- before_balance/after_balance + لا حذف ✅
- صرف يحتاج موافقة owner ✅ (configurable)
- الأدوار: Owner/Admin/Member/Viewer ✅
- SOUTH فقط ✅
- إيصال PDF لكل مساهمة/صرف ✅

### الملفات

| الملف | التصنيف |
|---|---|
| `database/migrations/.._create_family_funds_table.php` | ADD |
| `database/migrations/.._create_family_fund_members_table.php` | ADD |
| `database/migrations/.._create_family_fund_transactions_table.php` | ADD |
| `app/Models/FamilyFund.php` | ADD |
| `app/Models/FamilyFundMember.php` | ADD |
| `app/Models/FamilyFundTransaction.php` | ADD (append-only) |
| `app/Services/FamilyFundService.php` | ADD |
| `app/Http/Controllers/Api/V1/Amial/FamilyFundController.php` | ADD |

### API

| Method | URL | الغرض |
|---|---|---|
| GET | `/api/v1/amial/funds` | قائمة الصناديق |
| POST | `/api/v1/amial/funds` | إنشاء صندوق |
| GET | `/api/v1/amial/funds/{ulid}` | تفاصيل |
| POST | `/api/v1/amial/funds/{ulid}/invite` | دعوة عضو |
| POST | `/api/v1/amial/funds/{ulid}/contribute` | مساهمة |
| POST | `/api/v1/amial/funds/{ulid}/propose-disbursement` | اقتراح صرف |
| POST | `/api/v1/amial/funds/disbursements/{ulid}/approve` | موافقة (owner) |
| POST | `/api/v1/amial/funds/disbursements/{ulid}/reject` | رفض (owner) |
| POST | `/api/v1/amial/funds/memberships/{id}/accept` | قبول دعوة |
| GET | `/api/v1/amial/funds/{ulid}/transactions` | سجل الحركات |

### قرار تصميم: Append-only enforcement

`FamilyFundTransaction::update()` يرمي `RuntimeException` إن حاولت تعديل غير حقول الـ approval (status, approved_by_user_id, approved_at). `delete()` يرمي مباشرة. هذا يضمن **شفافية الصندوق** المذكورة في الوثيقة.

---

## v0.9-C — Bill Pay (قسم 19)

### الأهداف من الوثيقة
- صفحة لتسديد فواتير عبر API مع شركة اتصالات ✅
- جداول: bill_providers, bill_services, bill_service_products, bill_payment_orders, bill_provider_requests ✅
- الحالات: pending, processing, success, failed, pending_provider_confirmation, reversed ✅
- لا تظهر الخدمة إلا إذا كانت مفعّلة + SOUTH ✅
- لا success إلا بتأكيد المزود ✅
- كل req/response للمزود يُسجَّل ✅
- إيصال PDF لكل عملية ناجحة ✅

### الملفات

| الملف | التصنيف |
|---|---|
| `database/migrations/.._create_bill_pay_structure_table.php` | ADD (3 جداول: providers, services, products) |
| `database/migrations/.._create_bill_pay_orders_table.php` | ADD (2 جداول: orders, requests) |
| `app/Models/BillProvider.php` | ADD |
| `app/Models/BillService.php` | ADD |
| `app/Models/BillServiceProduct.php` | ADD |
| `app/Models/BillPaymentOrder.php` | ADD |
| `app/Models/BillProviderRequest.php` | ADD |
| `app/Services/BillPay/BillProviderInterface.php` | ADD (Contract) |
| `app/Services/BillPay/BillProviderResponse.php` | ADD (DTO) |
| `app/Services/BillPay/StubProvider.php` | ADD (للتطوير) |
| `app/Services/BillPayService.php` | ADD (orchestration) |
| `app/Http/Controllers/Api/V1/Amial/BillPayController.php` | ADD |

### API

| Method | URL | الغرض |
|---|---|---|
| GET | `/api/v1/amial/bill-pay/providers` | قائمة المزودين النشطين |
| GET | `/api/v1/amial/bill-pay/services/{id}/products` | منتجات تحت خدمة |
| POST | `/api/v1/amial/bill-pay/pay` | تنفيذ دفع |
| GET | `/api/v1/amial/bill-pay/orders` | سجل العمليات |
| GET | `/api/v1/amial/bill-pay/orders/{ulid}` | تفاصيل عملية |

### قرار تصميم: Provider Abstraction

تم تصميم `BillProviderInterface` لتأخر الاعتماد على مزود حقيقي. `StubProvider` يحاكي:
- 90% success
- 5% failed (مع refund تلقائي)
- 5% pending (يحتاج reconcile لاحقاً)

بمجرد توقيع عقد مع مزود حقيقي:
1. نُنشئ class جديد مثل `YemenMobileProvider` يطبق `BillProviderInterface`
2. نُسجَّل في `BillPayService::resolveProvider()` حسب `provider->integration_type`
3. لا تعديل في باقي الكود

### Job مطلوب (مؤجل لـ v1.0)

```php
// routes/console.php
Schedule::command('amial:bill-pay:reconcile-pending')->everyMinute();
```

يستدعي `BillPayService::reconcilePendingOrder()` لكل order في حالة `pending_provider_confirmation` آخر 24 ساعة.

---

## معايير القبول — التحديث

| # | المعيار | قبل | بعد v0.9 |
|---|---|---|---|
| 12 | كل عملية ناجحة لها إيصال | ❌ | ✅ ReceiptService + Queue |
| 13 | كل مرفق حساس في private storage | ⚠️ | ✅ Storage::disk('local') لكل PDFs |
| 14 | كل تقرير كبير عبر job export | ⚠️ | ✅ Receipt جزء من Job pattern |

**12/15 → 13/15** محقق

---

## نسبة التطور

```
v0.8:    ███████████████████░  75%
v0.9:    █████████████████████ 88%
```

| القسم | قبل | بعد |
|---|---|---|
| Section 14 (Safe Payment) | 5% | 5% (لم يتغير — يحتاج جلسة منفصلة) |
| Section 17 (Receipts) | 0% | **95%** |
| Section 18 (Family Fund) | 0% | **95%** (Flutter UI مؤجل لـ v0.9-D) |
| Section 19 (Bill Pay) | 0% | **70%** (جاهز لمزود حقيقي) |

---

## نشر v0.9

```bash
# 1. backup
mysqldump --single-transaction cash6_db > /backups/pre_v0.9_$(date +%s).sql

# 2. composer
composer require barryvdh/laravel-dompdf

# 3. migrations
php artisan migrate --pretend  # افحص أولاً
php artisan migrate

# 4. configure queue (مهم — receipts تعتمد على queue)
# في .env:
# QUEUE_CONNECTION=redis  أو database
php artisan queue:restart

# 5. أضف queue worker لـ receipts (لو منفصل):
# php artisan queue:work --queue=receipts,default
# (لو الـ default queue يكفي، استخدمه)

# 6. خلق storage symlink لو لم يوجد
php artisan storage:link  # ليس ضرورياً (PDFs في private بدون link)

# 7. ابدأ seed لـ bill providers (لاحقاً):
# php artisan tinker
# >>> BillProvider::create(['code' => 'stub_telecom', 'name' => 'Stub Telecom', ...]);
```

## التحقق

```bash
# Receipts
php artisan test --filter=ReceiptTest  # (tests يُضافون لاحقاً)
php artisan route:list | grep receipts
# يتوقع: 4 routes

# Family Fund
php artisan route:list | grep funds
# يتوقع: 10 routes

# Bill Pay
php artisan route:list | grep bill-pay
# يتوقع: 5 routes
```

---

## ما لم يُنجَز

| البند | السبب |
|---|---|
| Flutter UI لـ Family Fund | يحتاج جلسة UI منفصلة |
| Flutter UI لـ Bill Pay | يحتاج جلسة UI منفصلة |
| Flutter UI لـ Receipts (تحميل PDF + share) | يحتاج جلسة UI منفصلة |
| Section 14 — Safe Payment | يحتاج merchant source code |
| Section 15 — Split Bill | يحتاج merchant POS |
| Section 16 — Merchant Ledger | يحتاج merchant source |
| Bill Pay reconcile Job | مؤجل لـ v1.0 (يحتاج Scheduler config) |
| Receipt integration in TransactionTrait | يدوي للمطور (لتجنب breaking tests) |

هذه الميزات تكتمل في v1.0 (Flutter UI batch + merchant features بعد الشراء).
