<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-BILL-PAY-001
 */
class BillProvider extends Model
{
    protected $table = 'bill_providers';
    // AMIAL-SURFACE-002: طلبات المزوّد (للوحة الأدمن)
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BillPaymentOrder::class, 'provider_id');
    }

    protected $fillable = [
        'code', 'name', 'display_name_ar', 'integration_type',
        'endpoint_url', 'api_key_encrypted', 'credentials_encrypted', 'config',
        'is_active', 'integration_status', 'configured_at', 'last_health_checked_at',
        'last_success_at', 'last_failure_at', 'last_health_message',
        'last_known_balance', 'balance_currency', 'balance_checked_at',
        'failure_streak', 'zone_code',
    ];
    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'configured_at' => 'datetime',
        'last_health_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'last_known_balance' => 'decimal:4',
        'balance_checked_at' => 'datetime',
        'failure_streak' => 'integer',
    ];
    protected $hidden = ['api_key_encrypted', 'credentials_encrypted'];

    public function services(): HasMany
    {
        return $this->hasMany(BillService::class, 'provider_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(BillProviderWebhookEvent::class, 'provider_id');
    }

    /** @return array<string, string> */
    public function credentials(): array
    {
        if (!$this->credentials_encrypted) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($this->credentials_encrypted), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? array_filter($decoded, 'is_string') : [];
        } catch (\Throwable $e) {
            // Treat unreadable credentials as absent. Continuing with a corrupt
            // secret is less safe than keeping the integration unavailable.
            Log::warning('Bill provider credentials could not be decrypted', [
                'provider_id' => $this->id,
                'provider_code' => $this->code,
            ]);
            return [];
        }
    }

    /**
     * Merges only non-empty values, so an administrator can rotate one secret
     * without needing to retype the others. Caller must save the model.
     *
     * @param array<string, mixed> $credentials
     */
    public function replaceCredentials(array $credentials): void
    {
        $merged = $this->credentials();
        foreach ($credentials as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $merged[$key] = trim($value);
            }
        }

        $this->credentials_encrypted = Crypt::encryptString(
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function hasRequiredCredentials(): bool
    {
        if ($this->integration_type === 'stub') {
            return true;
        }

        if ($this->integration_type !== 'free_sadad') {
            return false;
        }

        $credentials = $this->credentials();
        foreach (['username', 'account_number', 'password', 'api_token', 'webhook_secret'] as $required) {
            if (empty($credentials[$required])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Readiness is stricter than active. An operator can see a provider before
     * it is eligible to receive customer money, but cannot accidentally route
     * a payment through untested credentials.
     */
    public function isReadyForPayments(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->integration_type === 'stub') {
            return !app()->environment('production');
        }

        return $this->integration_type === 'free_sadad'
            && $this->hasRequiredCredentials()
            && $this->integration_status === 'ready';
    }

    /** @return array<string, mixed> */
    public function adminSnapshot(): array
    {
        return [
            'integration_type' => $this->integration_type,
            'endpoint_url' => $this->endpoint_url,
            'is_active' => (bool) $this->is_active,
            'integration_status' => $this->integration_status,
            'credentials_configured' => $this->hasRequiredCredentials(),
            'config' => $this->config ?? [],
        ];
    }
}
