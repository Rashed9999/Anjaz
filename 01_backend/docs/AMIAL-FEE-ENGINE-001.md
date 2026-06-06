# AMIAL-FEE-ENGINE-001 — لوحة تحكم نسب الأرباح/الرسوم (v2.12)

## الفكرة
مصدر مركزي واحد لحساب كل رسم/ربح، قابل للضبط من لوحة الأدمن، **مُؤرّخ
(versioned)** و**مُدقّق (audited)**، بدل الدوال المبعثرة في نمط Cash6
(`get_sendmoney_charge`, `get_cashout_charge`, `get_agent_commission`...).

## الملفات (ADD — لا تعديل لأي ملف مالي قائم)
| النوع | المسار |
|---|---|
| ADD | `database/migrations/2026_05_23_120001_amial_create_fee_engine.php` |
| ADD | `app/Models/FeeScheme.php` |
| ADD | `app/Models/FeeChangeLog.php` |
| ADD | `app/Services/FeeService.php` |
| ADD | `database/seeders/FeeSchemeSeeder.php` |
| ADD | `app/Http/Controllers/Admin/FeeSchemeController.php` |
| ADD | `resources/views/admin-views/amial/fees/{index,create,history}.blade.php` |
| ADD | `tests/Feature/FeeEngineTest.php` |
| ADD | `tests/Feature/FeeSchemeAdminTest.php` |
| MERGE | `routes/admin/amial.php` (أُضيفت مجموعة `fees`) |
| MERGE | `resources/views/admin-views/amial/partials/_sidebar.blade.php` (رابط القائمة) |

## كيف يعمل
- لكل عملية (`SEND_MONEY, CASH_OUT, CASH_IN, MERCHANT_QR, MERCHANT_POS,
  SAFE_PAYMENT, BILL_PAY, SPLIT_BILL, REFUND, FAMILY_FUND_CONTRIB`) نسخة رسم نشطة.
- التعديل = إنشاء **نسخة جديدة** تُلغي السابقة (append-only، لا حذف).
- `FeeService::calculate($code, $amount, $context)` يرجّع:
  `fee, platform_profit, agent_commission, total_debit, net_credit, scheme_id, scheme_version`.
- **ثابت محسوب**: `platform_profit + agent_commission = fee` دائماً (لا انحراف تقريب — bcmath).
- حساب بـ `MoneyService` (DECIMAL، لا float). لا رسم سالب.
- النسخة المستخدَمة تُرجَع ليُخزّنها المُتصل (snapshot) فتبقى العملية التاريخية مفسَّرة.

## اللوحة (admin/amial/fees)
- عرض النسب النشطة لكل عملية.
- إنشاء نسخة جديدة (نوع: percent / fixed / percent_plus_fixed، حدّ أدنى/أقصى،
  حصة الوكيل، من يتحمّل الرسم).
- **محاكي حيّ**: أدخل مبلغاً → شاهد الرسم/الربح/الحصص قبل الحفظ.
- تاريخ النسخ + سجل التدقيق (من غيّر، متى، IP).

## التشغيل عندك (PHP)
```bash
php artisan migrate --pretend      # معاينة أولاً (تعليمات الوثيقة)
php artisan migrate
php artisan db:seed --class=FeeSchemeSeeder   # أرقام افتراضية متحفّظة
php artisan test --filter=FeeEngine
php artisan test --filter=FeeSchemeAdmin
```

## حالة التحقق (صدق)
- ✅ فحص بنيوي: كل ملفات PHP متوازنة الأقواس بعد تجريد التعليقات/النصوص؛
  توجيهات Blade مكتملة.
- ⚠️ **لم تُشغَّل الاختبارات هنا** (بيئة التطوير بلا PHP). يجب تشغيل
  `php artisan test` عندك قبل أي ادعاء بالنجاح. كُتب 19 اختباراً لهذه الميزة.

## ما لم يُنفَّذ بعد (الخطوة التالية، بقصد)
- **ربط `FeeService` بالمسارات المالية الحيّة** (التحويل، السحب، QR/POS،
  الدفع الآمن، الفواتير...) لم يُجرَ بعد لأنه يمسّ قلب العملية المالية ويحتاج
  مراجعة واختبار دقيقين. الميزة الآن = المحرّك + اللوحة + الاختبارات.
  الربط يُضاف كدفعة منفصلة (استبدال نداءات `get_*_charge` القديمة بـ `FeeService::calculate`)
  مع تخزين `fee_scheme_id/version` على كل عملية.

---

# الربط بالمسارات الحيّة (v2.13)

تمّ ربط `FeeService` بمساري **التحويل** و**السحب** بشكل إضافي متوافق رجعياً:

## التغييرات
| النوع | المسار | الوصف |
|---|---|---|
| MERGE | `app/Traits/TransactionTrait.php` | بارامترات اختيارية + دالتا تغليف جديدتان |
| MERGE | `app/Models/Transaction.php` | `fee_scheme_id`, `fee_scheme_version` في fillable |
| ADD | `database/migrations/2026_05_23_130001_amial_add_fee_snapshot_to_transactions.php` | عمودا snapshot |
| ADD | `tests/Feature/FeeEngineWiringTest.php` | 4 اختبارات ربط |

## كيف
- دالتان جديدتان:
  - `send_money_with_fee_engine(from, to, amount, note?, idempotencyKey?, context?)`
  - `cash_out_with_fee_engine(from, to, amount, note?, idempotencyKey?, context?)`
  تحسبان الرسم (وحصة الوكيل للسحب) عبر `FeeService::calculate(...)` ثم تفوّضان
  للدالتين المُختبَرتين `customer_send_money_transaction` / `customer_cash_out_transaction`.
- الدالتان الأصليتان أُضيف لهما بارامترات **اختيارية** فقط
  (`$feeMeta`, و`$agentCommissionOverride` للسحب). **المستدعون القدامى لم يتأثروا**
  (لو لم تُمرّر، السلوك القديم تماماً + snapshot = null).
- snapshot النسخة (`fee_scheme_id/version`) يُخزَّن على صف الخصم الأساسي.
- السحب: حصة الوكيل تأتي من المحرّك إن مُرّرت، وإلا fallback لـ `Helpers::get_agent_commission`.

## التبديل في الـ Controller (خطوتك التالية عند الدمج)
في نقطة دخول التحويل/السحب، استبدل:
```php
// قديم: حساب الرسم يدوياً ثم
$this->customer_send_money_transaction($from, $to, $amount, $charge);
// جديد: المحرّك يحسب الرسم
$this->send_money_with_fee_engine($from, $to, $amount, $note, $idemKey);
```

## حالة التحقق (صدق)
- ✅ فحص بنيوي: `TransactionTrait` متوازن بعد كل التعديلات (320/320 أقواس، 63/63 أقسام).
- ⚠️ **لم تُشغَّل الاختبارات هنا** (لا PHP). شغّل عندك:
  `php artisan migrate && php artisan test --filter=FeeEngine`
- إجمالي اختبارات الميزة: 28 (محرّك 19 + لوحة 5 + ربط 4).

---

# ربط الدفع الآمن (v2.14)

## التغييرات
| النوع | المسار | الوصف |
|---|---|---|
| MERGE | `app/Services/SafePaymentService.php` | الرسم من المحرّك بدل `config` (مع fallback) |
| MERGE | `app/Models/SafePayment.php` | `fee_scheme_id`, `fee_scheme_version` في fillable |
| ADD | `database/migrations/2026_05_23_140001_amial_add_fee_snapshot_to_safe_payments.php` | عمودا snapshot |
| ADD | `tests/Feature/SafePaymentFeeEngineTest.php` | اختباران |

## كيف
- دالة موحّدة `resolveSafePaymentFee($amount)`: تستدعي
  `FeeService::calculate('SAFE_PAYMENT', $amount)`.
- **fallback متوافق رجعياً**: إذا لم توجد نسخة `SAFE_PAYMENT` نشطة، تعود للسلوك
  القديم (`config('amial.safe_payment.fee_percent')`، افتراضياً 1%).
- استُبدِل حساب الرسم في موضعي الإفراج (الكامل + الجزئي) بهذه الدالة.
- snapshot النسخة يُخزَّن على سجل `safe_payments`.

## ملاحظة مهمة — دفع التاجر QR/POS
**لا يوجد بعد مسار "دفع تاجر QR/POS" مبني كعملية مستقلة** في الكود الحالي
(`MerchantController` = استرجاع + دفتر + إحصاءات فقط). أكواد `MERCHANT_QR/POS`
موجودة في المحرّك والبذرة، لكن ربطها يتطلب **بناء مسار الدفع نفسه أولاً**
(عميل → تاجر، خصم الرسم، قيد دفتر التاجر، نسبة POS) — يُقترح كمتطلب مستقل
`AMIAL-MERCHANT-PAY-001`.

## الحالة الحالية للربط
| العملية | الحالة |
|---|---|
| SEND_MONEY | ✅ مربوط (v2.13) |
| CASH_OUT | ✅ مربوط (v2.13) |
| SAFE_PAYMENT | ✅ مربوط (v2.14) |
| BILL_PAY | ⏳ رسم على مستوى المنتج (bill_service_products) — ربط اختياري |
| MERCHANT_QR/POS | ⛔ المسار غير مبني بعد |
| SPLIT_BILL / REFUND / FAMILY_FUND | ⏳ لاحقاً |

## التحقق (صدق)
- ✅ فحص بنيوي ناجح لكل الملفات.
- ⚠️ **لم تُشغَّل الاختبارات هنا** (لا PHP). اختبارات الميزة الآن: **30**.
