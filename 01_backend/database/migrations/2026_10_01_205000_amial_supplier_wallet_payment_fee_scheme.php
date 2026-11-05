<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * سداد المورد من المحفظة عملية إنتاجية لا يجوز أن تعتمد على Seeder يدوي.
 * نزرع النسخة الصفرية مرة واحدة فقط، ويمكن للإدارة إصدار version لاحقة
 * من شاشة الرسوم. الهجرة لا تستبدل أي إعداد قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('fee_schemes')
            ->where('code', 'SUPPLIER_PAYMENT')
            ->where('zone_code', 'SOUTH')
            ->where('applies_to', 'merchant')
            ->where('is_active', true)
            ->exists();

        if ($exists) return;

        DB::table('fee_schemes')->insert([
            'code' => 'SUPPLIER_PAYMENT',
            'label' => 'سداد مورد من المحفظة',
            'zone_code' => 'SOUTH',
            'applies_to' => 'merchant',
            'fee_type' => 'fixed',
            'percent_rate' => 0,
            'fixed_amount' => 0,
            'min_fee' => null,
            'max_fee' => null,
            'agent_commission_percent' => 0,
            'agent_commission_fixed' => 0,
            'bearer' => 'merchant',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now(),
            'notes' => 'Default supplier wallet settlement pricing; editable/versioned from fee management.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('fee_schemes')
            ->where('code', 'SUPPLIER_PAYMENT')
            ->where('zone_code', 'SOUTH')
            ->where('applies_to', 'merchant')
            ->where('version', 1)
            ->where('notes', 'Default supplier wallet settlement pricing; editable/versioned from fee management.')
            ->delete();
    }
};
