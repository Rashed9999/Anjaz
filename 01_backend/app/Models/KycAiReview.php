<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** نتيجة استشارية مشفرة؛ لا تمنح أي صلاحية لاتخاذ قرار KYC. */
class KycAiReview extends Model
{
    protected $fillable = [
        'user_id', 'requested_by', 'model', 'input_digest', 'status',
        'report_encrypted', 'prompt_tokens', 'completion_tokens',
    ];

    protected $hidden = ['report_encrypted'];

    protected $casts = [
        'report_encrypted' => 'encrypted:array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
    ];
}
