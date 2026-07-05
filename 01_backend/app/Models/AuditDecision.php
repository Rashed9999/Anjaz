<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * append-only — لا UPDATE ولا DELETE.
 * نمنع ذلك على مستوى Eloquent.
 */
class AuditDecision extends Model
{
    protected $table = 'audit_decisions';

    public $timestamps = false; // لدينا created_at فقط (يتم set بـ useCurrent في DB)

    protected $fillable = [
        'decision_id',
        'actor_type',
        'actor_user_id',
        'subject_type',
        'subject_id',
        'action',
        'decision_code',
        'reason',
        'context',
        'transaction_id',
        'idempotency_key',
        'zone_code',
        'severity',
        'created_at',
        'prev_hash',
        'entry_hash',
    ];

    protected $casts = [
        'actor_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Override delete — append-only policy
     */
    public function delete()
    {
        throw new \RuntimeException('AuditDecision is append-only. Deletion is not permitted.');
    }

    /**
     * Override update — append-only policy.
     * نسمح بـ first save فقط (insert).
     */
    public function update(array $attributes = [], array $options = [])
    {
        if ($this->exists) {
            throw new \RuntimeException('AuditDecision is append-only. Update is not permitted.');
        }
        return parent::update($attributes, $options);
    }
}
