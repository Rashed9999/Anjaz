<?php

/**
 * AMIAL-OPERATOR-RBAC-003 — المالُ والإعدادات لمدير المنصّة وحده.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كان قائماً:** واحدٌ وأربعون مساراً إداريّاً يطلب صلاحية — من أصل
 * مئتين وستّةٍ وسبعين. والباقي يكتفي بـ«هل هذا موظّف منصّة؟».
 *
 * فمَن دخل بحساب دعمٍ كان يستطيع اعتماد تسوية وكيل، وتعديل نسب الرسوم،
 * وشحن محفظة وكيلٍ من المنصّة. ولا شيء في الشاشة ولا في السجلّ يقول إنّه
 * تجاوز دوره — لأنّ الدور لم يكن يُسأل أصلاً على تلك المسارات.
 *
 * وهذه أخطر ثلاثة في المنصّة: **التسوية والرسوم والتمويل**. فتُفرَد لها
 * صلاحيّتان لا تُمنحان إلّا لمدير المنصّة.
 *
 * ولماذا اثنتان لا واحدة: «قرارُ تسوية» فعلٌ يوميٌّ متكرّر، و«تغييرُ نسبة
 * ربح» فعلٌ نادرٌ أثرُه دائم. وجمعُهما في صلاحيةٍ واحدة يجعل منحَ الأولى
 * منحاً للثانية — وهو ما يقع دائماً حين تُخلط الحبّات.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.money.move' => [
            'تحريك المال: تسويات وتمويل الوكلاء', 'platform_money',
        ],
        'platform.fees.update' => [
            'تعديل الرسوم ونسب الأرباح', 'platform_money',
        ],
    ];

    /** لا تُمنحان إلّا لهذا الدور. */
    private const GRANT_TO = 'platform_admin';

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $code => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label_ar' => $label, 'category' => $category,
                 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $roleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', self::GRANT_TO)
            ->value('id');

        // الهجرة الأمّ لم تُشغَّل بعد؟ لا نخترع الدور — تُشغَّل هي أوّلاً.
        if ($roleId === null) {
            return;
        }

        foreach (array_keys(self::PERMISSIONS) as $code) {
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
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
