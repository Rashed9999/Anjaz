<?php

/**
 * AMIAL-ADMIN-DOORS-001 — **«اطّلاعٌ على حركة» ليس «اطّلاعاً على المنصّة».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** أنشأ صاحبُ المشروع حسابَ موظّفِ دعمٍ ودخل به، فرأى
 * في الصفحة الأولى:
 *
 *   · حجمَ معاملات السنة كاملاً — ٤١٠٬٦٨٨ ريالاً، ورسماً بيانيّاً شهريّاً.
 *   · عددَ العملاء والتجّار والوكلاء في المنصّة.
 *   · **«أعلى الوكلاء تعاملاً» و«أعلى العملاء تعاملاً» بالأسماء والمبالغ.**
 *
 * واللوحةُ كانت تعمل **كما صُمّمت**: تحجب الأرصدةَ والأرباح بـ`money.move`
 * (ولذلك ظهرت لافتةُ «لا تُتاح بصلاحيتك»)، وتبني الباقيَ على
 * `platform.transactions.view` و`platform.customers.view` — **ودورُ الدعم
 * يملكهما**.
 *
 * **فالعطلُ في التصميم لا في التنفيذ**، وهو خلطُ حبّتين:
 *
 *   · `transactions.view` مبنيّةٌ لـ**«أرِني حركةَ هذا العميل»** — وهي
 *     عملُ الدعم اليوميّ ولا غنى له عنها.
 *   · وما في الصفحة الأولى **مجاميعُ المنصّة**: حجمُها السنويُّ وترتيبُ
 *     عملائها. وذاك سؤالُ إدارةٍ لا سؤالُ دعم.
 *
 * ومن يعرف من أكبرُ عملائنا وكم يُحرّكون يملك ما يُباع — **ولا يحتاج
 * إلى الأرصدة ليضرّ**.
 *
 * وهو النمطُ نفسُه الذي فُصل به `zones.override` عن `zones.assign` قبل
 * قليل: **جمعُ المقيَّد بالمطلق في صلاحيّةٍ واحدةٍ يجعل منحَ الأوّل منحاً
 * للثاني.**
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'platform.analytics.view';

    /**
     * **ولا تُمنح للدعم ولا للصيانة.**
     *
     * والمخاطرُ والامتثالُ يُمنحانها: قراءةُ «من أكبرُ المتعاملين» هي
     * أداةُ عملهما الأولى في كشف الغسل — ومنعُها عنهما يشلّ التحقيق.
     */
    private const GRANT_TO = [
        'platform_admin',
        'platform_supervisor',
        'platform_finance',
        'platform_risk',
        'platform_compliance',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['code' => self::CODE],
            [
                'label_ar' => 'عرض مجاميع المنصّة وترتيب المتعاملين',
                'category' => 'platform_read',
                'created_at' => $now, 'updated_at' => $now,
            ],
        );

        $permId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permId === null) {
            return;
        }

        foreach (self::GRANT_TO as $roleCode) {
            $roleId = DB::table('roles')
                ->whereNull('merchant_user_id')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
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
        $id = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
