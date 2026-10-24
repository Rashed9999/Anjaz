<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationDossier extends Model
{
    public const CUSTOMER = 'customer';
    public const MERCHANT = 'merchant';
    public const VERIFICATION_TIER_1 = 'verification_tier_1';
    public const VERIFICATION_TIER_2 = 'verification_tier_2';
    public const VERIFICATION_TIER_3 = 'verification_tier_3';

    public const SOURCES = [
        'self_service',
        'staff_assisted',
        'paper_archive',
        self::VERIFICATION_TIER_1,
        self::VERIFICATION_TIER_2,
        self::VERIFICATION_TIER_3,
    ];

    public const VERIFICATION_SOURCES = [
        self::VERIFICATION_TIER_1,
        self::VERIFICATION_TIER_2,
        self::VERIFICATION_TIER_3,
    ];
    public const AWAITING_CONFIRMATION = 'awaiting_customer_confirmation';
    public const SUBMITTED = 'submitted';

    protected $fillable = [
        'reference', 'subject_type', 'subject_user_id', 'source', 'state', 'phone_hash',
        'payload_encrypted', 'paper_form_encrypted_path', 'paper_form_mime',
        'paper_form_sha256', 'created_by_user_id', 'confirmed_at',
    ];

    protected $hidden = ['payload_encrypted', 'phone_hash', 'paper_form_encrypted_path'];

    protected $casts = [
        'payload_encrypted' => 'encrypted:array',
        'confirmed_at' => 'datetime',
    ];

    public function subject(): BelongsTo { return $this->belongsTo(User::class, 'subject_user_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
