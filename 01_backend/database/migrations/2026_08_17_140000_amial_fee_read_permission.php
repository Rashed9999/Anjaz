<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-FEE-TRUTH-005 — **قراءةُ الرسوم غيرُ تعديلها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن:** كانت `platform.fees.update` وحدَها. فمن أراد أن **يقرأ**
 * تسعيرةً — محاسبٌ يراجع، أو مشرفٌ يتحقّق من شكوى — لزمه إذنُ **تغيير**
 * الرسوم. فإمّا يُمنع من الاطّلاع، وإمّا يُعطى مفتاحَ تغيير المال كلِّه.
 *
 * وهذا ما يمنعه `amial-rbac`: **أقلُّ صلاحيّةٍ تكفي**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثٌ لا اثنتان:**
 *
 *   `platform.fees.view`     يقرأ التسعيرَ والتاريخَ وتقريرَ الأرباح
 *   `platform.fees.update`   يُنشئ نسخةً جديدة
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا `platform.fees.approve` — والسببُ يُكتب لا يُسكت عنه.**
 *
 * أُنشئت أوّلاً لمسار maker–checker، **وحُجبت عن مدير المنصّة عمداً**
 * ليكون للاعتماد معنىً. فأسقطها حارسٌ قائم:
 *
 *     `PlatformPermissionExistenceGuardTest::
 *      test_the_platform_admin_holds_every_platform_permission`
 *
 * أي أنّ في المشروع **ثابتاً معلَناً**: مديرُ المنصّة يملك كلَّ صلاحيّات
 * المنصّة. وصلاحيّةٌ محجوبةٌ عنه تكسره.
 *
 * فالخياران: كسرُ الثابت، أو منحُها له — ومنحُها تُفرغ المراجعةَ من
 * معناها (‏يقترح ويعتمد بنفسه). وكلاهما أسوأُ من الثالث: **ألّا تُنشأ
 * حتّى يُقرَّر نموذجُ الفصل**.
 *
 * وهذا نصُّ ما تطلبه المواصفة: «إذا لم يكن Maker–Checker مناسبًا للبنية
 * الحالية، وثّق السبب ولا تبنِ نظامًا موازيًا.» فالسببُ هنا، **وقرارُ
 * فصل الأدوار قرارُ صاحب المشروع لا قرارُ شيفرة**.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.fees.view' => ['الاطّلاع على الرسوم وتقارير الأرباح', 'platform_money', true],
    ];

    private const GRANT_TO = 'platform_admin';

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $code => [$label, $category, $grant]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label_ar' => $label, 'category' => $category,
                 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // **الدورُ يُقرأ ولا يُخترَع** — كما في الهجرة الأمّ. فإن لم تكن
        // شُغّلت بعد، تُشغَّل هي أوّلاً ولا يُصنَع دورٌ نصفُ مُعرَّف.
        $roleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', self::GRANT_TO)
            ->value('id');

        if ($roleId === null) {
            return;
        }

        foreach (self::PERMISSIONS as $code => [$label, $category, $grant]) {
            if (! $grant) {
                continue;   // `approve` لا تُمنح تلقائيّاً — انظر رأس الملفّ.
            }

            $permId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permId === null) {
                continue;
            }

            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
    }
};
