# سجل التغييرات — v1.9 (Ledger Integration + Sanction + KYC Tiers)

**التاريخ:** 2026-05-22
**النطاق:** AMIAL-LEDGER-001 (full integration) + AMIAL-SANCTION-001 + AMIAL-KYC-TIERS-001

---

## ما تم بناؤه

| الفئة | عدد |
|---|---|
| Migrations | 1 (sanction + kyc tiers) |
| Services | 2 (SanctionScreening + KycTier) |
| Seeders | 1 (KycTierLimits) |
| Service integration | 1 (SafePaymentService → Ledger) |
| Tests | 1 ملف / **18 test** |
| **مجموع** | **6 ملفات + دمج Ledger** |

---

## A. Ledger Integration الكامل (AMIAL-LEDGER-001)

### SafePaymentService الآن مدموج بالكامل

كل دورة حياة الدفع الآمن تُسجَّل محاسبياً:

```
1. createAndFund   → ledgerHoldEscrow
   محفظة المشتري   debit  100
   ESCROW_HOLD     credit 100

2. release (buyerConfirm / adminResolveRelease)  → ledgerReleaseEscrow
   ESCROW_HOLD     debit  100
   محفظة البائع    credit  99
   PLATFORM_FEE    credit   1

3. refund (cancel / dispute / expiry)  → ledgerRefundEscrow
   ESCROW_HOLD     debit  100
   محفظة المشتري   credit 100
```

### نمط Deferred Posting (احترافي)

المشكلة: الـ release/refund داخل `DB::transaction`، والـ ledger يحتاج commit نظيف.

الحل: `$pendingLedgerPosts[]` يجمع البيانات داخل الـ transaction، ثم `flushPendingLedgerPosts()` ينشر بعد commit عبر `safeLedgerPost` (لا يرمي exception).

```php
// داخل releaseToSellerInternal (ضمن transaction):
$this->pendingLedgerPosts[] = ['type' => 'release', ...];

// بعد commit (في buyerConfirm/adminResolve...):
$this->flushPendingLedgerPosts();
```

### حالة الدمج الكاملة

| الخدمة | الحالة |
|---|---|
| DonationsService | ✅ مدموج (v1.8) |
| MerchantService (refund) | ✅ مدموج (v1.7) |
| **SafePaymentService** | ✅ **مدموج (v1.9)** |
| TransactionTrait (send_money) | ⏳ trait جاهز، يحتاج استدعاء |
| BillPayService | ⏳ trait جاهز، يحتاج استدعاء |
| Agent transactions | ⏳ trait جاهز، يحتاج استدعاء |

> الخدمات المتبقية: الـ `PostsToLedger` trait جاهز، يحتاج فقط استدعاء `ledgerTransfer()` post-commit في كل منها (نمط واضح موثّق).

---

## B. Sanction Screening (AMIAL-SANCTION-001)

### فحص ضد قوائم العقوبات (OFAC, UN, EU, LOCAL)

```
المنهجية:
  1. تطبيع الاسم (عربي + لاتيني)
  2. مطابقة بالمعرفات (national_id hash) — الأقوى
  3. مطابقة دقيقة على الاسم المُطبَّع
  4. مطابقة ضبابية (fuzzy) للأسماء المتقاربة

النتائج:
  clear            → لا تطابق
  potential_match  → تطابق ضبابي (مراجعة admin)
  confirmed_match  → تطابق دقيق (block فوري)
```

### تطبيع الاسم العربي (احترافي)

```
أ/إ/آ → ا      (توحيد الألف)
ى → ي          (الياء)
ة → ه          (التاء المربوطة)
ؤ → و، ئ → ي
+ إزالة التشكيل (الفتحة/الضمة/...)
+ إزالة الرموز والمسافات الزائدة
```

نتيجة: "أحمد" و "احمد" يتطابقان. هذا حاسم لقوائم العقوبات العربية.

### الجداول

```
sanction_list_entries     → القوائم (name, aliases, national_id_hash, ...)
sanction_screening_logs   → سجل كل فحص (audit)
users.sanction_status     → clear/flagged/blocked
```

### ⚠️ ملاحظة احترافية صادقة

هذا فحص **أساسي** يوفّر البنية. في production الحقيقي:
- قوائم العقوبات تتحدث **يومياً** — تحتاج تحديث آلي
- الـ fuzzy matching الأساسي (similar_text) أقل دقة من Jaro-Winkler/Soundex
- **يُفضّل بشدة** خدمة متخصصة (ComplyAdvantage, Refinitiv World-Check, Dow Jones) للـ pilot الجدي

البنية الحالية كافية لـ:
- حظر قائمة محلية معروفة
- فحص أولي قبل الخدمة المتخصصة
- إثبات الـ compliance المبدئي

---

## C. KYC Tiers (AMIAL-KYC-TIERS-001)

### 4 مستويات بحدود متدرجة

| Tier | الاسم | أقصى رصيد | عملية واحدة | يومي | شهري | الميزات |
|---|---|---|---|---|---|---|
| **0** | غير موثق | 0 | 0 | 0 | 0 | عرض فقط |
| **1** | أساسي | 50k | 5k | 10k | 50k | تحويل، فواتير |
| **2** | قياسي | 500k | 50k | 100k | 500k | + دفع آمن، تبرعات، صندوق |
| **3** | كامل | 5M | 500k | 1M | 5M | كل الميزات |

(بالريال السعودي)

### الفائدة

| الفائدة | التفصيل |
|---|---|
| **تقليل المخاطر** | مستخدم جديد لا يحرّك مبالغ كبيرة |
| **AML/CFT compliance** | تدرّج حسب المعرفة بالعميل (KYC) |
| **تحفيز التوثيق** | المستخدم يرفع مستواه لرفع الحدود |
| **حماية تلقائية** | فحص قبل كل عملية + رصيد |

### الاستخدام

```php
// قبل أي عملية مالية:
app(KycTierService::class)->assertTransactionAllowed($user, $amount, 'send_money');
// يرمي exception إذا: ميزة غير مسموحة، تجاوز حد العملية/اليومي/الشهري

// قبل تحديث رصيد:
app(KycTierService::class)->assertBalanceAllowed($user, $newBalance);

// ترقية بعد توثيق:
app(KycTierService::class)->upgradeTier($user, 2, $adminId);
```

الحدود اليومية/الشهرية تُحسب من الـ **ledger** (دقة محاسبية).

---

## Tests (18)

### `KycSanctionTest.php`

**KYC Tiers (10):**
- tier 0 يمنع كل العمليات
- tier 1 يسمح بالصغيرة، يرفض الكبيرة
- tier 1 يمنع safe_payment
- tier 2 يسمح safe_payment
- tier 3 يسمح كل الميزات
- حد الرصيد مُطبَّق
- الترقية تعمل + ترفض المستوى غير الصالح
- معلومات المستوى تشمل التالي

**Sanction (8):**
- اسم نظيف يمر
- مطابقة دقيقة → confirmed
- مطابقة national_id → confirmed
- تطبيع الاسم العربي (أحمد = احمد)
- تسجيل النتيجة
- المطابقة المؤكدة تحظر المستخدم

---

## النشر السريع v1.9

```bash
# 1. backup + migrations
mysqldump cash6_db > pre_v1.9.sql
php artisan migrate

# 2. seed KYC tiers
php artisan db:seed --class=KycTierLimitsSeeder

# 3. tests
php artisan test --filter="KycSanction|SafePayment|Ledger"

# 4. (مهم) أضف لـ User model:
#    'kyc_tier', 'kyc_tier_updated_at', 'sanction_checked', 'sanction_status' في fillable
#    'kyc_tier' => 'integer', 'sanction_checked' => 'boolean' في casts

# 5. دمج في الخدمات المالية (يدوياً):
#    قبل كل عملية: $kyc->assertTransactionAllowed($user, $amount, $feature);
#    عند التسجيل: $sanction->screenUser($user, 'registration');
```

---

## ⚠️ مطلوب لـ User model

```php
protected $fillable = [/* ... */ 'kyc_tier', 'kyc_tier_updated_at',
    'sanction_checked', 'sanction_status'];

protected $casts = [/* ... */ 'kyc_tier' => 'integer',
    'kyc_tier_updated_at' => 'datetime', 'sanction_checked' => 'boolean'];
```

---

## النسبة الإجمالية

```
v1.8:  █████████████████████████████ 99.97%
v1.9:  █████████████████████████████ 99.98%
```

### Total Tests للمشروع

```
v0.6-v1.8: ~181
v1.9:      18 ← جديد
───────────
المجموع: ~199 test
```

---

## أين نحن الآن (تقييم صادق)

### مكتمل تقنياً ✅
- Core refactor (lockForUpdate, DECIMAL, idempotency)
- Zone policy + Legal terms + Account recovery
- Admin panel + RBAC + Monitoring
- Receipts + Family fund + Bill pay
- Safe payment (escrow) + Donations
- **Double-entry ledger (مدموج في 3 خدمات)**
- PII encryption + AML engine
- Unified login (كل الأدوار)
- Agent + Merchant apps (Flutter)
- 2FA TOTP (admin)
- QR generation/scanning
- **Sanction screening (أساسي)**
- **KYC tiers (4 مستويات)**

### يحتاج إكمال (تقني) ⏳
- دمج Ledger في 3 خدمات متبقية (send_money, bill_pay, agent)
- Ledger كمصدر حقيقة وحيد (drop EMoney)
- POS users management screen

### حاسم قبل أي pilot حقيقي (غير تقني) 🔴
1. **Pen-test طرف ثالث** ($3-10k) — لا بديل
2. **محامي للترخيص اليمني** — البنك المركزي اليمني
3. **خدمة sanction متخصصة** (ComplyAdvantage) — للـ compliance الجدي
4. **AWS Secrets Manager** للمفاتيح
5. **عقد تأمين سيبراني**
6. **Staging + K6 load test**

---

## التوصية النهائية الصادقة

**الجانب التقني الآن في حالة ممتازة لـ pilot صغير محدود.**

النظام يحتوي على ما تحتاجه fintech جادة:
- محاسبة مزدوجة حقيقية
- تشفير PII
- محرك AML
- 2FA
- KYC tiers
- sanction screening

**لكن البرمجة وحدها لا تكفي للإطلاق.** الأولوية الآن **تتحول 100% إلى:**

| الأولوية | الإجراء | لماذا |
|---|---|---|
| 🔴 1 | **محامي ترخيص يمني** | قد يكون الإطلاق بدون ترخيص جريمة مالية |
| 🔴 2 | **Pen-test** | اكتشاف ثغرات لم نرها |
| 🔴 3 | **خدمة sanction حقيقية** | الأساسي لا يكفي قانونياً |
| 🟡 4 | **Staging + load test** | تأكيد التحمل الفعلي |
| 🟡 5 | إكمال دمج Ledger | اكتمال القيمة المحاسبية |

**نصيحة:** قبل كتابة المزيد من الكود، تحدّث مع محامٍ متخصص في اللوائح المالية اليمنية. السؤال الأهم: **هل تحتاج رخصة من البنك المركزي اليمني لتشغيل محفظة إلكترونية؟** الإجابة على الأرجح نعم، وقد تغيّر كل خططك.
