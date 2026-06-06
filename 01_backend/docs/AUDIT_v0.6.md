# تقرير الفحص الأمني والمالي — Cash6 → Amial Pay v0.6

**التاريخ:** 2026-05-15
**النطاق:** REFACTOR-CORE-AMIAL-001 (القلب المالي فقط)
**الملفات المفحوصة:**
- `app/Traits/TransactionTrait.php` (560 سطر)
- `app/CentralLogics/Helpers.php` (973 سطر)
- `app/CentralLogics/SmsModule.php` (159 سطر)
- `app/Models/EMoney.php`، `app/Models/Transaction.php`، `app/Models/User.php`
- `app/Http/Controllers/Api/V1/Customer/TransactionController.php`
- `database/migrations/2021_12_01_063108_create_e_money_table.php`
- `database/migrations/2021_12_01_053955_create_transactions_table.php`
- `database/migrations/2014_10_12_000000_create_users_table.php`

---

## 1. النقاط الحرجة (Critical) — تمنع الإطلاق

### 1.1 ❌ غياب كامل لـ `lockForUpdate` — **خسارة مال مضمونة تحت الحمل**

**الموقع:** كل دوال `TransactionTrait.php` — 6 دوال مالية.

```php
// السطر 35:
$senderEmoney = EMoney::where('user_id', $from_user_id)->first();
$senderEmoney->current_balance -= $total_amount;
$senderEmoney->save();
```

**السيناريو الكارثي:**
- المستخدم رصيده 100.
- يطلق طلبَيْ تحويل متزامنَيْن (T1=80, T2=80) من جهازين أو بـ retry.
- T1 يقرأ balance=100. T2 يقرأ balance=100 (نفس اللحظة).
- T1 يحفظ 100-80=20. T2 يحفظ 100-80=20.
- النتيجة: تم خصم 80 فقط، لكن المستقبِل (أو شخصان) استلموا 160.
- **العميل سرق 80 من النظام.**

**الإصلاح:** `EMoney::where('user_id', $id)->lockForUpdate()->first()` داخل `DB::transaction`، مع `READ COMMITTED` على الأقل.

---

### 1.2 ❌ `customer_send_money_transaction` لا تفحص الرصيد إطلاقاً

**الموقع:** `TransactionTrait.php:35-37`

```php
$senderEmoney = EMoney::where('user_id', $from_user_id)->first();
$senderEmoney->current_balance -= $total_amount;  // ← يصبح سالباً بدون قيود!
$senderEmoney->save();
```

**على عكس** `customer_cash_out_transaction` التي تفحص `if ($balance >= $total)`، هذه الدالة **لا تفحص شيئاً**. يمكن للمستخدم إرسال مبلغ أكبر من رصيده والوصول لرصيد سالب.

**النتيجة:** انتهاك مباشر لمعيار القبول رقم 1: "لا يوجد رصيد سالب".

---

### 1.3 ❌ `transaction_id` يستخدم `Str::random(5) . timestamp` — تصادمات مضمونة

**الموقع:** TransactionTrait — 16 موضعاً، Helpers.php:675

```php
'transaction_id' => Str::random(5) . Carbon::now()->timestamp,
```

**الحساب:**
- `Str::random(5)` = 62^5 = ~916M احتمال
- `Carbon::now()->timestamp` = ثوانٍ (لا ميلي ثانية)
- في نفس الثانية الواحدة تحت حمل 100 trans/sec، احتمال التصادم = ~1% (birthday problem)
- **لا يوجد UNIQUE INDEX** على `transaction_id` في migration الأصلي → التصادم يدخل DB بصمت

**النتيجة:** انتهاك معيار القبول رقم 2 و4 من الوثيقة (idempotency و ULID/UUID).

**الإصلاح:** ULID + UNIQUE INDEX.

---

### 1.4 ❌ FLOAT للأموال — أخطاء تقريب ستظهر

**الموقع:**
- migration: `float('current_balance', 14, 2)` (MySQL `FLOAT(14,2)`)
- model casts: `'current_balance' => 'float:4'`
- العمليات: `$balance -= $total_amount` في PHP باستخدام float

**المشكلة:**
```php
0.1 + 0.2 === 0.3  // false في PHP!
0.1 + 0.2          // 0.30000000000000004
```

**النتيجة:** بعد آلاف العمليات، رصيد التاجر سينحرف بـ سنتات. التسويات اليومية ستفشل. **انتهاك معيار 4 و23**.

**الإصلاح:** `DECIMAL(20,4)` على المستوى الحالي، مع خطة انتقال نحو **minor units (BIGINT)** في v0.7.

---

### 1.5 ❌ `pin_check` يقارن PIN بـ password — اختراق واحد = خسارة كاملة

**الموقع:** `Helpers.php:407-416`

```php
public static function pin_check(int $user_id, string $pin): bool
{
    $user = User::find($user_id);
    if (Hash::check($pin, $user->password)) {  // ← نفس password!
        return true;
    }
    return false;
}
```

**النتيجة:**
- لا يوجد `transaction_pin` في `users` table أصلاً.
- إذا سُرق password (phishing/dump) → سرقة مال فورية بدون عائق ثانٍ.
- **انتهاك صريح لقسم 9 من الوثيقة (PIN-SECURITY-AMIAL-001)**.

---

### 1.6 ❌ غياب Idempotency — كل retry = عملية مكررة

**الموقع:** في كل مكان. لا يوجد `idempotency_key` في DB ولا middleware.

**السيناريو:**
- العميل يرسل تحويل، الشبكة تنقطع قبل وصول الرد.
- التطبيق يعمل retry → الـ API ينفذ التحويل **مرتين**.
- **انتهاك معيار القبول رقم 2**: "لا توجد عملية مالية مكررة بسبب retry".

---

### 1.7 ❌ `earned_charge` يُضاف لمستخدمين عاديين

**الموقع:** `Helpers.php:683-705` (دالة `updateEmoney`)

```php
} else {  // ← غير admin
    if (strtolower($type) === 'debit') {
        $emoney->current_balance -= $amount;
    } elseif (strtolower($type) === 'credit') {
        $emoney->current_balance += $amount;
    } else {
        throw new InvalidArgumentException(...);
    }
    $emoney->charge_earned += $charge;  // ← bug! للمستخدم العادي
}
```

السطر `$emoney->charge_earned += $charge` داخل فرع المستخدم العادي → كل عملية على مستخدم عادي تضيف للرسوم المكتسبة في عموده. **مذكور حرفياً في الوثيقة**.

**النتيجة:** تقارير الإيرادات مغشوشة، والتسويات معطوبة.

---

### 1.8 ❌ `setEnvironmentValue` تكتب على `.env` في runtime

**الموقع:** `Helpers.php:805-822`، مستدعاة من `InstallController` و `UpdateController`.

```php
public static function setEnvironmentValue(string $envKey, string $envValue): string
{
    $envFile = app()->environmentFilePath();
    $str = file_get_contents($envFile);
    // ...
    $fp = fopen($envFile, 'w');
    fwrite($fp, $str);
    fclose($fp);
}
```

**المشاكل:**
1. لا قفل (`flock`) → race condition يفسد `.env`.
2. لا فحص `app()->environment()` → تعمل في production.
3. لا فحص صلاحيات → أي مستخدم يصل لـ `/install` أو `/update` يكتب `.env`.
4. Routes `install.php` و `update.php` موجودة حتى بعد التنصيب.

**النتيجة:** انتهاك معيار القبول رقم 9 (لا install/update مفتوح في production).

---

### 1.9 ❌ الإشعارات داخل `DB::transaction` — HTTP roundtrips تحت قفل

**الموقع:** كل دوال TransactionTrait. مثال السطر 54:

```php
return DB::transaction(function () use (...) {
    // ... خصم وحفظ
    Helpers::send_transaction_notification(...);  // ← HTTP لـ FCM!
    // ... تكملة العملية
});
```

`send_transaction_notification` → `send_push_notif_to_device` → `sendNotificationToHttp` → `Http::post('fcm.googleapis.com')` **مرتين** (مرة لـ access_token ومرة للإشعار).

**النتيجة:**
- كل transaction = 2 HTTP requests محتفظ بـ DB transaction مفتوحاً.
- FCM بطيء أو متعطل → الـ DB transaction يبقى مفتوحاً → row locks مستمرة → deadlocks.
- **انتهاك معيار 10**: "الإشعارات وPDF والتصدير تعمل عبر queue".

---

### 1.10 ❌ `getAccessToken` يستدعى في كل إشعار

**الموقع:** `Helpers.php:47-68`

كل إشعار يجلب access_token جديداً من Google (1 ساعة عمر) دون caching. **انتهاك مباشر لقسم 5 من الوثيقة**: "تخزين Firebase access token في cache".

---

### 1.11 ❌ `translate()` تكتب على disk عند كل مفتاح مفقود

**الموقع:** `Helpers.php:953-970`

```php
function translate(string $key): string
{
    // ...
    if (!array_key_exists($key, $lang_array)) {
        $lang_array[$key] = $processed_key;
        $str = "<?php return " . var_export($lang_array, true) . ";";
        file_put_contents(base_path('resources/lang/' . $local . '/messages.php'), $str);
        // ...
    }
}
```

**المشاكل:**
1. **Code injection محتمل**: إذا احتوى `$key` (من user input) على `'` فإن `var_export` ينتج PHP يكسر الملف، وقد يسمح بحقن كود.
2. **Race condition**: لا قفل ملف، طلبان متزامنان يفسدان الملف.
3. **Performance**: كل API call ينتج عشرات الكتابات على disk.
4. **مذكور حرفياً في الوثيقة**: "حذف تكرار translate".

---

### 1.12 ❌ `SmsModule` يطبع الأخطاء بـ `echo`

**الموقع:** `SmsModule.php:79`

```php
echo 'Error:' . curl_error($ch);
```

**النتيجة:** يلوث JSON response → كسر العميل (Flutter يفشل في parsing). **مذكور في الوثيقة**.

أيضاً السطر 106-114 منطق مضطرب: يكتب فوق `$response` بنتيجة API ثم يعيد كتابته بـ 'success'/'error' حسب الـ error — النتيجة الفعلية من الـ API ضائعة.

---

## 2. النقاط العالية (High)

### 2.1 ⚠️ `Transaction::create` بدون `lockForUpdate` على الصف الذي يبني عليه `balance`

السطر `'balance' => $senderEmoney->current_balance` يخزن snapshot، لكنه snapshot من قراءة غير مقفلة (راجع 1.1).

### 2.2 ⚠️ `return null` داخل `DB::transaction` لا يـ rollback

```php
return DB::transaction(function () {
    if ($balance < $total) {
        return null;  // ← القفل يفلت، لكن إذا كان الحفظ تم قبلها يبقى محفوظاً
    }
});
```

في الكود الحالي الحفظ مشروط `if-else` فلا يحدث ضرر، لكن النمط خطر. الإصلاح: رمي `InsufficientBalanceException`.

### 2.3 ⚠️ `User` model — `$fillable` ناقص و `$hidden` لا يخفي `fcm_token` ولا `transaction_pin`

### 2.4 ⚠️ لا يوجد `audit_decisions` table — لا سجل قرارات النظام

الوثيقة (قسم 4 و23.5) تطلب `decision_code`, `decision_reason` لكل عملية. لا يوجد جدول.

### 2.5 ⚠️ `pending_balance` يُخصم في `accept_withdraw_transaction` بدون lock وبدون فحص فعلي

```php
if ($userEmoney->pending_balance >= $total_amount) {
    $userEmoney->pending_balance -= $total_amount;
    $userEmoney->save();
}
// ↓ يكمل بقية الـ transaction حتى لو الفحص فشل! ←
$primary_transaction = Transaction::create([...]);
```

`if` بدون `else { return; }` → لو الفحص فشل، النظام يكمل تسجيل سحب لم يحدث خصمه.

### 2.6 ⚠️ `get_admin_id()` يستعلم DB في كل عملية

```php
public static function get_admin_id(): int
{
    return User::where('type', 0)->first()->id ?? 1;
}
```

استعلام DB في كل transaction × 2-3 مرات داخل كل دالة. يجب cache.

---

## 3. النقاط المتوسطة (Medium)

| # | الموقع | المشكلة |
|---|---|---|
| 3.1 | `upload()` Helpers:176 | يستخدم `getimagesize` فقط — لا يفحص extension. ملف PHP بـ payload صورة يمر. |
| 3.2 | `upload()` | يحفظ في `Storage::disk('public')` للهويات والإيصالات — يجب `private` (مذكور في الوثيقة قسم 2). |
| 3.3 | `Transaction` model | لا يوجد `$casts` لـ `idempotency_key` ولا `currency` ولا `zone_code` |
| 3.4 | `Transaction` model | `'amount' => 'float:4'` لكن لا يوجد عمود `amount` في migrations |
| 3.5 | `EMoney` | `protected $guarded = []` — mass assignment لكل الحقول |
| 3.6 | Migrations | لا يوجد INDEX على `from_user_id`, `to_user_id`, `transaction_type` |
| 3.7 | `requestSender()` Helpers:824 | يستدعي `LaravelchkController` بدون try/catch ولا timeout — توقف خارجي = توقف داخلي |

---

## 4. التصنيف النهائي للملفات

| الملف | التصنيف | السبب |
|---|---|---|
| `app/Traits/TransactionTrait.php` | **REPLACE** | إعادة كتابة كاملة مع locks + idempotency + exceptions |
| `app/CentralLogics/Helpers.php` | **MERGE** | احتفاظ بالدوال السليمة، استبدال `updateEmoney`, `pin_check`, `setEnvironmentValue`, `translate`, `getAccessToken`, `send_transaction_notification` |
| `app/CentralLogics/SmsModule.php` | **REPLACE** | إزالة echo، إضافة Logger، تنظيف منطق two_factor/msg_91 |
| `app/Models/EMoney.php` | **REPLACE** | casts → decimal:4، fillable مقيد، scope helper |
| `app/Models/Transaction.php` | **REPLACE** | casts → decimal:4، fillable موسّع لـ idempotency/zone، scopes جديدة |
| `app/Models/User.php` | **MERGE** | إضافة `$hidden` لـ `transaction_pin` و `fcm_token`، Mutator لـ pin |
| `app/Http/Controllers/Api/V1/Customer/TransactionController.php` | **REVIEW** | يحتاج تعديل بسيط (idempotency middleware يلتقطه) — تعديل في v0.7 |
| `routes/install.php`, `routes/update.php` | **DELETE** بعد التنصيب | لا تترك مفتوحة (معيار 9) |
| **ملفات جديدة (ADD):** | | |
| `app/Services/FinancialGuardService.php` | ADD | فحص مركزي للرصيد |
| `app/Services/IdempotencyService.php` | ADD | إدارة مفاتيح idempotency |
| `app/Services/MoneyService.php` | ADD | تحويلات decimal/minor units |
| `app/Services/TransactionPinService.php` | ADD | PIN منفصل |
| `app/Services/FirebaseTokenService.php` | ADD | cache لـ access_token |
| `app/Services/EnvironmentGuardService.php` | ADD | حماية .env |
| `app/Jobs/SendTransactionNotificationJob.php` | ADD | إشعارات async |
| `app/Http/Middleware/EnforceIdempotency.php` | ADD | middleware للـ API المالي |
| `app/Exceptions/InsufficientBalanceException.php` | ADD | exception واضح |
| `app/Exceptions/DuplicateTransactionException.php` | ADD | exception للـ idempotency |
| `app/Exceptions/EnvironmentMutationBlockedException.php` | ADD | منع كتابة .env |
| `database/migrations/2026_05_15_*` (6 migrations) | ADD | jobs أعلاه |

---

## 5. خطة الاختبار (TDD)

كل إصلاح يتبع Red→Green:

1. `ConcurrentSendMoneyTest` — يرسل تحويلَيْن متزامنَيْن، يتأكد ألا يصبح رصيد سالباً.
2. `IdempotencyTest` — نفس `Idempotency-Key` مرتين، عملية واحدة فقط.
3. `PinSeparationTest` — تغيير password لا يغير PIN، PIN صحيح يمر، PIN خاطئ يفشل.
4. `EarnedChargeBugTest` — عملية على مستخدم عادي، `charge_earned` لا يتغير عنده، يزداد عند admin فقط.
5. `EnvironmentMutationTest` — `setEnvironmentValue` في production يرمي exception.
6. `TransactionIdUniqueTest` — توليد 10,000 ULID متزامنة، لا تصادمات.
7. `MoneyDecimalTest` — `0.1 + 0.2 = 0.3` exactly عبر MoneyService.
8. `AsyncNotificationTest` — `Bus::fake()`، تحويل ينشر Job ولا يستدعي FCM داخل DB.
9. `FirebaseTokenCacheTest` — استدعاءان متتاليان لـ getAccessToken، HTTP واحد فقط.
10. `BalanceLockTest` — مع `pessimistic_locks` mock، التأكد من `lockForUpdate`.

---

## 6. التحقق من معايير القبول (قسم 23 من الوثيقة)

| # | المعيار | الحالة قبل v0.6 | بعد v0.6 |
|---|---|---|---|
| 1 | لا رصيد سالب | ❌ مكسور | ✅ FinancialGuardService + lockForUpdate |
| 2 | لا عملية مكررة بـ retry | ❌ مكسور | ✅ IdempotencyService + middleware |
| 3 | كل debit داخل DB::transaction | ⚠️ معظمها داخل، الإشعار يلوث | ✅ async notifications |
| 4 | كل debit يستخدم lockForUpdate | ❌ مكسور | ✅ TransactionTrait معاد |
| 5 | كل عملية لها audit/decision | ❌ غير موجود | ⏳ جدول جاهز، الكتابة في v0.7 |
| 6 | كل عملية خارج SOUTH ترفض | ❌ غير موجود | ⏳ v0.7 (ZONE-001) |
| 7 | موافقة على سياسة | ❌ غير موجود | ⏳ v0.7 (LEGAL-001) |
| 8 | لا token/PIN/OTP في logs | ⚠️ يحتاج فحص config/logging | ✅ Sanitizer + log channel |
| 9 | لا install/update في production | ❌ مفتوح | ✅ EnvironmentGuard + route guards |
| 10 | إشعارات/PDF/تصدير عبر queue | ❌ مكسور | ✅ Job |
| 11-15 | (ميزات لاحقة) | — | v0.8+ |

---

## 7. مخاطر متبقية بعد v0.6 (تُغلق في v0.7+)

- لا يوجد فحص zone بعد (تحويلات خارج جنوب لا تزال تعمل).
- TransactionLimit لا يزال يقرأ بدون lock (مذكور في 2.x، حلّ خفيف يكفي الآن).
- بعض dependencies غير محقّقة (Sanctum vs Passport — يستخدم Passport، نتركه).
- `Customer/TransactionController` لا يلتف بـ idempotency middleware بعد — `routes/api.php` يحتاج edit في الدفعة التالية.

**النتيجة:** v0.6 يُغلق القلب المالي. v0.7 يغلق Zone+Legal+PIN-Recovery. v0.8+ كما في الوثيقة.
