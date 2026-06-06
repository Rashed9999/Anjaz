<?php

namespace Database\Seeders;

use App\Models\CharityCategory;
use Illuminate\Database\Seeder;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * بذور التصنيفات الأساسية للحملات الخيرية.
 */
class CharityCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'emergency', 'name_ar' => 'إغاثة عاجلة', 'icon' => 'emergency', 'sort_order' => 1],
            ['code' => 'food', 'name_ar' => 'طعام وغذاء', 'icon' => 'food', 'sort_order' => 2],
            ['code' => 'medical', 'name_ar' => 'علاج ودواء', 'icon' => 'medical', 'sort_order' => 3],
            ['code' => 'water', 'name_ar' => 'مياه ونظافة', 'icon' => 'water', 'sort_order' => 4],
            ['code' => 'shelter', 'name_ar' => 'مأوى وسكن', 'icon' => 'home', 'sort_order' => 5],
            ['code' => 'education', 'name_ar' => 'تعليم وأيتام', 'icon' => 'school', 'sort_order' => 6],
            ['code' => 'orphans', 'name_ar' => 'كفالة يتيم', 'icon' => 'child', 'sort_order' => 7],
            ['code' => 'mosques', 'name_ar' => 'مساجد', 'icon' => 'mosque', 'sort_order' => 8],
            ['code' => 'general', 'name_ar' => 'صدقة عامة', 'icon' => 'heart', 'sort_order' => 9],
        ];

        foreach ($categories as $cat) {
            CharityCategory::updateOrCreate(
                ['code' => $cat['code']],
                array_merge($cat, ['is_active' => true]),
            );
        }

        $this->command->info('✓ Seeded ' . count($categories) . ' charity categories');
    }
}
