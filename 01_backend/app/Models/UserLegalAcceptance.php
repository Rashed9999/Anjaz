<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-LEGAL-001
 *
 * append-only — لا UPDATE ولا DELETE (للأمان القانوني).
 */
class UserLegalAcceptance extends Model
{
    protected $table = 'user_legal_acceptances';

    public $timestamps = false; // accepted_at فقط

    protected $fillable = [
        'user_id',
        'legal_term_id',
        'accepted_version',
        'ip_address',
        'user_agent',
        'device_id',
        'accepted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'legal_term_id' => 'integer',
        'accepted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function legalTerm(): BelongsTo
    {
        return $this->belongsTo(LegalTerm::class, 'legal_term_id', 'id');
    }

    /** منع التعديل بعد الحفظ (append-only) */
    public function update(array $attributes = [], array $options = [])
    {
        if ($this->exists) {
            throw new \RuntimeException('UserLegalAcceptance is append-only. Update is not permitted.');
        }
        return parent::update($attributes, $options);
    }

    public function delete()
    {
        throw new \RuntimeException('UserLegalAcceptance is append-only. Deletion is not permitted.');
    }
}
