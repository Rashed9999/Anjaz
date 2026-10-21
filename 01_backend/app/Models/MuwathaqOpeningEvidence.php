<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MuwathaqOpeningEvidence extends Model
{
    /** اسم الجدول لا يطابق جمع كلمة Evidence الافتراضي في Eloquent. */
    protected $table = 'muwathaq_opening_evidences';
    public const PHONE = 'phone';
    public const RESIDENCE = 'residence';
    public const IDENTITY_DOCUMENT = 'identity_document';
    public const OWNERSHIP_ASSURANCE = 'ownership_assurance';
    public const OWNER_IDENTITY = 'owner_identity';
    public const BUSINESS_REGISTRATION = 'business_registration';
    public const OPERATING_ADDRESS = 'operating_address';
    public const ADMIN_PROVISIONING = 'admin_provisioning';

    public const STATE_SUBMITTED = 'submitted';
    public const STATE_VERIFIED = 'verified';
    public const STATE_REJECTED = 'rejected';

    protected $fillable = [
        'application_id', 'kind', 'state', 'payload_encrypted', 'file_encrypted_path',
        'file_mime', 'file_sha256', 'submitted_at', 'verified_at', 'muwathaq_delivered_at', 'muwathaq_delivery_error',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'muwathaq_delivered_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(MuwathaqAccountOpeningApplication::class, 'application_id');
    }

    public function publicSummary(): array
    {
        return [
            'kind' => $this->kind,
            'state' => $this->state,
            'document_received' => $this->file_encrypted_path !== null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
