<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneVerification extends Model
{
    /**
     * AMIAL-OTP-BRUTEFORCE-001 — **نموذجٌ بلا `$fillable` إطلاقاً.**
     *
     * فكلُّ `create()` أو `update()` جماعيٍّ عليه يُرفض بـ
     * `MassAssignmentException`. ولذلك كان كلُّ من يكتب في هذا الجدول
     * يمرّ بـ`DB::table(...)` مباشرةً — يتجاوز النموذجَ وقوالبَه
     * وأحداثَه.
     *
     * وهو سببٌ صامتٌ لعطلٍ آخر: عدّادُ المحاولات موجودٌ في الجدول ولم
     * يُستعمل قطّ — ومن حاول لقي استثناءً لا رسالةَ خطأ فيه تدلّ على
     * أنّ السبب `$fillable` غائب.
     */
    protected $fillable = [
        'phone', 'otp',
        'otp_hit_count', 'is_temp_blocked', 'temp_block_time',
    ];

    protected $casts = [
        'otp_hit_count' => 'integer',
        'is_temp_blocked' => 'boolean',
        'temp_block_time' => 'datetime',
        'phone' => 'string',
        'otp' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
