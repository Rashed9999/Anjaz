# سجل التغييرات — v2.5 (توسعة AML: Shadow Mode + قواعد سلوكية)

**التاريخ:** 2026-05-22
**النطاق:** AMIAL-AML-002 (بناءً على وثيقة "رصد")

---

## القرار: توسعة AML بدل بناء "رصد" منفصلاً

بعد تحليل وثيقة "رصد"، اكتُشف أن **~70% منها مبني بالفعل** في الـ AML Engine
(v1.4) + RateLimit (v1.0) + AuditService + AgentNetwork (v2.3).

**القرار الهندسي:** توسعة الـ AML الموجود بالأجزاء عالية القيمة فقط، بدل بناء
نظام موازٍ يسبب ازدواجية خطيرة (قاعدتا مخاطرة، منطقان للإيقاف).

### ما أضافته "رصد" فعلاً (الفجوات الحقيقية)

| من الوثيقة | الحالة | القرار |
|---|---|---|
| محرك قواعد + risk score | ✅ موجود (AML v1.4) | لم يُكرَّر |
| Rate limiting | ✅ موجود (v1.0) | لم يُكرَّر |
| Structuring + Velocity | ✅ موجود | لم يُكرَّر |
| **Shadow Mode** | ❌ | **بُني** ⭐ |
| **Circular Transfers** | ❌ | **بُني** |
| **Agent Velocity** | ⚠️ عام فقط | **بُني (خاص بالوكيل)** |
| WAF Webhook | ❌ | أُجِّل (يعتمد Cloudflare) |
| Device banning | ⚠️ جزئي | أُجِّل (يحتاج بنية أجهزة) |

---

## A. Shadow Mode ⭐ (الأهم)

### الفكرة (من الوثيقة)

> "عند الإطلاق، شغّل النظام في وضع المراقبة فقط — يصدر تنبيهات دون إيقاف فعلي،
>  لضبط القواعد وتقليل الإيقافات الخاطئة."

### لماذا حاسم؟

محركات مكافحة الاحتيال الجديدة تُنتج **false positives كثيرة** أول الإطلاق.
لو أوقفت حسابات عملاء أبرياء يوم الإطلاق → تفقد ثقتهم فوراً.

### كيف يعمل

```
قاعدة بـ shadow_mode = true:
  ✓ تُقيَّم عادياً
  ✓ تُسجَّل في aml_shadow_decisions ("كان سيحدث: block")
  ✗ لا تُطبَّق فعلياً (القرار الحقيقي يتجاهلها)

→ تجمع بيانات لأسابيع → تضبط العتبات → تطفئ shadow_mode → تفعّل
```

### التطبيق

- عمود `shadow_mode` على `aml_rules`
- جدول `aml_shadow_decisions` (would_be_action مقابل actual_action)
- في `AmlScreeningService`: القواعد shadow تُحسب في "القرار الافتراضي" لكن
  تُستثنى من القرار الفعلي، ويُسجَّل الفرق

---

## B. CircularTransferRule (حوالات دائرية)

كشف نمط غسل الأموال:

```
دورة مباشرة:   A → B ثم B → A  (خلال 24 ساعة)
دورة غير مباشرة: A → B → C → A
```

يُستخدم لـ: غسل الأموال، تضخيم حجم وهمي لاستغلال عمولات.

**الإعدادات:** window_hours (24)، min_cycle_amount (5000).

---

## C. AgentVelocityRule (نشاط وكيل غير طبيعي)

من الوثيقة مباشرة:

> "إذا قام الوكيل بعمليات Cash-In لعدد كبير من العملاء بمبالغ متطابقة خلال
>  دقائق، يتدخل رصد."

يكشف:
- 5+ عمليات cash-in بمبالغ **متطابقة** خلال 10 دقائق
- خدمة عدد كبير من العملاء المختلفين (15+) خلال النافذة

يشير إلى: تبييض موزّع، عمليات صورية، حساب وكيل مخترَق.

---

## Tests (10)

### `AmlBehavioralRulesTest.php`

| Test | يثبت |
|---|---|
| `circular_transfer_detects_direct_cycle` | A→B→A |
| `circular_transfer_ignores_small_amounts` | عتبة المبلغ |
| `circular_transfer_no_match_without_reverse` | لا إنذار كاذب |
| `agent_velocity_detects_identical_amounts` | مبالغ متطابقة |
| `agent_velocity_ignores_non_agent_transactions` | نطاق القاعدة |
| `agent_velocity_allows_normal_activity` | لا إنذار كاذب |
| `shadow_rule_does_not_block_but_logs` | **Shadow لا يوقف** ⭐ |
| `non_shadow_rule_blocks_normally` | الفعلي يوقف |
| `shadow_decision_only_logged_when_differs` | تسجيل ذكي |

---

## النشر السريع v2.5

```bash
php artisan migrate
php artisan db:seed --class=AmlDefaultRulesSeeder  # يضيف القاعدتين الجديدتين (shadow)
php artisan test --filter="AmlBehavioral"

# بعد أسابيع من المراقبة، راجع ماذا كان سيحدث:
php artisan tinker
>>> DB::table('aml_shadow_decisions')
       ->select('would_be_action', DB::raw('count(*) as n'))
       ->groupBy('would_be_action')->get();
# لو الأرقام معقولة → فعّل القواعد:
>>> \App\Models\Aml\AmlRule::whereIn('code', ['CIRCULAR_TRANSFER','AGENT_VELOCITY_IDENTICAL'])
       ->update(['shadow_mode' => false]);
```

---

## النسبة الإجمالية

```
v2.4:  ██████████████████████████████ 100%
v2.5:  ██████████████████████████████ 100% (+ AML أعمق)
```

### Total Tests

```
v0.6-v2.4: ~242
v2.5:      10 ← جديد
───────────
المجموع: ~252 test
```

---

## رأيي الصادق في وثيقة "رصد"

### ما أحسنت الوثيقة فيه ✓
- **Shadow Mode**: فكرة ممتازة، طبّقناها
- **Non-blocking (Queues)**: مبدأ سليم، الـ AML يدعمه
- **قواعد سلوكية محددة**: واقعية ومفيدة

### ما كان مكرراً (موجود مسبقاً)
- محرك القواعد، risk scoring، rate limiting، audit — كلها مبنية

### ما أُجِّل بوعي
- **WAF Webhook**: يعتمد على Cloudflare. مفيد لكن ليس أولوية الآن، ويُضاف
  لاحقاً دون إعادة تصميم (مجرد listener جديد).
- **Device/IP banning كامل**: يحتاج بنية تتبع أجهزة. الموجود (cert pinning +
  VPN كإشارة) كافٍ مبدئياً.

### الخلاصة

الوثيقة جيدة، لكن بناء "رصد" كنظام منفصل كان سيُنتج **ازدواجية**. الأصح —
وما فعلناه — توسعة الـ AML الموجود. النتيجة: نظام واحد متماسك بدل نظامين
متنافسين على نفس القرار.

---

## ملاحظة أمنية مهمة عن Shadow Mode

الـ Shadow Mode ليس مجرد ميزة تقنية — إنه **فلسفة إطلاق مسؤولة**:

1. **أطلق القواعد الجديدة في shadow دائماً**
2. **راقب aml_shadow_decisions أسابيع**
3. **اضبط العتبات حتى تقل الإنذارات الكاذبة**
4. **فعّل تدريجياً** (flag أولاً، ثم hold، ثم block)

هذا يحمي عملاءك الأبرياء من الإيقاف الخاطئ، ويحمي سمعتك يوم الإطلاق.

**لا تفعّل block على قاعدة جديدة مباشرة. أبداً.**
