# سجل التغييرات — v2.10 (إكمال نظام مراقبة التجار)

**التاريخ:** 2026-05-23 | **النطاق:** AMIAL-MERCHANT-RISK-001 (تشغيل كامل)

---

## ما أُكمل في v2.10 (الفجوات الأربع)

في v2.9 بُني اللب (تصنيف + 3 أنماط + لوحة). في v2.10 أصبح **قابلاً للتشغيل**:

| الفجوة | الحل |
|---|---|
| تحليل يبطئ العميل | **AnalyzeMerchantRiskJob** (خلفية non-blocking) |
| لا واجهة إدارة | **AdminMerchantRiskController** (5 endpoints) |
| التجار الحاليون بلا profile | **MerchantProfileBackfillSeeder** |
| غير مربوط بالدفع | **hook في TransactionTrait** (يكتشف التاجر تلقائياً) |

### المبدأ المعماري المطبّق

```
الدفع للتاجر:
  1. assertReceiveAllowed()  ← متزامن، سريع (فحص الحد)
  2. التحويل ينفّذ
  3. AnalyzeMerchantRiskJob  ← خلفية، لا يبطئ العميل (التحليل المعقّد)
```

هذا يطبّق مبدأ "رصد": الفحص السريع متزامن، التحليل المعقّد في الخلفية.

### Admin API الجديد

```
GET  /admin/amial/merchants/high-risk        → التجار عالو المخاطر (أولوية)
GET  /admin/amial/merchants/risk-stats        → إحصاءات عامة
GET  /admin/amial/merchants/{id}/risk         → لوحة مخاطر تاجر
PUT  /admin/amial/merchants/{id}/tier         → تغيير التصنيف
POST /admin/amial/merchants/{id}/verify       → التوثيق
```

### النشر

```bash
php artisan migrate
php artisan db:seed --class=MerchantProfileBackfillSeeder
php artisan test --filter="MerchantRisk"   # 14 اختبار
```

### Total Tests: ~288 (v2.9: 12 + v2.10: 2 = 14 لمراقبة التجار)

---

# سجل التغييرات — v2.9 (تصنيف ومراقبة مخاطر التجار)

**التاريخ:** 2026-05-23
**النطاق:** AMIAL-MERCHANT-RISK-001

---

## السياق: 3 أسئلة كشفت فجوة حقيقية

> 1. هل كل التجار نفس المستوى؟
> 2. ألا يمكن أن يكون هناك غسيل أموال في حساباتهم؟
> 3. هل التطبيق جاهز للتعامل معهم؟

### الإجابة الصادقة قبل v2.9

| السؤال | الحالة السابقة |
|---|---|
| نفس المستوى؟ | نعم (فجوة) — التاجر مجرد user type=3 |
| غسيل أموال؟ | ممكن — AML عام لا يراعي خصوصية التجار |
| جاهز؟ | للأساسيات نعم، للمخاطر المتقدمة لا |

**الفجوة:** التاجر يستقبل من عملاء كثر (طبيعي)، وهذا بالضبط ما يخفي الغسيل.
كنا نعامله كمستخدم عادي رغم أنه **أعلى مخاطرة**.

---

## ما بُني في v2.9

### 1. تصنيف التجار (Tiers)

| التصنيف | المثال | حد يومي | حد العملية |
|---|---|---|---|
| micro | بقالة/كشك | 200,000 | 50,000 |
| small | محل | 1,000,000 | 200,000 |
| medium | متجر | 5,000,000 | 1,000,000 |
| large | شركة | 20,000,000 | 5,000,000 |

**لا حد موحد بعد الآن** — كل تاجر حسب حجمه الحقيقي.

### 2. حالات التوثيق (من الوثيقة الأصلية)

```
unverified → pending_review → verified
                ↓
        rejected / resubmission_required / verification_suspended
```

### 3. مراقبة 3 أنماط غسيل أموال

| النمط | الكشف | النقاط |
|---|---|---|
| **حجم غير طبيعي** | استلام اليوم > 3x المتوسط، أو > المعلن بـ 50% | +25/+20 |
| **عملاء جدد فجأة** | عملاء اليوم > 3x المعتاد | +20 |
| **pass-through** | >80% مما يُستلَم يُحوَّل خارجاً (مؤشر غسيل قوي) | +35 |

### 4. ملف مخاطر متجدد لكل تاجر

- `current_risk_score` (EMA — وزن أكبر للأحداث الحديثة)
- `risk_level`: low/medium/high/critical
- إحصاءات: متوسط الحجم، الذروة، عدد العملاء، pass-through ratio
- سجل أحداث (append-only)

### 5. الفلسفة: مراقبة لا إيقاف أعمى

```
critical (70+) → تنبيه عاجل للإدارة (لا إيقاف تلقائي)
  لماذا؟ تجنب إيقاف تاجر شرعي نشط بالخطأ
  الإدارة تراجع وتقرر
```

---

## مقارنة: قبل وبعد

```
قبل v2.9:
  تاجر يستقبل 5 مليون في يوم (معتاده 100 ألف)
  → النظام: لا شيء (ضمن KYC العام)
  → غسيل محتمل يمرّ دون كشف

بعد v2.9:
  نفس الحالة
  → volume_spike مكتشف (+25)
  → exceeds_declared مكتشف (+20)
  → risk_score = 45 → high → flag للإدارة
  → الإدارة تراجع قبل أي ضرر
```

---

## Tests (12)

### `MerchantRiskServiceTest.php`

| Test | يثبت |
|---|---|
| `tiers_have_different_limits` | التصنيف يفرّق الحدود |
| `micro_merchant_blocked_above_single_limit` | حد micro |
| `large_merchant_allows_big_amount` | حد large |
| `merchant_without_profile_treated_as_micro` | الأمان الافتراضي |
| `suspended_merchant_cannot_receive` | الإيقاف |
| `set_tier_updates_limits` | تغيير التصنيف |
| `pass_through_pattern_raises_risk` | نمط الغسيل 3 ⭐ |
| `volume_spike_is_detected` | نمط الغسيل 1 ⭐ |
| `record_transfer_out_accumulates` | تتبع pass-through |
| `risk_score_maps_to_correct_level` | التصنيف الصحيح |
| `risk_events_are_immutable` | عدم التلاعب |
| `dashboard_returns_risk_summary` | لوحة الإدارة |

---

## ⚠️ التكامل المطلوب (مهم)

### ربط المراقبة بمسار استقبال الدفعات

المراقبة جاهزة لكن تحتاج ربطاً في مسار الدفع الفعلي (في Cash6 payment controller):

```php
// قبل قبول دفعة للتاجر:
app(\App\Services\MerchantRiskService::class)
    ->assertReceiveAllowed($merchantId, $amount);

// بعد الدفعة (في الخلفية — non-blocking):
dispatch(function () use ($merchantId, $amount, $customerId) {
    app(\App\Services\MerchantRiskService::class)
        ->analyzeReceived($merchantId, $amount, $customerId);
});

// عند تحويل التاجر خارجاً (لحساب pass-through):
app(\App\Services\MerchantRiskService::class)
    ->recordTransferOut($merchantId, $amount);
```

### إنشاء MerchantProfile للتجار الحاليين

```php
foreach (User::where('type', 3)->get() as $merchant) {
    $limits = MerchantProfile::defaultLimitsForTier('micro'); // ابدأ محافظاً
    MerchantProfile::firstOrCreate(['user_id' => $merchant->id],
        array_merge($limits, ['tier' => 'micro', 'verification_status' => 'unverified']));
}
```

---

## النشر السريع v2.9

```bash
php artisan migrate
php artisan test --filter="MerchantRisk"
```

---

## النسبة الإجمالية

```
v2.8:  ██████████████████████████████ 100%
v2.9:  ██████████████████████████████ 100% (+ مراقبة التجار)
```

### Total Tests

```
v0.6-v2.8: ~274
v2.9:      12 ← جديد
───────────
المجموع: ~286 test
```

---

## الإجابة الكاملة على أسئلتك

### 1. هل كل التجار نفس المستوى؟
**الآن لا.** 4 تصنيفات (micro/small/medium/large)، كل واحد بحدوده.

### 2. ألا يمكن أن يكون غسيل أموال؟
**ممكن — لكن الآن نراقبه.** 3 أنماط غسيل مكتشفة آلياً (حجم، عملاء، pass-through)
مع نقاط مخاطرة وتنبيه للإدارة.

### 3. هل التطبيق جاهز؟
**أقرب بكثير الآن.** التصنيف + المراقبة + الحدود المتدرجة جاهزة. يبقى:
- ربط المراقبة بمسار الدفع (تكامل بسيط)
- وعند الترخيص: تقارير AML الدورية للبنك المركزي (حسب متطلباته)

---

## ملاحظة صادقة عن الحدود

### ما يفعله هذا النظام
- يكشف الأنماط الواضحة للغسيل
- يصنّف التجار حسب المخاطر
- يحدّ من الحجم حسب الفئة
- يُنبّه الإدارة للمراجعة

### ما لا يفعله (يحتاج بشراً + خدمات متخصصة)
- **القرار النهائي**: النظام يُنبّه، لكن الإدارة تقرر (الغسيل المعقّد يحتاج تحقيقاً بشرياً)
- **الشبكات المعقّدة**: غسيل عبر عدة حسابات منسّقة قد يتطلب تحليلاً أعمق
- **التقارير التنظيمية**: عند الترخيص، البنك المركزي قد يطلب تقارير AML بصيغة محددة

**هذا النظام طبقة دفاع قوية، لكنه ليس بديلاً عن:**
- ضابط امتثال (compliance officer) بشري
- خدمة sanction/AML متخصصة
- مراجعة الإدارة للحالات الحرجة

كما هو الحال دائماً: التقنية تكشف وتُنبّه، البشر يقررون.

---

## الصدق المعتاد

**لم أشغّل الـ 286 اختبار** — بيئتي بلا PHP. تحققت من البنية والأقواس.
وفقاً لقاعدة المشروع: ادعاءات حتى تشغّلها أنت.

```bash
php artisan test --filter="MerchantRisk"
```
