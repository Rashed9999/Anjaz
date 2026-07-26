<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FavouriteNumber extends Model
{
    /**
     * AMIAL-FAVORITES-001 — أنواع المفضّلة.
     *
     * contact   جهة اتصال (هاتف) — النوع الوحيد قبل التوسيع، ويُغذّي خصم
     *           رسوم التحويل والسحب. لا يجوز أن تختلط به الأنواع الأخرى.
     * account   رقم حساب في أميال باي (لمن يحوّل لحسابه أو لحساب شركة)
     * operation عملية سابقة تُعاد بنفس تفاصيلها (إيجار، اشتراك، تحويل شهري)
     * merchant  تاجر متكرّر الشراء منه
     */
    public const KIND_CONTACT = 'contact';
    public const KIND_ACCOUNT = 'account';
    public const KIND_OPERATION = 'operation';
    public const KIND_MERCHANT = 'merchant';

    public const KINDS = [
        self::KIND_CONTACT,
        self::KIND_ACCOUNT,
        self::KIND_OPERATION,
        self::KIND_MERCHANT,
    ];

    protected $casts = [
        'user_id' => 'string',
        'type' => 'string',
        'name' => 'string',
        'phone' => 'string',
        'metadata' => 'array',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'phone',
        'kind',
        'value',
        'metadata',
    ];

    /**
     * جهات الاتصال وحدها.
     *
     * خصم رسوم المفضّلة يقرأ هذا الجدول ويطابق بالهاتف. بعد التوسيع صار
     * فيه صفوف بلا هاتف (عمليات، حسابات)، فوجب حصر القراءة بالنوع — وإلا
     * تسرّبت قيم غير هواتف إلى قائمة المطابقة.
     */
    public function scopeContacts($query)
    {
        return $query->where('kind', self::KIND_CONTACT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
