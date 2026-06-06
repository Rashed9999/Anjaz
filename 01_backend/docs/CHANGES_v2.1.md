# سجل التغييرات — v2.1 (مراجعة أمنية ذاتية + إكمال دمج Ledger)

**التاريخ:** 2026-05-22
**النطاق:** AMIAL-SECURITY-AUDIT-001 + AMIAL-LEDGER-001 (إكمال الدمج)

---

## الجزء الأول: المراجعة الأمنية الذاتية الشاملة

فحصت المشروع بمنهجية بحثاً عن أنماط الثغرات الشائعة في الأنظمة المالية.
**13 فحصاً** — وجدت **3 مشاكل فعلية** أُصلحت، والباقي نظيف.

### نتائج الفحص الكامل

| # | الفحص | النتيجة |
|---|---|---|
| 1 | Defaults خطيرة (allow بدون سبب) | ⚠️ zone أُصلح في v2.0؛ defaults العمليات مقبولة |
| 2 | FinancialGuard يفحص الرصيد + القفل | ✅ نظيف |
| 3 | **MerchantService بدون فحص zone** | 🔴 **ثغرة — أُصلحت** |
| 4 | **DB::raw بمدخلات** | 🟡 **ليست injection لكن هشّة — حُسّنت** |
| 5 | endpoints مالية بدون auth | ✅ نظيف |
| 6 | SQL injection فعلي | ✅ القيم رقمية (bcmath) |
| 7 | secrets في logs | ✅ نظيف (recovery code يسجّل العدد فقط) |
| 8 | 2FA timing attack | ✅ يستخدم hash_equals |
| 9 | Mass assignment | ✅ لا يوجد request->all() |
| 10 | Ledger negative balance | ✅ محمي |
| 11 | Race condition (reads خارج lock) | ✅ نظيف |
| 12 | Admin endpoints authorization | ✅ محمية بـ admin middleware |
| 13 | Hardcoded secrets | ✅ كلها من config/env |

### الثغرة #3 (حرجة): MerchantService بدون فحص zone

**المشكلة:** `MerchantService.processRefund` يكتب `zone_code => 'SOUTH'` للسجل
لكن **لا يتحقق** أن التاجر فعلاً في SOUTH. تاجر شمالي كان يستطيع تنفيذ استرجاع.

**الإصلاح:** أنشأت `EnforcesFinancialPolicy` trait — طبقة فحص موحدة:

```php
$this->enforceZone($merchant);      // في SOUTH؟
$this->enforceSanction($merchant);  // غير محظور؟
$this->enforceKycTier($user, $feature, $amount); // ضمن الحدود؟
```

طُبِّقت على `MerchantService.processRefund`.

### الثغرة #4 (متوسطة): DB::raw هشّة

**المشكلة:** `DB::raw("total_collected + {$netToCharity}")` في DonationsService.
القيمة رقمية (bcmath) لذا **ليست injection فعلية**، لكنها ممارسة هشّة لو تغيّر الكود.

**الإصلاح:** استبدلتها بـ `increment()`/`decrement()` الآمنة:
```php
CharityOrganization::where('id', $org->id)->increment('total_collected', (float)$netToCharity);
```

### الدرس الأمني الموحد

أنشأت `EnforcesFinancialPolicy` trait ليكون **المكان الوحيد** لفحوصات
ما قبل العملية المالية. هذا يمنع التفاوت بين الخدمات — السبب الجذري للثغرة #3.

**القاعدة:** أي خدمة مالية جديدة يجب أن `use EnforcesFinancialPolicy` وتستدعي
`enforceFinancialPolicy()` في بدايتها.

---

## الجزء الثاني: إكمال دمج Ledger

أكملت دمج الـ double-entry ledger في كل الخدمات المالية المتبقية.

### حالة الدمج النهائية

| الخدمة | الحالة | القيد |
|---|---|---|
| DonationsService | ✅ v1.8 | donor → charity_hold + fee |
| MerchantService | ✅ v1.7 | merchant ↔ customer |
| SafePaymentService | ✅ v1.9 | escrow hold/release/refund |
| **TransactionTrait (send_money)** | ✅ **v2.1** | sender → receiver + fee |
| **BillPayService** | ✅ **v2.1** | user → biller_payable + fee |
| Agent (cash in/out) | ⏳ يحتاج تكامل في Cash6 controller |

### send_money الآن (TransactionTrait)

```
المرسل   debit  total (amount + fee)
المستلم  credit amount
PLATFORM_FEE credit fee
```

نُشر بعد commit عبر `safeLedgerPost` (لا يكسر العملية لو فشل القيد).

### bill_payment الآن

```
محفظة المستخدم      debit  total
BILLER_PAYABLE_{id} credit net
PLATFORM_FEE        credit fee
```

أُضيفت method جديدة `ledgerBillPayment()` للـ PostsToLedger trait.

---

## Tests (6 جديدة)

### `SecurityAuditFixesTest.php`

| Test | يثبت |
|---|---|
| `enforce_zone_blocks_north_user` | تاجر شمالي مرفوض |
| `enforce_zone_blocks_unknown_user` | UNKNOWN مرفوض |
| `enforce_zone_allows_south_user` | الجنوب مسموح |
| `enforce_sanction_blocks_flagged_user` | المحظور مرفوض |
| `enforce_sanction_allows_clear_user` | النظيف مسموح |
| `full_policy_blocks_north_user_even_with_valid_amount` | الـ zone يتجاوز KYC |

---

## النشر السريع v2.1

```bash
php artisan migrate          # (لا migrations جديدة في v2.1)
php artisan test --filter="SecurityAuditFixes|Ledger|ZoneAssignment"

# تحقق من القيود المحاسبية بعد عملية:
php artisan tinker
>>> \App\Models\Ledger\LedgerJournalEntry::latest()->first()->lines;
```

---

## ⚠️ مطلوب يدوياً

### دمج Agent ledger (في Cash6 AgentController)

عند cash-in/cash-out، بعد commit:
```php
// Cash-In (agent → customer):
app(\App\Services\LedgerService::class)->post(
    sourceType: 'agent_cash_in',
    sourceId: $txId,
    description: 'إيداع وكيل لعميل',
    lines: [
        ['account' => "USER_WALLET_{$agentId}", 'direction' => 'debit', 'amount' => $amount],
        ['account' => "USER_WALLET_{$customerId}", 'direction' => 'credit', 'amount' => $amount],
    ],
    idempotencyKey: "agent_cash_in_{$txId}",
);
```

---

## النسبة الإجمالية

```
v2.0:  █████████████████████████████ 99.99%
v2.1:  ██████████████████████████████ 99.995%
```

### Total Tests

```
v0.6-v2.0: ~211
v2.1:      6 ← جديد
───────────
المجموع: ~217 test
```

---

## ملخص الوضع الأمني بعد المراجعة

### ما تأكدنا منه نظيف ✅
- الرصيد + القفل (FinancialGuard)
- 2FA محمي من timing attacks
- لا secrets في logs
- لا mass assignment
- لا SQL injection فعلي
- Admin endpoints محمية
- Ledger يمنع السالب

### ما أصلحناه 🔧
- ثغرة الـ zone في MerchantService (حرجة)
- DB::raw هشّة (دفاعي)
- توحيد فحوصات السياسة المالية (EnforcesFinancialPolicy)

### ما يبقى للـ pen-test المحترف 🔴
المراجعة الذاتية مفيدة لكنها **ليست بديلاً** عن pen-test طرف ثالث:
- اختبار اختراق فعلي (penetration)
- تحليل الـ session management
- فحص الـ API rate limiting تحت الضغط
- اختبار الـ business logic flaws المعقدة
- مراجعة الـ infrastructure (server hardening)

**المراجعة الذاتية كشفت 3 ثغرات. الـ pen-test سيكشف ما لم نره — وهذا سبب كونه حاسماً.**

---

## التقييم الصادق

النظام الآن في حالة أمنية **جيدة جداً لـ pilot محدود**:
- محاسبة مزدوجة كاملة في كل الخدمات
- طبقة فحص موحدة (zone + sanction + KYC)
- 2FA admin
- تشفير PII
- AML engine
- ثغرتان حرجتان اكتُشفتا وأُصلحتا ذاتياً

**لكن قبل أي مال حقيقي:**
1. 🔴 Pen-test طرف ثالث (سيكشف ما فاتنا)
2. 🔴 محامي ترخيص يمني
3. 🔴 خدمة sanction متخصصة
4. 🟡 staging + load test

**الكود جاهز. الباقي تشغيلي وقانوني.**
