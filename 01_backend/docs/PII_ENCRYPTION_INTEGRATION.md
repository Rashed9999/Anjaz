# دمج HasEncryptedPII في User Model

**AMIAL-PII-ENCRYPTION-001 (v1.3)**

## الخطوات

### 1. توليد المفاتيح

```bash
php artisan amial:generate-pii-keys
```

انسخ الـ output إلى `.env`:

```env
AMIAL_PII_ENCRYPTION_KEY=base64_string_here...
AMIAL_PII_BLIND_INDEX_KEY=another_base64_string...
```

**⚠️ مهم:**
- backup هذين المفتاحين في مكان آمن (Vault, AWS Secrets)
- فقدانهما = فقدان كل البيانات الـ encrypted (لا يمكن استرداد)

### 2. تشغيل migrations

```bash
php artisan migrate
```

هذا يضيف:
- `users.phone_encrypted`, `phone_blind_index`, `phone_masked`
- `users.email_encrypted`, `email_blind_index`, `email_masked`
- `users.f_name_encrypted`, `l_name_encrypted`
- `users.national_id_encrypted`, ...
- `users.address_encrypted`, `dob_encrypted`
- `users.pii_migrated_at`
- جدول `pii_access_logs`

### 3. تحديث User model

في `app/Models/User.php`:

```php
use App\Traits\HasRoles;
use App\Traits\HasEncryptedPII;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles, HasEncryptedPII;

    // أضف هذا — يحدد أي حقول تُشفر:
    protected array $piiFields = [
        'phone' => [
            'encrypted' => 'phone_encrypted',
            'blind_index' => 'phone_blind_index',
            'masked' => 'phone_masked',
            'normalizer' => 'phone',
        ],
        'email' => [
            'encrypted' => 'email_encrypted',
            'blind_index' => 'email_blind_index',
            'masked' => 'email_masked',
            'normalizer' => 'email',
        ],
        'f_name' => ['encrypted' => 'f_name_encrypted'],
        'l_name' => ['encrypted' => 'l_name_encrypted'],
        'national_id' => [
            'encrypted' => 'national_id_encrypted',
            'blind_index' => 'national_id_blind_index',
            'masked' => 'national_id_masked',
            'normalizer' => 'national_id',
        ],
    ];

    // ... باقي الكود
}
```

### 4. ترحيل البيانات الموجودة

```bash
# أولاً: backup كامل
mysqldump cash6_db > /backups/pre_pii_$(date +%s).sql

# ثانياً: dry run
php artisan amial:migrate-pii --pretend

# ثالثاً: التنفيذ
php artisan amial:migrate-pii

# للجدول الآخر:
php artisan amial:migrate-pii --table=account_recovery_requests
```

### 5. تحديث الـ code للبحث

**قبل (لن يعمل بعد التشفير لو حذفنا plaintext):**
```php
$user = User::where('phone', $phone)->first();
```

**بعد (آمن):**
```php
$user = User::wherePhone($phone)->first();  // scope من trait
// أو
$user = User::where('phone_blind_index', $service->blindIndex($phone, 'phone'))->first();
```

**القراءة دون تغيير:**
```php
echo $user->phone;  // يفك تلقائياً، يعمل كما هو
echo $user->phone_masked;  // للـ logs/display السريع
```

### 6. الفحص بعد الترحيل

```bash
php artisan tinker
>>> $u = User::find(1);
>>> $u->phone;             # يجب أن يطبع الـ phone الأصلي
>>> $u->phone_masked;      # يجب أن يطبع +967XX***567
>>> $u->phone_encrypted;   # يبدأ بـ v1:
>>> $u->phone_blind_index; # 64-char hex
```

### 7. التنظيف (v1.4 - مستقبلاً)

بعد التأكد عبر بضعة أسابيع أن كل شيء يعمل:

```php
// migration v1.4 (لاحقاً)
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn(['phone', 'email', 'f_name', 'l_name', 'national_id', 'address', 'date_of_birth']);
});
```

**لكن لا تستعجل** — احتفظ بالـ plaintext لشهر على الأقل قبل الحذف.

## فحص الأمان

### اختبار يدوي

```bash
# 1. تأكد من أن الـ DB لا يحتوي plaintext قابل للبحث:
mysql> SELECT phone FROM users LIMIT 5;
# (إن كان plaintext فقد فشل الترحيل)

mysql> SELECT phone_encrypted FROM users LIMIT 5;
# يجب أن يبدأ كل سطر بـ "v1:..."

# 2. تأكد من البحث يعمل:
php artisan tinker
>>> User::wherePhone('+967701234567')->exists();
=> true
```

### حماية الـ keys

- **DON'T:** الـ keys في git
- **DON'T:** الـ keys في logs
- **DO:** AWS Secrets Manager / HashiCorp Vault / Azure Key Vault
- **DO:** rotation كل 6 شهور (في v1.4)
- **DO:** صلاحيات RBAC strict على الـ admins الذين يطلعون على decrypted PII

## التوصية النهائية

### للـ pilot الأول
- شغل migrations + migrate-pii
- اترك plaintext columns موجودة (rollback safety)
- راقب logs لـ decrypt failures

### بعد شهر
- إن لم تظهر مشاكل، يمكن حذف plaintext columns
- بدء rotation procedure للـ keys (في v1.4)
