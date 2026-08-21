<?php

/**
 * AMIAL-ZONE-RBAC-001 — **قطاعُ النطاقات كان بلا صلاحيّةٍ واحدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** ثلاثُ مجموعاتِ مساراتٍ للنطاقات في `routes/admin/amial.php`،
 * **ولا واحدةَ منها عليها `platform:`**:
 *
 *   · `zones/` و`zones/update`            — سياسةُ محافظات التشغيل
 *   · `zone/assign` و`assign-from-kyc`    — تغييرُ نطاقِ حسابٍ بعينه
 *   · `hub/zones/*` و`.../reassign`       — لوحةُ المناطق وإعادةُ الإسناد
 *
 * فكلُّ من يدخل اللوحة — موظّفُ دعمٍ أو صيانة — كان يستطيع نقلَ حسابٍ من
 * نطاقٍ إلى نطاق. **وهذا ليس تصنيفاً إداريّاً**: `EnforceZonePolicy` تقرأ
 * النطاقَ فتسمح بالحركة أو تمنعها. فنقلُ الحساب **يفتح أو يُغلق حركةَ
 * ماله**، ولا شيءَ في الشاشة ولا في الدور يقول إنّ الفاعلَ تجاوز حدَّه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا خمسٌ لا واحدة — والفصلُ مقصودٌ في كلّ حدّ:**
 *
 * | الصلاحيّة | ما تفتح | لماذا مفردة |
 * |---|---|---|
 * | `view` | القراءةُ والإحصاء | القراءةُ تُفصل عن الكتابة — وهي أوّلُ ما تطلبه الوثيقة |
 * | `assign` | الإسنادُ **من وثيقة KYC** | قرارٌ تحكمه وثيقةٌ موثّقة، فمداه محدود |
 * | `override` | الإسنادُ **اليدويُّ بسببٍ مكتوب** | **هذا هو التجاوز**: نطاقٌ يخالف ما تقوله الوثيقة. وجمعُه مع `assign` يجعل منحَ الأولى منحاً للثاني |
 * | `policy.update` | محافظاتُ التشغيل كلُّها | فعلٌ نادرٌ أثرُه على **كلّ** الحسابات لا على حساب |
 * | `audit.view` | سجلُّ نطاقِ شخصٍ وفحصُه الجغرافيّ | اطّلاعٌ على تاريخ **فردٍ** بعينه — لا يُمنح لمن يُشغّل اللوحة |
 *
 * ولا صلاحيّةَ ها هنا بلا مسارٍ يستعملها — فصلاحيّةٌ تُنشأ ولا تُحرس شيئاً
 * **تُمنح بحسن نيّةٍ ولا تمنع أحداً**، وهي أسوأ من غيابها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والدعمُ لا يُمنح شيئاً** — وهو نصُّ الوثيقة: «لا تجعل موظف دعم عادي
 * يستطيع تغيير منطقة حساب». ولا يُمنح `override` إلّا مديرُ المنصّة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string,array{0:string,1:string}> */
    private const PERMISSIONS = [
        'platform.zones.view' => [
            'قراءة لوحة المناطق ونطاق التشغيل', 'platform_zones',
        ],
        'platform.zones.assign' => [
            'إسناد نطاق حساب من وثيقة KYC', 'platform_zones',
        ],
        'platform.zones.override' => [
            'إسناد نطاق يدويّاً بخلاف الوثيقة (تجاوز)', 'platform_zones',
        ],
        'platform.zones.policy.update' => [
            'تعديل محافظات التشغيل (يمسّ كلّ الحسابات)', 'platform_zones',
        ],
        'platform.zones.audit.view' => [
            'قراءة سجلّ نطاق حساب وفحصه الجغرافيّ', 'platform_zones',
        ],
    ];

    /**
     * الدورُ ← ما يُمنح له.
     *
     * @var array<string,list<string>>
     */
    private const GRANTS = [
        'platform_admin' => [
            'platform.zones.view', 'platform.zones.assign',
            'platform.zones.override', 'platform.zones.policy.update',
            'platform.zones.audit.view',
        ],
        // الامتثالُ صاحبُ وثائق الهويّة، فالإسنادُ منها عملُه — **ولا
        // يُمنح التجاوزَ**: مخالفةُ الوثيقة قرارُ إدارةٍ لا قرارُ مدقّق.
        'platform_compliance' => [
            'platform.zones.view', 'platform.zones.assign',
            'platform.zones.audit.view',
        ],
        // المخاطرُ تقرأ ولا تُسند.
        'platform_risk' => [
            'platform.zones.view', 'platform.zones.audit.view',
        ],
        'platform_supervisor' => [
            'platform.zones.view',
        ],
        // **ولا شيءَ للدعم ولا للصيانة** — بنصّ الوثيقة.
    ];

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

        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')
                ->whereNull('merchant_user_id')
                ->where('code', $roleCode)
                ->value('id');

            // الهجرةُ الأمُّ لم تُشغَّل بعد؟ لا يُخترَع دور — تُشغَّل هي أوّلاً.
            if ($roleId === null) {
                continue;
            }

            foreach ($codes as $code) {
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
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
