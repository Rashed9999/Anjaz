<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-REFACTOR-CORE-001
 * @property string $key
 * @property int|null $user_id
 * @property string $endpoint
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $transaction_id
 * @property string $status 'processing'|'completed'|'failed'
 * @property \Carbon\Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'user_id',
        'endpoint',
        'request_hash',
        'response_status',
        'response_body',
        'transaction_id',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'response_status' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    public function scopeForKey($query, string $key, ?int $userId, string $endpoint)
    {
        return $query->where('key', $key)
            ->where('user_id', $userId)
            ->where('endpoint', $endpoint);
    }
}
