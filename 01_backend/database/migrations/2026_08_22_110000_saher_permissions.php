<?php

/**
 * SAHER-FOUNDATION-002 — **صلاحيّاتُ ساهر، والدليلُ أضيقُ من العرض.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * §26 من المخطّط: «ساهر لا يفتح تلقائيًا لكل Admin. صلاحيات Evidence
 * والمالية والبنية التحتية مستقلة.»
 *
 * **والسببُ ليس تشدّداً:** الدليلُ في ساهر يحمل مقتطعاتِ شيفرةٍ وأسماءَ
 * مسارات ومواضعَ حرّاسٍ ونتائجَ استعلامات. **وخريطةُ الأسطح غير المحروسة
 * هي بعينها خريطةُ الهجوم** — من يقرؤها يعرف أين يطرق. فعرضُ العدد
 * («سبعةُ أسطحٍ غيرُ محروسة») ليس كعرض الدليل («أيُّها، وفي أيّ ملفّ،
 * وأيُّ حارسٍ غاب»).
 *
 * ولهذا `saher.view` غير `saher.evidence.view`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا تُمنح واحدةٌ منها لدورٍ قائمٍ بحجّة «لا يفقد أحدٌ ما يفعله».**
 *
 * وهذا درسُ هذه الجلسة نفسِها: هجرةٌ منحت `aml.decide` لكلّ من يملك
 * `audit.view` «حفاظاً على القائم» — **وما كان يفعله هؤلاء هو العطلُ
 * نفسُه**. وساهرٌ جديدٌ لا قائمَ له يُحفَظ، فيُمنح لمن يحتاجه ابتداءً:
 *
 *   · العرضُ والاكتشافات → الإدارة · المدقّق · الأمن
 *   · الدليلُ            → الإدارة · الأمن        (خريطةُ الهجوم)
 *   · النزاهةُ الماليّة   → الإدارة · الماليّة · المدقّق
 *   · تشغيلُ فحصٍ يدويّ  → الإدارة · الصيانة
 *   · الكتمُ وخطُّ الأساس → **الإدارة وحدَها** — إخفاءُ اكتشافٍ قرارٌ
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** الرمز ← [الوصف, الفئة, من يملكها **وحدَهم**] */
    private const GRANTS = [
        'saher.view' => [
            'فتح ساهر ومركز قيادته', 'saher',
            ['platform_admin', 'platform_auditor', 'platform_security'],
        ],
        'saher.findings.view' => [
            'عرض الاكتشافات وتفاصيلها', 'saher',
            ['platform_admin', 'platform_auditor', 'platform_security'],
        ],
        'saher.evidence.view' => [
            'عرض أدلّة الاكتشاف — مقتطعات الشيفرة ومواضع الحرّاس', 'saher',
            ['platform_admin', 'platform_security'],
        ],
        'saher.financial.view' => [
            'عرض نتائج ثوابت النزاهة الماليّة', 'saher',
            ['platform_admin', 'platform_finance', 'platform_auditor'],
        ],
        'saher.guards.view' => [
            'عرض جرد الحرّاس والأسطح غير المحروسة', 'saher',
            ['platform_admin', 'platform_security', 'platform_auditor'],
        ],
        'saher.scan.run' => [
            'تشغيل فحص ساهر يدويّاً', 'saher_act',
            ['platform_admin', 'platform_maintenance'],
        ],
        'saher.findings.acknowledge' => [
            'الإقرار باكتشاف وتغيير حالته', 'saher_act',
            ['platform_admin', 'platform_security'],
        ],
        'saher.findings.suppress' => [
            'كتم اكتشاف أو قبوله مخاطرةً', 'saher_act',
            ['platform_admin'],
        ],
        'saher.baseline.manage' => [
            'إنشاء خطّ أساس وتفعيله', 'saher_act',
            ['platform_admin'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GRANTS as $code => [$label, $category, $roleCodes]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label_ar' => $label, 'category' => $category,
                    'created_at' => $now, 'updated_at' => $now],
            );

            $permId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permId === null) {
                continue;
            }

            // **قائمةُ حائزين مغلقة** — تُنزَع ممّن ليس فيها وتُمنح لمن فيها.
            // فمنحةٌ تُضاف ولا تُنزَع تتراكم حتّى يملك الجميعُ كلَّ شيء.
            $keep = DB::table('roles')->whereNull('merchant_user_id')
                ->whereIn('code', $roleCodes)->pluck('id');

            DB::table('role_permissions')->where('permission_id', $permId)
                ->whereNotIn('role_id', $keep->all() ?: [0])->delete();

            foreach ($keep as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        // ── مصادرُ ساهر — تُسجَّل بحالة «لم يُشغَّل» لا بحالة «سليم» ────
        foreach ([
            ['guards', 'جرد الحرّاس والأسطح غير المحروسة', 1440],
            ['routes', 'المسارات وحرّاسها', 1440],
        ] as [$code, $label, $stale]) {
            DB::table('saher_sources')->updateOrInsert(
                ['code' => $code],
                [
                    'label_ar' => $label,
                    'health' => 'NOT_CONFIGURED',
                    'health_reason' => 'لم تُشغَّل جولةُ فحصٍ بعد',
                    'stale_after_minutes' => $stale,
                    'is_enabled' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::GRANTS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
