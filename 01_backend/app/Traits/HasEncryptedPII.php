<?php

namespace App\Traits;

use App\Services\EncryptionService;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * HasEncryptedPII trait — يضاف لـ models تحتوي PII.
 *
 * **استخدام:**
 *   class User extends Authenticatable
 *   {
 *       use HasEncryptedPII;
 *
 *       protected array $piiFields = [
 *           'phone' => ['encrypted' => 'phone_encrypted', 'blind_index' => 'phone_blind_index',
 *                       'masked' => 'phone_masked', 'normalizer' => 'phone'],
 *           'email' => ['encrypted' => 'email_encrypted', 'blind_index' => 'email_blind_index',
 *                       'masked' => 'email_masked', 'normalizer' => 'email'],
 *           'f_name' => ['encrypted' => 'f_name_encrypted'],
 *           'national_id' => ['encrypted' => 'national_id_encrypted',
 *                             'blind_index' => 'national_id_blind_index',
 *                             'masked' => 'national_id_masked', 'normalizer' => 'national_id'],
 *       ];
 *   }
 *
 * **التدفق:**
 *   - عند set: `$user->phone = '+967...'` → يشفر + يحسب blind_index + يحفظ masked
 *   - عند get: `$user->phone` → يفك التشفير تلقائياً
 *   - عند save: الكل يُكتب في DB
 *
 * **البحث:**
 *   $service = app(EncryptionService::class);
 *   $user = User::where('phone_blind_index', $service->blindIndex('+967...', 'phone'))->first();
 *
 * أو عبر scope:
 *   User::wherePhone('+967...')->first();
 */
trait HasEncryptedPII
{
    /**
     * Boot the trait. يُسجَّل تلقائياً عند Eloquent::boot().
     */
    public static function bootHasEncryptedPII(): void
    {
        // قبل الـ save: نشفر القيم الـ plain التي تم تعيينها
        static::saving(function ($model) {
            $model->encryptPiiFields();
        });
    }

    /**
     * شفر كل الـ PII fields قبل الحفظ.
     */
    public function encryptPiiFields(): void
    {
        $service = $this->getEncryptionService();

        foreach ($this->getPiiFieldsConfig() as $plainField => $config) {
            // إذا الـ plain attribute موجود وتغير، شفر
            if (!$this->isDirty($plainField) && !isset($this->attributes[$plainField])) {
                continue;
            }

            $plainValue = $this->attributes[$plainField] ?? null;
            if ($plainValue === null || $plainValue === '') {
                continue;
            }

            // تحقق من أنها ليست encrypted already
            if ($service->isEncrypted($plainValue)) {
                continue;
            }

            // 1) Encrypted
            if (!empty($config['encrypted'])) {
                $this->attributes[$config['encrypted']] = $service->encrypt($plainValue);
            }

            // 2) Blind index
            if (!empty($config['blind_index'])) {
                $this->attributes[$config['blind_index']] = $service->blindIndex(
                    $plainValue,
                    $config['normalizer'] ?? null,
                );
            }

            // 3) Masked
            if (!empty($config['masked'])) {
                $maskFn = $this->getMaskFunctionFor($plainField);
                $this->attributes[$config['masked']] = $service->{$maskFn}($plainValue);
            }
        }
    }

    /**
     * Override get* للحقول الـ PII — يفك التشفير عند القراءة.
     *
     * يستخدم الـ accessor pattern بـ Eloquent.
     * عند `$user->phone` يفك من `phone_encrypted` إن وُجد،
     * وإلا fallback للقيمة في `phone` (لبيانات legacy غير مهاجرة).
     */
    public function getAttribute($key)
    {
        $piiConfig = $this->getPiiFieldsConfig();

        // هل الـ key واحد من الـ PII fields؟
        if (isset($piiConfig[$key]) && !empty($piiConfig[$key]['encrypted'])) {
            $encryptedCol = $piiConfig[$key]['encrypted'];
            $encryptedValue = $this->attributes[$encryptedCol] ?? null;

            if ($encryptedValue !== null) {
                $service = $this->getEncryptionService();
                if ($service->isEncrypted($encryptedValue)) {
                    $decrypted = $service->tryDecrypt($encryptedValue);
                    if ($decrypted !== null) return $decrypted;
                }
            }
            // fallback للـ plaintext القديم
            return parent::getAttribute($key);
        }

        return parent::getAttribute($key);
    }

    // ============================================================
    // Scopes للبحث على blind_index
    // ============================================================

    public function scopeWherePhone($query, string $phone)
    {
        $service = $this->getEncryptionService();
        return $query->where(
            $this->getPiiFieldsConfig()['phone']['blind_index'],
            $service->blindIndex($phone, 'phone'),
        );
    }

    public function scopeWhereEmail($query, string $email)
    {
        $service = $this->getEncryptionService();
        return $query->where(
            $this->getPiiFieldsConfig()['email']['blind_index'],
            $service->blindIndex($email, 'email'),
        );
    }

    public function scopeWhereNationalId($query, string $nid)
    {
        $service = $this->getEncryptionService();
        return $query->where(
            $this->getPiiFieldsConfig()['national_id']['blind_index'],
            $service->blindIndex($nid, 'national_id'),
        );
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * يجب على الـ model تعريف $piiFields أو دالة getPiiFieldsConfig().
     */
    public function getPiiFieldsConfig(): array
    {
        if (property_exists($this, 'piiFields') && is_array($this->piiFields)) {
            return $this->piiFields;
        }
        return [];
    }

    private function getEncryptionService(): EncryptionService
    {
        return app(EncryptionService::class);
    }

    private function getMaskFunctionFor(string $field): string
    {
        return match ($field) {
            'phone' => 'maskPhone',
            'email' => 'maskEmail',
            'national_id' => 'maskNationalId',
            default => 'maskPhone', // fallback
        };
    }

    /**
     * استرجاع masked version مباشرة (للـ logs، admin views).
     */
    public function maskedPhone(): ?string
    {
        return $this->attributes['phone_masked'] ?? null;
    }

    public function maskedEmail(): ?string
    {
        return $this->attributes['email_masked'] ?? null;
    }

    public function maskedNationalId(): ?string
    {
        return $this->attributes['national_id_masked'] ?? null;
    }
}
