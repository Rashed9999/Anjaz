<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPERATOR-RBAC-003 — أدوارُ فريق أميال الخمسة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «لا يجوز أن يستطيع كلّ موظّف في أميال رؤية كلّ شيء.»
 *
 * وكان في اللوحة أربعةُ أدوار عامّة (مدير · إشراف · دعم · صيانة)، وكان
 * مركزُ التاجر كلُّه محميّاً بصلاحيّتين اثنتين: `customers.view` لكلّ
 * القراءات و`audit.view` لكلّ ما يمسّ المال. فمن يفتح ملفّ تاجرٍ ليردّ
 * على تذكرةٍ يقرأ تسوياته وعمولاته، ومن يراجع الامتثال يقرأ أرصدته.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي كُشف وهذه الهجرة تُصلحه — والأخطر من التقسيم نفسه:**
 *
 * `platform.settings.manage` كانت مستعملةً في أحدَ عشرَ مساراً — منها
 * **مركزُ الباقات والقدرات بأكمله** — **ولا وجود لها في جدول
 * `permissions` أصلاً**. و`hasPlatformPermission` تبحث عن الرمز في
 * قائمةٍ مبنيّةٍ من الجدول، فتُرجع `false` لكلّ من سُئل.
 *
 * **ومديرُ المنصّة ليس استثناءً**: دورُه `*` تُوسَّع لحظةَ الهجرة إلى
 * أسماء الصلاحيّات المعرَّفة **يومئذٍ**، وليست هذه فيها. فالمركزُ كان
 * مبنيّاً ومسجَّلاً ومختبَراً — **ولا يفتحه إنسان**. وهو نمطُ العطل
 * الأكثر تكراراً في أميال باي: مبنيٌّ ولا يُوصَل إليه.
 *
 * ولذلك وُلد `PlatformPermissionExistenceGuardTest` مع هذه الهجرة: كلّ
 * رمزٍ يطلبه وسيطُ `platform:` في أيّ مسار **لا بدّ أن يكون في الجدول**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا صلاحيّاتٌ جديدةٌ لا أدوارٌ فقط:** الدورُ يُسنَد والصلاحيّةُ
 * تُفحَص. فتقسيمُ الأدوار بلا تقسيم الصلاحيّات يُعيد الجميع إلى الباب
 * نفسه باسمٍ آخر.
 */
return new class extends Migration
{
    /**
     * الصلاحيّات الجديدة — كلٌّ منها بابٌ مستقلٌّ في مركز التاجر.
     *
     * `platform.settings.manage` ليست جديدةً في الاستعمال — هي جديدةٌ
     * في **الوجود**. وكانت الشيفرةُ تطلبها منذ يومين.
     */
    private const PERMISSIONS = [
        'platform.merchants.money' => [
            'مال التاجر: الأرصدة وكشف الحساب والتسويات والعمولات', 'platform_read'],
        'platform.merchants.risk' => [
            'مخاطر التاجر: الإشارات والأجهزة والجلسات', 'platform_read'],
        'platform.merchants.compliance' => [
            'امتثال التاجر: التوثيق والوثائق والقيود', 'platform_read'],
        'platform.merchants.investigate' => [
            'منح إذن اطّلاع تشغيليّ مؤقّت على تفاصيل التاجر', 'platform_decide'],
        'platform.settings.manage' => [
            'إدارة الباقات والقدرات والتسعير', 'platform_settings'],
    ];

    /**
     * الأدوار الخمسة كما وصفها صاحب المشروع — بلا زيادةٍ ولا تأويل.
     *
     * والأدوارُ الأربعةُ القديمة تبقى: هي مسندةٌ لحساباتٍ قائمة، وحذفُها
     * يُخرج أصحابَها من اللوحة بلا سبب. وهذه تُضاف إلى جانبها.
     */
    private const ROLES = [
        // «يستطيع: الملف · حالة الحساب · التذاكر · العمليات الأساسية.
        //  ولا يستطيع: تعديل التسويات · تعديل الأرصدة · تغيير العمولات.»
        //
        // فلا `merchants.money` هنا. والدعمُ يرى أنّ الحساب نشطٌ وكم عمليّةً
        // نفّذ، ولا يرى كم يملك.
        'platform_support' => ['فريق دعم العملاء', [
            'platform.customers.view',
            'platform.transactions.view',
            'platform.receipts.view',
            'platform.tickets.manage',
        ]],

        // «يستطيع: العمليات المالية · كشف الحساب · التسويات · العمولات.
        //  ولا يستطيع: تعديل KYC.»
        'platform_finance' => ['فريق المالية', [
            'platform.customers.view',
            'platform.transactions.view',
            'platform.receipts.view',
            'platform.merchants.money',
        ]],

        // «يستطيع: المخاطر · العمليات · الأجهزة · Audit · التجميد الأمني.»
        //
        // وهو الدورُ الوحيد الذي يجمع القراءةَ إلى الفعل — ولذلك كلُّ فعلٍ
        // منه يمرّ بسببٍ إلزاميٍّ وسطرِ تدقيقٍ بحالةٍ قبل وبعد.
        'platform_risk' => ['فريق المخاطر', [
            'platform.customers.view',
            'platform.transactions.view',
            'platform.merchants.risk',
            'platform.audit.view',
            'platform.customers.freeze',
            'platform.merchants.investigate',
        ]],

        // «يستطيع: KYC · الوثائق · المراجعات · القيود.»
        //
        // وله `audit.view` لأنّ «المراجعات» بلا تاريخِ ما جرى مراجعةُ
        // لحظةٍ واحدة. وله `approvals.decide` لأنّ اعتماد التوثيق هو عملُه.
        'platform_compliance' => ['فريق الامتثال', [
            'platform.customers.view',
            'platform.merchants.compliance',
            'platform.approvals.decide',
            'platform.audit.view',
            'platform.merchants.investigate',
        ]],
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

        foreach (self::ROLES as $code => [$label, $perms]) {
            DB::table('roles')->updateOrInsert(
                ['merchant_user_id' => null, 'code' => $code],
                ['label_ar' => $label, 'is_system' => true,
                 'created_at' => $now, 'updated_at' => $now],
            );

            $roleId = DB::table('roles')
                ->whereNull('merchant_user_id')->where('code', $code)->value('id');

            foreach ($perms as $pCode) {
                $pId = DB::table('permissions')->where('code', $pCode)->value('id');

                if (! $pId) {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $pId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        // ── مديرُ المنصّة يملك كلَّ صلاحيّةٍ **موجودةٍ الآن** ──
        //
        // وهذا ما سقط في الهجرة السابقة: `*` وُسِّعت مرّةً وجُمِّدت. فكلّ
        // صلاحيّةٍ تُضاف بعدها لا يملكها أحد — ولا يظهر ذلك خطأً، بل ٤٠٣
        // على شاشةٍ يُظنّ أنّها تعمل.
        //
        // فتُقرأ الصلاحيّاتُ من الجدول لا من ثابتٍ في ملفّ، ويُعاد الربطُ
        // كلَّ مرّة. وهي `updateOrInsert` فتُعاد بأمان.
        $adminRoleId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', 'platform_admin')->value('id');

        if ($adminRoleId) {
            $all = DB::table('permissions')->where('code', 'like', 'platform.%')->pluck('id');

            foreach ($all as $pId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $adminRoleId, 'permission_id' => $pId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->whereNull('merchant_user_id')
            ->whereIn('code', ['platform_finance', 'platform_risk', 'platform_compliance'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();

        $pIds = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $pIds)->delete();
        DB::table('permissions')->whereIn('id', $pIds)->delete();
    }
};
