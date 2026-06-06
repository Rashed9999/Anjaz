<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyCustomer extends Model
{
    protected $table = 'pharmacy_customers';

    protected $fillable = [
        'pharmacy_id', 'full_name', 'phone', 'date_of_birth', 'gender',
        'is_pregnant', 'is_breastfeeding',
        'allergies', 'chronic_conditions', 'regular_medications',
        'notes', 'is_active',
    ];

    protected $casts = [
        'pharmacy_id' => 'integer',
        'date_of_birth' => 'date',
        'is_pregnant' => 'boolean',
        'is_breastfeeding' => 'boolean',
        'allergies' => 'array',
        'chronic_conditions' => 'array',
        'regular_medications' => 'array',
        'is_active' => 'boolean',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(PharmacySale::class, 'customer_id');
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /**
     * فحص الحساسية: هل المنتج يحتوي على أيّ مادة في قائمة حساسيات المريض؟
     * يعتمد على مقارنة الاسم العلمي/التجاري للمنتج بقائمة الحساسيات.
     */
    public function checkAllergyConflict(PharmacyProduct $product): array
    {
        $conflicts = [];
        $allergies = $this->allergies ?? [];

        foreach ($allergies as $allergen) {
            $needle = mb_strtolower(trim($allergen));
            if ($needle === '') continue;

            $haystack = mb_strtolower(
                ($product->trade_name ?? '') . ' ' .
                ($product->generic_name ?? '')
            );
            if (mb_strpos($haystack, $needle) !== false) {
                $conflicts[] = $allergen;
            }
        }
        return $conflicts;
    }
}
