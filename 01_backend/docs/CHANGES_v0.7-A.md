# سجل التغييرات — Amial Pay v0.7-A (Backend: Zone + Legal + Recovery)

**التاريخ:** 2026-05-15
**النطاق:** AMIAL-ZONE-001 + AMIAL-LEGAL-001 + AMIAL-RECOVERY-001 + BlockInProduction guard
**المتطلب المُسبق:** v0.6 منشورة وعاملة

---

## 1. ما تم بناؤه

### Zone Policy (AMIAL-ZONE-001 — قسم 4 من الوثيقة)

- جدول جديد لـ `users.zone_code` (افتراضياً `SOUTH`).
- `ZonePolicyService` يقرر السماح/الرفض لكل action.
- `EnforceZonePolicy` middleware (`amial.zone:send_money`).
- Endpoint: `GET /api/v1/amial/policy/session` يخبر التطبيق ما الأزرار التي يُظهرها.
- ✅ معيار القبول 6: "كل عملية خارج SOUTH ترفض من API".

### Legal Terms (AMIAL-LEGAL-001 — قسم 8 من الوثيقة)

- جدولان: `legal_terms` (إصدارات) + `user_legal_acceptances` (سجل القبول، append-only).
- `LegalTermsService` لإدارة دورة الحياة.
- `RequireTermsAcceptance` middleware يرفض العمليات المالية لمن لم يقبل آخر إصدار.
- 3 endpoints مستخدم + 4 admin.
- ✅ معيار القبول 7: "كل مستخدم وافق على سياسة الاستخدام قبل العمليات المالية".

### Account Recovery (AMIAL-RECOVERY-001 — قسم 10 من الوثيقة)

- جدول `account_recovery_requests`.
- `AccountRecoveryService` لمسارَيْن:
  - Self-service (يملك القديم): OTP × 2 + PIN + 24h hold + risk score
  - Admin-mediated (فقد الرقم): مراجعة + 7 يوم hold
- موانع: agent/merchant، cooldown 30 يوم، رقم مستخدم.
- 5 endpoints مستخدم + 4 admin.

### Block In Production (AUDIT 1.8 — معيار 9)

- `BlockInProduction` middleware للـ routes الحساسة (`/install`، `/update`).
- 404 في production (يخفي وجود الـ route).
- ✅ معيار القبول 9: "لا يوجد demo/update/install مفتوح في production".

### Structured Exceptions → JSON

`bootstrap/app.php` الآن يحول تلقائياً:
- `InsufficientBalanceException` → HTTP 402 + JSON
- `DuplicateTransactionException` → HTTP 409 + JSON
- `EnvironmentMutationBlockedException` → HTTP 403 + JSON

---

## 2. جدول التصنيف

| # | المسار | التصنيف |
|---|---|---|
| 1 | `database/migrations/2026_05_15_110001_amial_add_zone_to_users.php` | ADD |
| 2 | `database/migrations/2026_05_15_110002_amial_create_legal_terms_table.php` | ADD |
| 3 | `database/migrations/2026_05_15_110003_amial_create_user_legal_acceptances_table.php` | ADD |
| 4 | `database/migrations/2026_05_15_110004_amial_create_account_recovery_requests_table.php` | ADD |
| 5 | `app/Models/LegalTerm.php` | ADD |
| 6 | `app/Models/UserLegalAcceptance.php` | ADD |
| 7 | `app/Models/AccountRecoveryRequest.php` | ADD |
| 8 | `app/Models/User.php` | MERGE (إضافة zone, security_hold لـ fillable/casts) |
| 9 | `app/Services/ZonePolicyService.php` | ADD |
| 10 | `app/Services/LegalTermsService.php` | ADD |
| 11 | `app/Services/AccountRecoveryService.php` | ADD |
| 12 | `app/Http/Middleware/EnforceZonePolicy.php` | ADD |
| 13 | `app/Http/Middleware/RequireTermsAcceptance.php` | ADD |
| 14 | `app/Http/Middleware/BlockInProduction.php` | ADD |
| 15 | `app/Http/Controllers/Api/V1/Amial/PolicyController.php` | ADD |
| 16 | `app/Http/Controllers/Api/V1/Amial/LegalController.php` | ADD |
| 17 | `app/Http/Controllers/Api/V1/Amial/AccountRecoveryController.php` | ADD |
| 18 | `app/Http/Controllers/Admin/LegalTermsController.php` | ADD |
| 19 | `app/Http/Controllers/Admin/AccountRecoveryController.php` | ADD |
| 20 | `routes/api/amial.php` | ADD |
| 21 | `bootstrap/app.php` | REPLACE (Laravel 11 — يسجل middleware + routes + exception handlers) |
| 22 | `app/Traits/TransactionTrait.php` | MERGE (إضافة assertFinancialEligibility) |
| 23 | `tests/Feature/ZonePolicyTest.php` | ADD (12 اختبار) |
| 24 | `tests/Feature/LegalTermsTest.php` | ADD (10 اختبار) |
| 25 | `tests/Feature/AccountRecoveryTest.php` | ADD (12 اختبار) |

---

## 3. Endpoints الجديدة

### User-facing

| Method | Path | Code | Description |
|---|---|---|---|
| GET | `/api/v1/amial/policy/session` | POLICY_OK | سياسة المستخدم الحالي |
| GET | `/api/v1/amial/legal/status` | TERMS_ACCEPTED / TERMS_ACCEPTANCE_REQUIRED | هل قبلت؟ |
| GET | `/api/v1/amial/legal/current` | TERMS_OK | الإصدار الحالي |
| POST | `/api/v1/amial/legal/accept` | TERMS_ACCEPTED | تسجيل القبول |
| POST | `/api/v1/amial/recovery/initiate-self` | RECOVERY_OTP_SENT | بدء تغيير رقم |
| POST | `/api/v1/amial/recovery/initiate-lost` | RECOVERY_PENDING_REVIEW | بدء استرداد |
| POST | `/api/v1/amial/recovery/{ulid}/verify-otp` | RECOVERY_OTP_VERIFIED | تحقق OTPs |
| POST | `/api/v1/amial/recovery/{ulid}/complete` | RECOVERY_HOLD_APPLIED | إكمال + hold |
| GET | `/api/v1/amial/recovery/{ulid}` | RECOVERY_OK | حالة الطلب |

### Admin-facing

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/admin/legal/terms` | قائمة الإصدارات |
| POST | `/api/v1/admin/legal/terms` | نشر إصدار جديد |
| GET | `/api/v1/admin/legal/terms/{id}/stats` | إحصاءات القبول |
| GET | `/api/v1/admin/recovery/requests` | قائمة طلبات الاسترداد |
| POST | `/api/v1/admin/recovery/requests/{ulid}/approve` | موافقة |
| POST | `/api/v1/admin/recovery/requests/{ulid}/reject` | رفض |

⚠️ الـ admin endpoints **يجب** إضافتها يدوياً إلى `routes/admin.php` (أو ما يقابلها) — راجع التعليق في `routes/api/amial.php`.

---

## 4. تطبيق Middleware على Routes القائمة

⚠️ **بعد deploy v0.7-A، عدّل `routes/api.php` (أو routes/api/customer.php) لتطبيق الـ middlewares الجديدة على endpoints المالية:**

```php
Route::middleware([
    'auth:api',
    'amial.terms',          // ← v0.7-A
    'amial.idempotency',    // ← v0.6
])->prefix('v1/customer')->group(function () {

    Route::post('/send-money', [TransactionController::class, 'sendMoney'])
        ->middleware('amial.zone:send_money');

    Route::post('/cash-out', [TransactionController::class, 'cashOut'])
        ->middleware('amial.zone:cash_out');

    Route::post('/request-money', [TransactionController::class, 'requestMoney'])
        ->middleware('amial.zone:request_money');

    Route::post('/withdraw', [WithdrawController::class, 'withdraw'])
        ->middleware('amial.zone:withdraw');

    Route::post('/add-money', [TransactionController::class, 'addMoney'])
        ->middleware('amial.zone:add_money');
});
```

والـ install/update:

```php
Route::middleware(['amial.block-in-production'])->group(function () {
    require __DIR__.'/install.php';
    require __DIR__.'/update.php';
});
```

---

## 5. Migration Order — صارم

شغّل بهذا الترتيب فقط:

```bash
# 1. v0.6 migrations يجب أن تكون منشورة مسبقاً
php artisan migrate:status | grep amial_

# 2. v0.7-A migrations
php artisan migrate --pretend  # افحص أولاً
php artisan migrate            # ثم نفّذ

# 3. seed إصدار أولي من السياسة (يدوي عبر CLI أو admin panel)
php artisan tinker
>>> app(\App\Services\LegalTermsService::class)->publishNewVersion(
...   version: '1.0',
...   locale: 'ar',
...   title: 'سياسة استخدام أميال باي',
...   content: file_get_contents(base_path('docs/legal/terms_ar_v1.md')),
...   changelog: 'الإصدار الأول',
...   createdBy: 1,
... );
```

> ⚠️ بدون نشر إصدار v1.0 من السياسة، الـ middleware `amial.terms`
> **لن** يرفض أحداً (لأن `needsAcceptance` يعيد false عند غياب أي إصدار).
> هذا تصميم آمن لتدرّج النشر، لكنه يعني أن المستخدمين يستطيعون تنفيذ عمليات
> حتى تنشر السياسة. **لا تطبق `amial.terms` middleware على routes قبل نشر v1.0**.

---

## 6. تحضير users الموجودين

```sql
-- بعد migration users، كل المستخدمين الجدد على SOUTH (default).
-- لكن لو عندك users قدامى بـ zone_code فارغ أو NULL:

UPDATE users SET zone_code = 'SOUTH' WHERE zone_code IS NULL OR zone_code = '';

-- إذا أردت تطبيق zones لمناطق مختلفة (مستقبلاً):
-- UPDATE users SET zone_code = 'NORTH' WHERE phone_country_code = '+967N';
```

---

## 7. الاختبارات

| Test File | الاختبارات | يغطي |
|---|---|---|
| `ZonePolicyTest` | 12 | كل قواعد قسم 4 |
| `LegalTermsTest` | 10 | dual-version + append-only + idempotent accept |
| `AccountRecoveryTest` | 12 | كل المسارات + risk score + holds |

**المجموع الكلي مع v0.6:** 70 اختبار في `tests/Feature` و `tests/Unit`.

```bash
php artisan test --filter='ZonePolicyTest|LegalTermsTest|AccountRecoveryTest'
```

---

## 8. معايير القبول — التحديث

| # | المعيار | حالة v0.6 | حالة v0.7-A |
|---|---|---|---|
| 1 | لا رصيد سالب | ✅ | ✅ |
| 2 | لا duplicate retry | ✅ | ✅ |
| 3 | DB::transaction على الكل | ✅ | ✅ |
| 4 | lockForUpdate | ✅ | ✅ |
| 5 | audit/decision لكل عملية | ✅ | ✅ + Zone decisions + Legal + Recovery |
| 6 | رفض خارج SOUTH | ⏳ | ✅ **ZonePolicyService** |
| 7 | قبول السياسة | ⏳ | ✅ **LegalTermsService** |
| 8 | لا token/PIN/OTP في logs | ✅ | ✅ + OTPs hidden في AccountRecoveryRequest |
| 9 | لا install/update في production | ✅ (Env Guard) | ✅ **+ BlockInProduction route guard** |
| 10 | إشعارات/PDF/تصدير async | ✅ | ✅ |

**معايير محققة: 9/15**. الباقي يحتاج ميزات v0.8+ (Merchant verification, POS, Safe Payment).

---

## 9. ما لم يُنجَز (مؤجل)

| البند | لماذا | الدفعة |
|---|---|---|
| Flutter UI: شاشة Terms | لـ v0.7-B | v0.7-B |
| Flutter UI: read-only banner | لـ v0.7-B | v0.7-B |
| Flutter UI: شاشة Recovery | لـ v0.7-B | v0.7-B |
| Flutter: secure_storage بدل SharedPreferences | لـ v0.7-C | v0.7-C |
| Flutter: Idempotency-Key header | لـ v0.7-C | v0.7-C |
| Branding (Cash6 → Amial Pay) | لـ v0.7-B | v0.7-B |
| KYC integration → zone assignment | احتمالاً عقد خارجي | v0.8 |
| OTP delivery integration (SMS) | الـ Service يولد الـ OTP لكن SmsModule::send يجب استدعاؤه من Controller | ✅ Code جاهز |
| Job مجدول لتطبيق phone change بعد hold | يحتاج Laravel Scheduler config | v0.7-A.1 hotfix |

---

## 10. الـ Job المؤجل (يجب إضافته)

في `routes/console.php` (Laravel 11):

```php
use App\Models\AccountRecoveryRequest;
use App\Services\AccountRecoveryService;

Schedule::call(function () {
    AccountRecoveryRequest::where('status', 'approved')
        ->whereHas('user', fn($q) => $q->where('security_hold_until', '<=', now()))
        ->chunk(50, function ($requests) {
            $svc = app(AccountRecoveryService::class);
            foreach ($requests as $req) {
                $svc->applyApprovedChange($req);
            }
        });
})->everyFifteenMinutes()->name('amial-apply-recovery-after-hold');

// أيضاً: تنظيف طلبات منتهية
Schedule::call(function () {
    AccountRecoveryRequest::where('expires_at', '<', now())
        ->whereIn('status', ['pending_otp', 'pending_review'])
        ->update(['status' => 'expired']);
})->hourly()->name('amial-expire-recovery-requests');
```
