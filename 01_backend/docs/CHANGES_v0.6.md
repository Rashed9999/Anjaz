# سجل التغييرات — Amial Pay v0.6 (Core Refactor)

**التاريخ:** 2026-05-15
**نطاق الدفعة:** REFACTOR-CORE-AMIAL-001 + PIN-SECURITY-AMIAL-001 (جزئياً)
**القاعدة:** WORKFLOW-AMIAL-001 (دفعات من المصدر الأصلي)

---

## 1. جدول التصنيف الكامل

| # | المسار | التصنيف | المرجع | السبب |
|---|---|---|---|---|
| 1 | `app/Traits/TransactionTrait.php` | **REPLACE** | REFACTOR-CORE-001 | إعادة كتابة كاملة: locks + idempotency + audit + async notifications |
| 2 | `app/Models/EMoney.php` | **REPLACE** | REFACTOR-CORE-001 | decimal:4 casts + fillable صريح + guard على negative balance |
| 3 | `app/Models/Transaction.php` | **REPLACE** | REFACTOR-CORE-001 | decimal:4 + idempotency_key + zone fields + scopes |
| 4 | `app/Models/User.php` | **MERGE** | PIN-SECURITY-001 | إضافة transaction_pin/hidden/locked_until + revoke tokens على PIN change |
| 5 | `app/CentralLogics/Helpers.php` | **MERGE** | متعدد | 8 دوال معدّلة (ملف patch منفصل) |
| 6 | `app/CentralLogics/SmsModule.php` | **REPLACE** | REFACTOR-CORE-001 | حذف echo + PSR-3 logs + timeout + إصلاح منطق two_factor/msg91 |
| 7 | `app/Models/IdempotencyKey.php` | **ADD** | REFACTOR-CORE-001 | جديد |
| 8 | `app/Models/AuditDecision.php` | **ADD** | REFACTOR-CORE-001 | جديد — append-only |
| 9 | `app/Models/AccountSecurityEvent.php` | **ADD** | PIN-SECURITY-001 | جديد |
| 10 | `app/Services/MoneyService.php` | **ADD** | REFACTOR-CORE-001 | BC Math بدلاً من float |
| 11 | `app/Services/FinancialGuardService.php` | **ADD** | REFACTOR-CORE-001 | فحص + خصم مركزي |
| 12 | `app/Services/IdempotencyService.php` | **ADD** | REFACTOR-CORE-001 | idempotency keys |
| 13 | `app/Services/AuditService.php` | **ADD** | REFACTOR-CORE-001 | كتابة audit_decisions مع PII sanitization |
| 14 | `app/Services/TransactionPinService.php` | **ADD** | PIN-SECURITY-001 | PIN منفصل + counter + lock |
| 15 | `app/Services/FirebaseTokenService.php` | **ADD** | REFACTOR-CORE-001 | cache لـ FCM access_token |
| 16 | `app/Services/EnvironmentGuardService.php` | **ADD** | REFACTOR-CORE-001 | حماية .env في production |
| 17 | `app/Jobs/SendTransactionNotificationJob.php` | **ADD** | REFACTOR-CORE-001 | إشعارات async عبر queue |
| 18 | `app/Http/Middleware/EnforceIdempotency.php` | **ADD** | REFACTOR-CORE-001 | middleware للـ API المالي |
| 19 | `app/Exceptions/InsufficientBalanceException.php` | **ADD** | REFACTOR-CORE-001 | بديل عن return null |
| 20 | `app/Exceptions/DuplicateTransactionException.php` | **ADD** | REFACTOR-CORE-001 | idempotency conflicts |
| 21 | `app/Exceptions/EnvironmentMutationBlockedException.php` | **ADD** | REFACTOR-CORE-001 | حماية .env |
| 22 | `database/migrations/2026_05_15_100001_amial_create_idempotency_keys_table.php` | **ADD** | REFACTOR-CORE-001 | جديد |
| 23 | `database/migrations/2026_05_15_100002_amial_add_transaction_pin_to_users.php` | **ADD** | PIN-SECURITY-001 | حقول PIN |
| 24 | `database/migrations/2026_05_15_100003_amial_refactor_transactions_table.php` | **ADD** | REFACTOR-CORE-001 | decimal + ULID + idempotency_key + zone |
| 25 | `database/migrations/2026_05_15_100004_amial_refactor_e_money_table.php` | **ADD** | REFACTOR-CORE-001 | decimal + held_balance + zone + version |
| 26 | `database/migrations/2026_05_15_100005_amial_create_audit_decisions_table.php` | **ADD** | REFACTOR-CORE-001 | جدول audit |
| 27 | `database/migrations/2026_05_15_100006_amial_create_account_security_events_table.php` | **ADD** | PIN-SECURITY-001 | جدول أحداث الأمان |
| 28 | `tests/Unit/MoneyServiceTest.php` | **ADD** | tests | 12 اختباراً |
| 29 | `tests/Feature/ConcurrentSendMoneyTest.php` | **ADD** | tests | locks + negative balance |
| 30 | `tests/Feature/IdempotencyTest.php` | **ADD** | tests | 9 اختبارات |
| 31 | `tests/Feature/PinSeparationTest.php` | **ADD** | tests | 11 اختباراً |
| 32 | `tests/Feature/EarnedChargeBugTest.php` | **ADD** | tests | 4 اختبارات |
| 33 | `routes/install.php` | **REVIEW** | REFACTOR-CORE-001 | تُحاط بـ middleware يمنعها في production (في الدفعة التالية) |
| 34 | `routes/update.php` | **REVIEW** | REFACTOR-CORE-001 | كذلك |
| 35 | `app/Http/Controllers/Api/V1/Customer/TransactionController.php` | **REVIEW** | REFACTOR-CORE-001 | يلتف بـ `amial.idempotency` middleware عبر routes — لا يحتاج تعديل داخلي |

---

## 2. ملخص التغييرات لكل ملف معدّل

### `app/Traits/TransactionTrait.php` (REPLACE)
- 6 دوال مالية أُعيدت كتابتها (`customer_send_money_transaction`, `customer_cash_out_transaction`, `customer_request_money_transaction`, `cash_in_transaction`, `add_money_transaction`, `accept_withdraw_transaction`, `disputeTransaction`).
- كل قراءة EMoney → `FinancialGuardService::lockWallet()` (lockForUpdate).
- كل خصم → `FinancialGuardService::debit()` (يرمي InsufficientBalanceException عند الفشل).
- `transaction_id` = ULID بدلاً من `Str::random(5).timestamp`.
- إشعارات async عبر `DB::afterCommit` + `SendTransactionNotificationJob`.
- كل عملية تكتب `audit_decision`.
- بصمة (signature) متوافقة للخلف — الـ controllers الحالية لا تحتاج تعديل.

### `app/Models/EMoney.php` (REPLACE)
- `$casts`: `float:4` → `decimal:4` (Laravel يعيد string، لا float).
- `$guarded` → `$fillable` صريح.
- إضافة `held_balance`, `zone_code`, `version`.
- `scopeLockedFor()` لتبسيط الاستخدام.
- `booted()` يرفض حفظ رصيد سالب (defense in depth).

### `app/Models/Transaction.php` (REPLACE)
- إضافة `idempotency_key`, `amount`, `decision_code`, `decision_reason`, `zone_code`, `request_zone`, `counterparty_zone` في `$fillable` و `$casts`.
- `scopeByIdempotencyKey()`, `scopeInZone()`.

### `app/Models/User.php` (MERGE)
- `$hidden`: + `transaction_pin`, `fcm_token`.
- `$casts`: + الحقول الجديدة لـ PIN.
- `$casts['transaction_pin'] = 'hashed'` — Laravel يطبق `Hash::make` تلقائياً.
- `boot()`: عند تغيير `transaction_pin` نُبطل كل tokens ونمسح `fcm_token`.
- relation `securityEvents()`.

### `app/CentralLogics/Helpers.php` (MERGE)
انظر `Helpers_PATCH.php` للتفاصيل. 8 دوال معدّلة:
- `pin_check` → يفوّض لـ `TransactionPinService` (لا يقارن بـ password).
- `updateEmoney` → يستخدم lockForUpdate + يحذف bug earned_charge للمستخدم العادي.
- `setEnvironmentValue` → يفوض لـ `EnvironmentGuardService` (يرمي exception في production).
- `send_transaction_notification` → يطلق Job بدلاً من HTTP مباشر.
- `getAccessToken` → يفوض لـ `FirebaseTokenService` (مع cache).
- `get_admin_id` → مع `Cache::remember`.
- `translate()` → لا كتابة على disk، لا injection.
- `upload` → فحص MIME ثلاثي + خيار `$private`.

### `app/CentralLogics/SmsModule.php` (REPLACE)
- حذف `echo` (السطر 79 الأصلي).
- إصلاح منطق `two_factor` و `msg_91` المضطرب (كان يكتب فوق response ثم يتجاهلها).
- استبدال `curl_init` بـ Laravel `Http` (timeout 10s).
- PSR-3 logging مع رقم هاتف ممحو جزئياً.
- لا OTP في الـ logs.

---

## 3. خطة الاختبار

### الاختبارات المُسلَّمة (36 اختبار)

| الملف | الاختبارات | يغطي |
|---|---|---|
| `MoneyServiceTest` | 12 | AUDIT 1.4 (float precision) |
| `ConcurrentSendMoneyTest` | 4 | AUDIT 1.1, 1.2 (locks + negative balance) |
| `IdempotencyTest` | 9 | AUDIT 1.6 (duplicate prevention) |
| `PinSeparationTest` | 11 | AUDIT 1.5 + قسم 9 من الوثيقة |
| `EarnedChargeBugTest` | 4 | AUDIT 1.7 |

### اختبارات يدوية مطلوبة قبل النشر

1. **Migration على dump فعلي من production** (مرحلة staging).
2. **Load test** بـ k6 أو JMeter — 100 trans/sec لـ 5 دقائق:
   - 0% negative balance
   - 0% duplicate transaction_id
   - 0% data corruption
3. **Soak test** ليلة كاملة عند 10 trans/sec — التأكد من عدم memory leak في Queue worker.
4. **Failover test** — قطع Redis أثناء التشغيل، التأكد أن DB لا يفقد بيانات.

### اختبارات يجب أن تُضاف في v0.7 (خارج نطاق v0.6)

- `EnvironmentMutationTest` — يحتاج config mock في PHPUnit
- `AsyncNotificationTest` — Bus::fake + assertion على Job dispatched
- `FirebaseTokenCacheTest` — Cache::spy + HTTP::fake
- `TransactionIdUniqueTest` — توليد 10K ULIDs، فحص بـ DB unique constraint

---

## 4. متطلبات النشر (Deployment Requirements)

### مسبقات
- PHP 8.2+ (لـ readonly properties في exceptions)
- MySQL 5.7+ أو MariaDB 10.3+ (لـ DECIMAL + row locks)
- Redis 6.0+ (لـ cache + queue)
- BC Math extension (للـ MoneyService)
- وحدة Queue worker تعمل (`php artisan queue:work` أو supervisor)

### تكوين مطلوب في `.env`
```env
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# للـ FirebaseTokenService
# (يقرأ المفتاح من business_settings، لا حاجة لـ env)

# للـ EnvironmentGuardService
INSTALL_COMPLETED=true  # بعد التنصيب
```

### Queue worker
```bash
php artisan queue:work redis --queue=notifications --sleep=3 --tries=3 --max-time=3600
```

### إضافة الـ middleware في `app/Http/Kernel.php`
```php
protected $routeMiddleware = [
    // ... الموجود
    'amial.idempotency' => \App\Http\Middleware\EnforceIdempotency::class,
];
```

### إضافة الـ services في `app/Providers/AppServiceProvider.php`
Laravel auto-resolves بالفعل (لأنها constructor-injectable). لا تكوين إضافي مطلوب.

### تطبيق middleware على routes المالية
في `routes/api/customer.php`:
```php
Route::middleware(['auth:api', 'amial.idempotency'])->group(function () {
    Route::post('/send-money', [TransactionController::class, 'sendMoney']);
    Route::post('/cash-out', [TransactionController::class, 'cashOut']);
    Route::post('/request-money', [TransactionController::class, 'requestMoney']);
    Route::post('/withdraw', [TransactionController::class, 'withdraw']);
});
```

---

## 5. ما لم يُنجَز في v0.6 (مؤجل بشكل صريح)

| البند | السبب | الدفعة |
|---|---|---|
| ZonePolicyService (رفض خارج SOUTH) | يحتاج user.zone_code أولاً | v0.7 |
| Legal terms acceptance flow | UI + middleware منفصل | v0.7 |
| Account recovery (رقم جديد) | يحتاج KYC review queue | v0.7 |
| Merchant verification flow | منتج تاجر لم يُشترَ بعد | v0.8 |
| POS users + refund | منتج تاجر لم يُشترَ | v0.8 |
| Safe Payment | يحتاج held_balance (مضاف) + UI | v0.8 |
| Family Fund | منتج مستقل | v0.9 |
| Bill Pay | عقد مزود | v0.9 |
| Branding (تغيير الاسم/الألوان) | Flutter — يحتاج User_app.zip | v0.7+ |
| APK build | يحتاج Flutter SDK + Android SDK | خارج بيئة Claude |

---

## 6. التحقق من معايير القبول (قسم 23 من الوثيقة)

| # | المعيار | الحالة بعد v0.6 |
|---|---|---|
| 1 | لا رصيد سالب | ✅ FinancialGuardService + EMoney::saving guard |
| 2 | لا عملية مكررة بسبب retry | ✅ IdempotencyService + middleware + UNIQUE INDEX |
| 3 | كل عملية حساسة داخل DB::transaction | ✅ assertInTransaction في الـ Service |
| 4 | كل debit يستخدم lockForUpdate | ✅ FinancialGuardService::lockWallet |
| 5 | كل عملية لها audit/decision log | ✅ AuditService.record لكل عملية |
| 6 | كل عملية خارج SOUTH ترفض | ⏳ v0.7 |
| 7 | موافقة على سياسة الاستخدام | ⏳ v0.7 |
| 8 | لا token/PIN/OTP في logs | ✅ AuditService.sanitizeContext + maskPhone في SmsModule |
| 9 | لا demo/update/install في production | ✅ EnvironmentGuardService (middleware للـ routes في v0.7) |
| 10 | إشعارات/PDF/تصدير عبر queue | ✅ SendTransactionNotificationJob (PDF + export في v0.9) |
| 11-15 | (ميزات لاحقة) | v0.8+ |
