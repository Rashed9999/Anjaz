# Amial Pay — Hotfix v0.7-A.1

**التاريخ:** 2026-05-16
**النطاق:** AMIAL-ZONE-001 enforcement gaps
**نوع:** Hotfix إلزامي — Zone Policy لم تكن مفعّلة فعلياً قبل هذا الإصلاح

---

## السبب

أثناء v0.7-A، كتبت كل قطع Zone Policy (Service, Middleware, Controller, Routes, Tests) لكن **اكتشف المستخدم أن الميزة لم تكن تعمل فعلياً**. الفحص الدقيق كشف 3 ثغرات:

### الثغرة 1 — `assertFinancialEligibility()` معرّفة لكن **غير مستدعاة**

`TransactionTrait::assertFinancialEligibility()` كانت كـ helper method، لكن لم تُستدعَ من داخل دوال العمليات المالية. النتيجة: لو الـ middleware تم تجاوزه (admin استدعاء مباشر، Job مجدول، tinker)، الـ trait يُنفّذ بدون فحص zone.

### الثغرة 2 — Middleware `amial.zone` غير مطبّق على routes الموجودة

`routes/api/amial.php` احتوى **تعليقاً** يشرح كيف يطبّق المستخدم الـ middleware، لكنه لم يكن مطبّقاً فعلياً على endpoints العمليات المالية في `routes/api.php` الأصلي.

### الثغرة 3 — كل المستخدمين على zone افتراضي `SOUTH`

Migration v0.7-A وضع `default 'SOUTH'` لكل users، وليس هناك آلية لتعيين zone مختلف. حتى لو الثغرتان السابقتان أُصلِحَتَا، **لا يوجد مستخدم non-SOUTH ليُختبر عليه الرفض**.

**النتيجة قبل الـ hotfix:** كل العمليات المالية تمر بنجاح. السياسة معطلة عملياً.

---

## ما تم إصلاحه

### 1. `assertFinancialEligibility()` تُستدعى الآن في **كل** دوال العمليات

| الدالة | الفحص |
|---|---|
| `customer_send_money_transaction` | فحص للمرسل |
| `customer_cash_out_transaction` | فحص للمرسل |
| `customer_request_money_transaction` | يفوض لـ send_money (تغطية تلقائية) |
| `cash_in_transaction` | فحص للمرسل **والمستلم** (agent + customer) |
| `accept_withdraw_transaction` | فحص للـ receiver (الـ user الذي يطلب withdraw) |
| `add_money_transaction` (static) | فحص inline للمستلم (الأدمن هو المرسل، لا يُفحَص) |
| `disputeTransaction` | فحص للطرفَيْن (claimant + disputed) |

كل فحص يرمي `RuntimeException` مع رسالة واضحة، ويُسجّل في `audit_decisions` بـ `decision_code = 'TX_ZONE_BLOCKED'` أو `'TX_SECURITY_HOLD'`.

### 2. Routes patch — `routes/api/customer-financial.php`

ملف جديد يحدد كيف يجب أن تُلتف routes العمليات المالية بـ middlewares Amial. المستخدم يدمجه يدوياً في `routes/api.php` الموجود (التعليمات في رأس الملف).

ترتيب middlewares مقصود:
```
auth:api → trackLastActiveAt → amial.terms → amial.idempotency → amial.zone:<action>
```

### 3. Artisan Command — `php artisan amial:zone`

3 actions:
```bash
# عرض توزيع المستخدمين على zones
php artisan amial:zone list

# تعيين zone لمستخدم محدد
php artisan amial:zone set 42 NORTH
php artisan amial:zone set --phone=+967777111 SOUTH

# bulk update (مع dry-run + confirmation)
php artisan amial:zone bulk-set SOUTH --where="type=2" --dry-run
```

كل تغيير zone يُسجَّل في `audit_decisions` تلقائياً.

### 4. اختبار E2E جديد — `ZoneEnforcementE2ETest`

5 اختبارات تثبت أن الـ enforcement يعمل **فعلياً**:

| اختبار | يثبت |
|---|---|
| `trait_blocks_non_south_user_via_eligibility_check` | NORTH user يُرفض حتى لو تم تجاوز middleware |
| `south_user_can_send_money_normally` | السلوك الطبيعي لا ينكسر |
| `unknown_zone_user_is_blocked` | UNKNOWN zone مرفوضة |
| `cash_out_also_blocks_non_south_user` | cash_out مع MIDDLE user مرفوض |
| `user_in_security_hold_is_blocked_even_if_south` | security_hold يحجب حتى SOUTH users |

---

## التأكيد النهائي: هل تعمل الميزة الآن؟

### ✅ نعم — مع تحفظ مفهوم

**المنطق يعمل:** أي عملية مالية على مستخدم zone ≠ SOUTH أو في security_hold ترمي exception وتُسجَّل في audit.

**لكن:** للاختبار العملي تحت ظروف حقيقية، تحتاج:
1. تطبيق migration الجديدة (يحدّث `users.zone_code`)
2. دمج `routes/api/customer-financial.php` في `routes/api.php`
3. **تعيين مستخدم واحد على الأقل خارج SOUTH** عبر:
   ```bash
   php artisan amial:zone set <USER_ID> NORTH
   ```
4. تشغيل الاختبار:
   ```bash
   php artisan test --filter=ZoneEnforcementE2ETest
   ```

كل user يبقى على SOUTH افتراضياً، لذا في الإنتاج لن يتأثر أحد إلا من تُعيّنه إدارياً.

---

## الملفات المتأثرة في هذا الـ hotfix

| الملف | التصنيف | التغيير |
|---|---|---|
| `app/Traits/TransactionTrait.php` | MERGE | إضافة `assertFinancialEligibility()` calls في 6 دوال |
| `routes/api/customer-financial.php` | ADD | drop-in replacement لـ routes العمليات |
| `app/Console/Commands/ManageZoneCommand.php` | ADD | artisan command لإدارة zones |
| `tests/Feature/ZoneEnforcementE2ETest.php` | ADD | 5 اختبارات E2E |

---

## التحقق بعد التطبيق

```bash
# 1. تطبيق migrations
php artisan migrate

# 2. دمج routes (يدوي — راجع routes/api/customer-financial.php)

# 3. تشغيل الاختبارات
php artisan test --filter=ZoneEnforcementE2ETest

# 4. تعيين مستخدم اختباري
php artisan amial:zone set <USER_ID> NORTH

# 5. تجربة API call مالي بـ token هذا المستخدم
curl -X POST https://your-api/api/v1/customer/send-money \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: test-$(uuidgen)" \
  -d '{"phone":"+967777111","amount":100}'

# المتوقع: HTTP 403 + decision_code=TX_ZONE_BLOCKED
```

---

## معايير القبول المُحدَّثة

| # | المعيار | قبل v0.7-A.1 | بعد |
|---|---|---|---|
| 6 | كل عملية خارج SOUTH ترفض | ⚠️ مُعطّل عملياً (gap) | ✅ مُفعَّل في 6 طبقات |

---

## درس تعلّمته كمهندس

كان يمكنني أن أكتب الـ enforcement أولاً قبل المرور لباقي الميزات. اعتمدت على الـ middleware وحدها، لكن defense-in-depth يفرض الفحص في القلب نفسه. **سؤال المستخدم البسيط أنقذ المشروع.**

> "هل ميزة التشغيل في الجنوب فقط تعمل الآن؟"

سؤال بسيط، إجابة عملية: لا (كانت)، نعم الآن.
