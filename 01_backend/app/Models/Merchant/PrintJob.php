<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Branch;
use App\Models\PosUser;
use App\Models\User;

class PrintJob extends Model
{
    protected $table = 'merchant_print_jobs';

    protected $fillable = [
        'job_ulid', 'client_job_id', 'merchant_user_id', 'branch_id',
        'pos_user_id', 'pos_device_id', 'printer_profile_id',
        'requested_by_user_id', 'document_type', 'document_id',
        'document_number', 'status', 'copies', 'retry_count',
        'error_code', 'error_message', 'result_message',
        'queued_at', 'started_at', 'completed_at', 'failed_at', 'metadata',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'branch_id' => 'integer',
        'pos_user_id' => 'integer',
        'pos_device_id' => 'integer',
        'printer_profile_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'copies' => 'integer',
        'retry_count' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const STATUSES = [
        'queued', 'printing', 'completed', 'failed', 'cancelled', 'retrying',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'printer_profile_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function posUser(): BelongsTo
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
