<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Traits\HasRoles;

/**
 * AMIAL-PIN-SECURITY-001 — MERGE (تعديل محدّد)
 *
 * التغييرات عن الأصلي:
 *  - $hidden: إضافة transaction_pin و fcm_token (لا تظهران في API responses)
 *  - $casts: إضافة الحقول الجديدة (transaction_pin, *_at, attempts, locked_until)
 *  - relation security_events جديد
 *  - الـ getImageFullPathAttribute و أمثاله: بدون تغيير (نسخ من الأصلي)
 *
 * بقية الكود مطابق للأصلي 100% — يُعتبر MERGE وليس REPLACE.
 */
class User extends Authenticatable
{
    // AMIAL-RBAC-001 (fix): ربط نظام الصلاحيات المركزي — يوفّر hasPermission/
    // hasAnyPermission/hasAllPermissions/assignRole التي يعتمد عليها middleware `rbac`.
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles, \App\Traits\HasEncryptedPII;

    // AMIAL-PII-ENCRYPTION-001: تفعيل تشفير البيانات الحساسة (للتجربة المغلقة/الإنتاج).
    // على الحفظ: يُملأ *_encrypted + *_blind_index + *_masked مع إبقاء العمود الصريح
    // (v1.3، للتراجع الآمن). على القراءة: يفكّ التشفير أو يرجع للصريح. الأعمدة الصريحة
    // تبقى فيعمل البحث whereIn(phone/variants) وunified login كما هو.
    protected $piiFields = [
        'phone' => [
            'encrypted' => 'phone_encrypted', 'blind_index' => 'phone_blind_index',
            'masked' => 'phone_masked', 'normalizer' => 'phone',
        ],
        'email' => [
            'encrypted' => 'email_encrypted', 'blind_index' => 'email_blind_index',
            'masked' => 'email_masked', 'normalizer' => 'email',
        ],
        'national_id' => [
            'encrypted' => 'national_id_encrypted', 'blind_index' => 'national_id_blind_index',
            'masked' => 'national_id_masked', 'normalizer' => 'national_id',
        ],
    ];

    // AMIAL-PIN-SECURITY-001: إضافة transaction_pin و fcm_token للـ hidden
    protected $hidden = [
        'password',
        'transaction_pin',          // ← جديد: لا يجب أن يخرج من API
        'fcm_token',                // ← جديد: token الجهاز خاص بالـ backend
        'remember_token',
    ];

    protected $fillable = [
        'last_active_at',
        // AMIAL-PIN-SECURITY-001: إضافة الحقول الجديدة
        'transaction_pin',
        'transaction_pin_set_at',
        'pin_failed_attempts',
        'pin_locked_until',
        'requires_pin_setup',
        // AMIAL-ZONE-001 + AMIAL-RECOVERY-001 (v0.7-A)
        'zone_code',
        'security_hold_until',
        'security_hold_reason',
        // CRITICAL-001 — الأدوار + مستوى التوثيق
        'role',
        'verification_level',
        // phone يدخل fillable للسماح بـ AccountRecoveryService::applyApprovedChange
        'phone',
        'fcm_token',
        // AMIAL-ACCOUNT-NUMBER-001 — رقم الحساب العام (8 أرقام + Luhn)
        'account_number',
    ];

    protected $casts = [
        'f_name' => 'string',
        'l_name' => 'string',
        'dial_country_code' => 'string',
        'phone' => 'string',
        'email' => 'string',
        'image' => 'string',
        'type' => 'integer',
        'role' => 'string',
        'verification_level' => 'string',
        'password' => 'string',
        'is_phone_verified' => 'integer',
        'is_email_verified' => 'integer',
        'last_active_at' => 'datetime',
        'unique_id' => 'string',
        'referral_id' => 'string',
        // AMIAL-PIN-SECURITY-001
        'transaction_pin' => 'hashed',  // ← Laravel يطبع Hash::make تلقائياً عند set
        'transaction_pin_set_at' => 'datetime',
        'pin_failed_attempts' => 'integer',
        'pin_locked_until' => 'datetime',
        'requires_pin_setup' => 'boolean',
        'two_factor' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'two_factor_confirmed_at' => 'datetime',
        // AMIAL-ZONE-001 + AMIAL-RECOVERY-001 (v0.7-A)
        'zone_code' => 'string',
        'security_hold_until' => 'datetime',
        'security_hold_reason' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['image_fullpath', 'identification_image_fullpath', 'merchant_identification_image_fullpath'];

    /**
     * AMIAL-ROLE-SYNC-001 — مزامنة `role` مع `type` تلقائياً عند الحفظ.
     *
     * الجذر: توجيه القطاعات في التطبيق (HomeDispatcher) وبوابة الميزات
     * (FeatureAccessService) تعتمد كلّها على العمود `users.role`. لكن أعمدة
     * الإنشاء (التسجيل، لوحة الأدمن، البذور التجريبية) كانت تضبط `type` فقط
     * وتترك `role` على الافتراضي 'user' — فيظهر التاجر كعميل ولا تُفتح لوحة
     * القطاع (محطة الوقود مثلاً). هذا الخطّاف يضمن الاتساق دائماً دون أن
     * تتذكّره كلّ نقطة إنشاء.
     *
     * يعمل فقط حين يكون `role` فارغاً أو 'user' (الافتراضي)، فلا يدهس أدواراً
     * صريحة مثل 'super_admin' أو 'pos' أو 'distributor'.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $current = $user->role ?? null;
            if ($current !== null && $current !== '' && $current !== \App\Support\Access\AccessConstants::ROLE_USER) {
                return; // دور صريح مضبوط — لا نلمسه
            }
            $mapped = match ((int) ($user->type ?? CUSTOMER_TYPE)) {
                ADMIN_TYPE    => \App\Support\Access\AccessConstants::ROLE_ADMIN,
                AGENT_TYPE    => \App\Support\Access\AccessConstants::ROLE_AGENT,
                MERCHANT_TYPE => \App\Support\Access\AccessConstants::ROLE_MERCHANT,
                default       => \App\Support\Access\AccessConstants::ROLE_USER, // عميل
            };
            if ($mapped !== $current) {
                $user->role = $mapped;
            }
        });
    }

    public function getImageFullPathAttribute(): ?string
    {
        $image = $this->image ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/160x160/img1.jpg');

        if ($image == null) {
            if (request()->is('api/*')) {
                $path = null;
            }
            return $path;
        }

        $folderNames = [0 => 'admin', 1 => 'agent', 2 => 'customer'];
        $folder = $folderNames[$this->type] ?? 'merchant';
        if (!is_null($image) && Storage::disk('public')->exists($folder . '/' . $image)) {
            $path = dynamicStorage(path: 'storage/app/public/' . $folder . '/' . $image);
        }

        return $path;
    }

    public function getIdentificationImageFullPathAttribute(): array
    {
        $value = $this->identification_image ?? [];
        $folder = $this->type == 3 ? 'merchant' : 'identity';
        $imageUrlArray = is_array($value) ? $value : json_decode($value, true);
        if (is_array($imageUrlArray)) {
            foreach ($imageUrlArray as $key => $item) {
                if (Storage::disk('public')->exists('user/identity/' . $item)) {
                    $imageUrlArray[$key] = dynamicStorage(path: 'storage/app/public/user/identity/' . $item);
                } else {
                    $imageUrlArray[$key] = dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg');
                }
            }
        }
        return $imageUrlArray;
    }

    public function getMerchantIdentificationImageFullPathAttribute(): array
    {
        $value = $this->identification_image ?? [];
        $imageUrlArray = is_array($value) ? $value : json_decode($value, true);
        if (is_array($imageUrlArray)) {
            foreach ($imageUrlArray as $key => $item) {
                if (Storage::disk('public')->exists('merchant/' . $item)) {
                    $imageUrlArray[$key] = dynamicStorage(path: 'storage/app/public/merchant/' . $item);
                } else {
                    $imageUrlArray[$key] = dynamicAsset(path: 'public/assets/admin/img/160x160/img1.jpg');
                }
            }
        }
        return $imageUrlArray;
    }

    public function AauthAcessToken(): HasMany
    {
        return $this->hasMany(OauthAccessToken::class);
    }

    public function scopeAgent(Builder $query): Builder
    {
        return $query->where('type', '=', 1);
    }

    public function scopeCustomer(Builder $query): Builder
    {
        return $query->where('type', '=', 2);
    }

    public function scopeMerchantUser(Builder $query): Builder
    {
        return $query->where('type', '=', 3);
    }

    public function scopeOfType(Builder $query, int $user_type): Builder
    {
        return $query->where('type', '=', $user_type);
    }

    public function emoney(): HasOne
    {
        return $this->hasOne(EMoney::class, 'user_id', 'id');
    }

    public function user_log_histories(): HasMany
    {
        return $this->hasMany(UserLogHistory::class, 'user_id', 'id');
    }

    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class, 'user_id', 'id');
    }

    /**
     * AMIAL-PIN-SECURITY-001: relation للأحداث الأمنية
     */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(AccountSecurityEvent::class, 'user_id', 'id');
    }

    public static function boot(): void
    {
        parent::boot();

        // AMIAL-ACCOUNT-NUMBER-001 — توليد رقم حساب فريد لكل مستخدم جديد
        self::creating(function ($model) {
            if (empty($model->account_number)) {
                try {
                    $model->account_number = app(\App\Services\AccountNumberService::class)->generateUnique();
                } catch (\Throwable $e) {
                    // لا نمنع التسجيل عند تعذّر التوليد؛ يُعالَج لاحقاً بأمر backfill
                    \Log::warning('account_number generation failed on create: ' . $e->getMessage());
                }
            }
        });

        self::updated(function ($model) {
            if ($model->isDirty('is_active')) {
                if ($model->is_active == 0) {
                    $model->tokens->each(function ($token, $key) {
                        $token->revoke();
                    });
                }
            }

            // AMIAL-PIN-SECURITY-001: عند تغيير transaction_pin، نُبطل كل tokens
            // (سياسة قسم 9 من الوثيقة: "بعد PIN Reset يتم إبطال كل tokens access")
            if ($model->isDirty('transaction_pin') && !$model->wasRecentlyCreated) {
                $oldPin = $model->getOriginal('transaction_pin');
                $newPin = $model->transaction_pin;
                // فقط إذا تغير فعلاً (ليس null → null)
                if ($oldPin !== null && $oldPin !== $newPin) {
                    $model->tokens->each(function ($token) {
                        $token->revoke();
                    });
                    // نمسح fcm_token حتى لا تذهب إشعارات لجهاز قديم
                    \DB::table('users')->where('id', $model->id)->update(['fcm_token' => null]);
                }
            }
        });
    }

    // ================= AMIAL-OPERATOR-RBAC-001 =================

    /** أدوار المنصّة المسندة إلى هذا الحساب (لموظّفي المنصّة لا التجّار). */
    public function platformRoles()
    {
        return $this->belongsToMany(
            \App\Models\Role::class, 'admin_user_roles', 'user_id', 'role_id');
    }

    /**
     * هل يملك هذا المشغّل صلاحية بعينها؟
     *
     * تُقرأ مرّة لكل طلب وتُحفظ في الذاكرة: الحارس يُنادى على كل مسار، وضربُ
     * قاعدة البيانات في كل نداء يجعل الأمان ثمناً يُدفع من سرعة اللوحة —
     * فيُطلب تخفيفه لاحقاً، وهو أسوأ ما يُطلب في ضابط أمان.
     */
    public function hasPlatformPermission(string $code): bool
    {
        if (!isset($this->cachedPlatformPermissions)) {
            $this->cachedPlatformPermissions = $this->platformRoles()
                ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->pluck('permissions.code')
                ->all();
        }

        return in_array($code, $this->cachedPlatformPermissions, true);
    }

    /** يُقرأ في القوائم: لا يُعرض للمشغّل ما لا يستطيع فتحه. */
    public function platformRoleLabels(): array
    {
        return $this->platformRoles()->pluck('label_ar')->all();
    }

    private ?array $cachedPlatformPermissions = null;
}
