# تعليمات النشر — Amial Pay v0.7-A

> ⚠️ يفترض أن v0.6 منشورة وعاملة. لو لم تنشرها بعد، اقرأ `MIGRATION_NOTES_v0.6.md` أولاً.

---

## 0. Backup مزدوج

```bash
mysqldump --single-transaction cash6_db > /backups/pre_v0.7A_$(date +%s).sql
```

## 1. اختبار في staging (إلزامي)

```bash
cd /var/www/amial_pay_staging
git checkout amial-v0.7-A-batch

# تأكد أن v0.6 migrations طبقت أولاً
php artisan migrate:status | grep "amial_create_idempotency"

# pretend migrate الجديدة
php artisan migrate --pretend > /tmp/pretend_v0.7A.sql
less /tmp/pretend_v0.7A.sql
```

ابحث في الـ SQL عن أي:
- `ALTER TABLE users` لإضافة zone_code → جيد
- `CREATE TABLE legal_terms`
- `CREATE TABLE user_legal_acceptances`
- `CREATE TABLE account_recovery_requests`

## 2. الـ migrate الفعلي في staging

```bash
php artisan migrate
```

ثم شغّل الاختبارات:

```bash
php artisan test --filter='ZonePolicyTest|LegalTermsTest|AccountRecoveryTest'
```

## 3. seed السياسة v1.0 (إلزامي قبل تفعيل middleware)

```bash
# إنشاء محتوى السياسة كـ markdown
mkdir -p docs/legal
cat > docs/legal/terms_ar_v1.md << 'EOF'
# سياسة استخدام أميال باي
الإصدار 1.0 — تاريخ السريان: 2026-XX-XX

## 1. القبول
باستخدامك تطبيق أميال باي، أنت توافق على هذه الشروط...

## 2. الخدمات المقدمة
- التحويل بين المستخدمين داخل منطقة الجنوب
- ...

## 3. الرسوم
...

## 4. الخصوصية
...
EOF

# نشر الإصدار
php artisan tinker
>>> $svc = app(\App\Services\LegalTermsService::class);
>>> $svc->publishNewVersion(
...   version: '1.0',
...   locale: 'ar',
...   title: 'سياسة استخدام أميال باي - الإصدار 1.0',
...   content: file_get_contents(base_path('docs/legal/terms_ar_v1.md')),
...   changelog: 'الإصدار الأول من السياسة',
...   createdBy: 1,
... );
>>> exit
```

تحقق:
```sql
SELECT id, version, locale, is_current, effective_at FROM legal_terms WHERE is_current = 1;
```

## 4. تطبيق middleware على routes المالية

افتح `routes/api.php` (أو ما يقابلها) وعدّل routes العمليات المالية:

```php
Route::middleware([
    'auth:api',
    'amial.terms',
    'amial.idempotency',
])->prefix('v1/customer')->group(function () {
    Route::post('/send-money', ...)->middleware('amial.zone:send_money');
    Route::post('/cash-out', ...)->middleware('amial.zone:cash_out');
    // ... إلخ
});
```

ولـ install/update:

```php
Route::middleware(['amial.block-in-production'])->group(function () {
    require __DIR__.'/install.php';
    require __DIR__.'/update.php';
});
```

## 5. تحديث users الموجودين

```sql
-- كل users قدامى يصبحون SOUTH
UPDATE users SET zone_code = 'SOUTH' WHERE zone_code IS NULL OR zone_code = '';

-- تحقق
SELECT zone_code, COUNT(*) FROM users GROUP BY zone_code;
```

## 6. إضافة Scheduled Jobs

في `routes/console.php`:

```php
use App\Models\AccountRecoveryRequest;
use App\Services\AccountRecoveryService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    AccountRecoveryRequest::where('status', 'approved')->chunk(50, function ($reqs) {
        $svc = app(AccountRecoveryService::class);
        foreach ($reqs as $r) {
            if ($r->user->security_hold_until?->isPast()) {
                $svc->applyApprovedChange($r);
            }
        }
    });
})->everyFifteenMinutes();

Schedule::call(function () {
    AccountRecoveryRequest::where('expires_at', '<', now())
        ->whereIn('status', ['pending_otp', 'pending_review'])
        ->update(['status' => 'expired']);
})->hourly();
```

ثم تأكد cron يشغّل `php artisan schedule:run` كل دقيقة:

```cron
* * * * * cd /var/www/cash6 && php artisan schedule:run >> /dev/null 2>&1
```

## 7. النشر إلى production

```bash
# 1. maintenance mode
php artisan down --message="جاري التحديث" --retry=60

# 2. backup
mysqldump --single-transaction cash6_db > /backups/pre_v0.7A_prod_$(date +%s).sql

# 3. deploy code
git checkout amial-v0.7-A

# 4. migrate
php artisan migrate --force

# 5. publish initial legal terms (نفس tinker أعلاه)

# 6. cache clear & rebuild
php artisan config:cache
php artisan route:cache

# 7. queue restart (workers بحاجة للنسخة الجديدة)
php artisan queue:restart

# 8. up
php artisan up

# 9. تطبيق middleware على routes في خطوة منفصلة (deploy ثاني)
#    لا تطبق `amial.terms` على routes قبل تأكيد أن:
#      - legal_terms يحتوي إصدار current
#      - الـ Flutter app محدّث ليتعامل مع TERMS_ACCEPTANCE_REQUIRED
```

## 8. التحقق بعد النشر

```sql
-- legal_terms عنده إصدار current
SELECT version, locale, is_current FROM legal_terms WHERE is_current = 1;
-- يجب: row واحد على الأقل

-- users كلها لها zone
SELECT zone_code, COUNT(*) FROM users GROUP BY zone_code;
-- يجب: لا NULL

-- middleware aliases مسجلة
php artisan route:list | grep amial
```

اختبار API:
```bash
TOKEN=$(...)  # حصل على token من login

# POLICY
curl -H "Authorization: Bearer $TOKEN" \
     https://api.amialpay.com/api/v1/amial/policy/session
# يتوقع: {"success":true,"code":"POLICY_OK","meta":{"can_transact":true,...}}

# LEGAL
curl -H "Authorization: Bearer $TOKEN" \
     https://api.amialpay.com/api/v1/amial/legal/status
# يتوقع: {"success":true,"code":"TERMS_ACCEPTANCE_REQUIRED",...} (للمستخدم الذي لم يقبل بعد)

curl -H "Authorization: Bearer $TOKEN" \
     https://api.amialpay.com/api/v1/amial/legal/current
# يتوقع: محتوى السياسة الحالية
```

## 9. Rollback

```bash
# لو الـ deploy فشل:
php artisan down
git checkout v0.6
php artisan migrate:rollback --step=4  # يرجع 4 migrations v0.7-A
php artisan config:cache
php artisan up
```

⚠️ Rollback آمن **فقط** قبل قبول أي مستخدم للسياسة. بعدها، `user_legal_acceptances` تحتوي بيانات قانونية يجب الحفاظ عليها.
