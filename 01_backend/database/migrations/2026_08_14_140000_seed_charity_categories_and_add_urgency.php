<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CHARITY-META-001 — **التصنيفاتُ تُزرع، والعجلةُ تُقال.**
 *
 * ══════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** فتح صاحبُ المشروع نموذجَ الحملة فقرأ في خانة
 * التصنيف: «— لا تصنيفات مسجَّلة —». والتصنيفُ إلزاميّ، فالحملةُ **لا
 * تُنشأ أصلاً**. وقال: «لا علامات مساعدة طبيّة أو إنسانيّة أو غذائيّة».
 *
 * و`CharityCategoriesSeeder` مكتوبٌ منذ بُنيت التبرّعات، **ومسجَّلٌ في
 * `DatabaseSeeder`** — ولم يُزرع قطّ على الخادم الحيّ. لأنّ `db:seed`
 * أمرٌ يدويٌّ لا يجري في النشر، والهجراتُ وحدها تجري.
 *
 * **فبذرةٌ لا يشغّلها النشرُ ليست مزروعة** — وهي أختُ «مبنيٌّ ولا
 * يُوصَل إليه». فنُقلت إلى هجرة: تجري مرّةً في كلّ بيئة، وتُعيد الزرعَ
 * إن حُذف صفٌّ، ولا تكرّر إن وُجد.
 *
 * ويُضاف `is_urgent`: **«عاجل» حكمٌ إداريٌّ لا تصنيف.** فحملةُ إغاثةٍ
 * قد لا تكون عاجلةً، وحملةُ علاجٍ قد تكون — فلا يصحّ استنتاجُه من
 * التصنيف.
 * ══════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    /** الرمزُ مفتاحُ الهويّة — والاسمُ يُصحَّح فوقه إن تغيّر. */
    private const CATEGORIES = [
        ['code' => 'emergency', 'name_ar' => 'إغاثة عاجلة', 'icon' => 'emergency', 'sort_order' => 1],
        ['code' => 'food',      'name_ar' => 'طعام وغذاء',  'icon' => 'food',      'sort_order' => 2],
        ['code' => 'medical',   'name_ar' => 'علاج ودواء',  'icon' => 'medical',   'sort_order' => 3],
        ['code' => 'water',     'name_ar' => 'مياه ونظافة', 'icon' => 'water',     'sort_order' => 4],
        ['code' => 'shelter',   'name_ar' => 'مأوى وسكن',   'icon' => 'home',      'sort_order' => 5],
        ['code' => 'education', 'name_ar' => 'تعليم',        'icon' => 'school',    'sort_order' => 6],
        ['code' => 'orphans',   'name_ar' => 'كفالة يتيم',  'icon' => 'child',     'sort_order' => 7],
        ['code' => 'mosques',   'name_ar' => 'مساجد',        'icon' => 'mosque',    'sort_order' => 8],
        ['code' => 'general',   'name_ar' => 'صدقة عامة',   'icon' => 'heart',     'sort_order' => 9],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('charity_campaigns', 'is_urgent')) {
            Schema::table('charity_campaigns', function (Blueprint $table) {
                $table->boolean('is_urgent')->default(false)->after('is_featured');
                // الفرزُ في التطبيق «العاجلُ أوّلاً» — فبلا فهرسٍ مسحٌ كامل.
                $table->index(['status', 'is_urgent'], 'charity_campaigns_urgent_idx');
            });
        }

        foreach (self::CATEGORIES as $cat) {
            DB::table('charity_categories')->updateOrInsert(
                ['code' => $cat['code']],
                $cat + ['is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        // **التصنيفاتُ لا تُحذف في التراجع.** حملاتٌ قائمةٌ تشير إليها،
        // وحذفُها يترك حملاتٍ بتصنيفٍ معدوم — وذاك عطبٌ أكبر من عمودٍ زائد.
        if (Schema::hasColumn('charity_campaigns', 'is_urgent')) {
            Schema::table('charity_campaigns', function (Blueprint $table) {
                $table->dropIndex('charity_campaigns_urgent_idx');
                $table->dropColumn('is_urgent');
            });
        }
    }
};
