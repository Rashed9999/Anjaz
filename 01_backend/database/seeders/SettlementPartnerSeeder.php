<?php

namespace Database\Seeders;

use App\Models\SettlementPartner;
use Illuminate\Database\Seeder;

class SettlementPartnerSeeder extends Seeder
{
    public function run(): void
    {
        $partners = [
            ['code' => 'ALOMKI',   'name_ar' => 'شركة العمقي للصرافة والحوالات', 'name_en' => 'Al-Omki Exchange',     'type' => SettlementPartner::TYPE_EXCHANGE, 'currency' => 'YER'],
            ['code' => 'ALKARIMI', 'name_ar' => 'بنك الكريمي',                    'name_en' => 'Al-Karimi Bank',        'type' => SettlementPartner::TYPE_BANK,     'currency' => 'YER'],
            ['code' => 'BINDAWL',  'name_ar' => 'شركة بن دول للصرافة',           'name_en' => 'Bin Dawl Exchange',     'type' => SettlementPartner::TYPE_EXCHANGE, 'currency' => 'YER'],
            ['code' => 'CBY',      'name_ar' => 'البنك المركزي اليمني',          'name_en' => 'Central Bank of Yemen', 'type' => SettlementPartner::TYPE_BANK,     'currency' => 'YER'],
            ['code' => 'ALHUDA',   'name_ar' => 'بنك الهدى الإسلامي',            'name_en' => 'Al-Huda Islamic Bank',  'type' => SettlementPartner::TYPE_BANK,     'currency' => 'YER'],
        ];

        foreach ($partners as $data) {
            SettlementPartner::updateOrCreate(['code' => $data['code']], array_merge($data, ['is_active' => true]));
        }

        $this->command->info('✓ ' . count($partners) . ' settlement partners seeded');
    }
}
