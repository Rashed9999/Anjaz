<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantVerificationRequest extends Model
{
    protected $table = 'merchant_verification_requests';

    protected $fillable = [
        'request_ulid', 'merchant_user_id',
        'business_name', 'commercial_register_number', 'business_category', 'city', 'address',
        'id_card_front_path', 'id_card_back_path',
        'commercial_register_path', 'store_photo_path', 'address_proof_path',
        'profession_license_path', 'optional_document_path',
        'bank_name', 'bank_account_number', 'bank_account_holder',
        'contact_phone',
        'status', 'admin_note', 'reviewed_by_admin_id', 'reviewed_at',
        'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'reviewed_by_admin_id' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public const STATUSES = [
        'pending_review',
        'verified',
        'rejected',
        'resubmission_required',
        'verification_suspended',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }
}
