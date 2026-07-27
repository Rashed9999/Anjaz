<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-OPERATOR-RBAC-001 — فصل صلاحيات فريق المنصّة.
 *
 * **الحال قبل هذه الهجرة:**
 * حارس لوحة الإدارة كلّه سطر واحد — `type === ADMIN_TYPE` — يفتح 125 مساراً.
 * فمن يدخل يستطيع تجميد العملاء وتصفير رموزهم السرّية وإلغاء جلساتهم واعتماد
 * التوثيق وتغيير إعدادات المنصّة، أياً كان عمله.
 *
 * وموظّف الدعم الذي يحتاج قراءة سجلّ عميل يستطيع تصفير رمزه السرّي.
 *
 * **ولماذا هذا ضابطٌ لا تحسين:**
 * سجلّ التدقيق غير القابل للحذف يقول «من فعل»، ولا يقول «من كان يحقّ له».
 * وفصل الصلاحيات هو ما يجعل السجلّ ذا معنى: فعلٌ خارج دور صاحبه يصير مستحيلاً
 * لا مكتشَفاً بعد وقوعه.
 *
 * **إعادة استعمال ما هو قائم:** جداول roles/permissions موجودة لموظّفي
 * التاجر، و`merchant_user_id = null` معرَّف فيها أصلاً بمعنى «دور نظام».
 * فأدوار المنصّة تسكنها بلا جدول مواز، ويبقى الإسناد وحده جديداً.
 */
return new class extends Migration
{
    /** أدوار فريق المنصّة وصلاحيات كلّ منها. */
    private const ROLES = [
        'platform_admin' => ['مدير المنصّة', '*'],

        'platform_support' => ['فريق الدعم', [
            'platform.customers.view',
            'platform.transactions.view',
            'platform.receipts.view',
            'platform.tickets.manage',
        ]],

        'platform_maintenance' => ['فريق الصيانة', [
            'platform.ops.view',
            'platform.ops.retry',
            'platform.transactions.view',
        ]],

        'platform_supervisor' => ['فريق الإشراف', [
            'platform.customers.view',
            'platform.transactions.view',
            'platform.receipts.view',
            'platform.audit.view',
            'platform.approvals.decide',
            'platform.disputes.decide',
            'platform.ops.view',
        ]],
    ];

    private const PERMISSIONS = [
        // قراءة
        'platform.customers.view'   => ['عرض ملفّ العميل', 'platform_read'],
        'platform.transactions.view' => ['عرض الحركات', 'platform_read'],
        'platform.receipts.view'    => ['عرض الإيصالات', 'platform_read'],
        'platform.audit.view'       => ['عرض سجلّ التدقيق', 'platform_read'],
        'platform.ops.view'         => ['عرض حالة التشغيل', 'platform_read'],

        // أفعال تمسّ حساب عميل
        'platform.customers.freeze'    => ['تجميد حساب', 'platform_account'],
        'platform.customers.reset_pin' => ['تصفير الرمز السرّي', 'platform_account'],
        'platform.customers.sessions'  => ['إلغاء الجلسات', 'platform_account'],

        // قرارات
        'platform.approvals.decide' => ['اعتماد التوثيق ورفضه', 'platform_decide'],
        'platform.disputes.decide'  => ['الفصل في النزاعات', 'platform_decide'],

        // تشغيل
        'platform.ops.retry' => ['إعادة تشغيل المهامّ الفاشلة', 'platform_ops'],

        // دعم
        'platform.tickets.manage' => ['إدارة تذاكر الدعم', 'platform_support'],

        // إعدادات المنصّة — أخطرها، فلا تُمنح إلا لمدير المنصّة
        'platform.settings.update' => ['تعديل إعدادات المنصّة', 'platform_settings'],
    ];

    public function up(): void
    {
        // قابلة لإعادة التشغيل: بقيّة الهجرة تستعمل updateOrInsert فتُعاد بأمان،
        // وكان إنشاء الجدول وحده يكسر ذلك. وإعادةُ التشغيل ليست حالةً نظرية —
        // نشرٌ متكرّر أو استعادةُ نسخة يمرّان بها، وسقوطُ الهجرة عندها يترك
        // الأدوار مزروعةً نصفَ زرع.
        Schema::hasTable('admin_user_roles') || Schema::create('admin_user_roles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('role_id');
            // من أسند الدور ومتى — إسناد الصلاحية نفسه فعلٌ يُساءل عنه.
            $t->unsignedBigInteger('granted_by_user_id')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'role_id'], 'aur_unique');
            $t->index('user_id');
        });

        $now = now();

        // ── الصلاحيات ──
        foreach (self::PERMISSIONS as $code => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label_ar' => $label, 'category' => $category,
                 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // ── الأدوار وربط صلاحياتها ──
        foreach (self::ROLES as $code => [$label, $perms]) {
            DB::table('roles')->updateOrInsert(
                ['merchant_user_id' => null, 'code' => $code],
                ['label_ar' => $label, 'is_system' => true,
                 'created_at' => $now, 'updated_at' => $now],
            );

            $roleId = DB::table('roles')
                ->whereNull('merchant_user_id')->where('code', $code)->value('id');

            $codes = $perms === '*' ? array_keys(self::PERMISSIONS) : $perms;
            foreach ($codes as $pCode) {
                $pId = DB::table('permissions')->where('code', $pCode)->value('id');
                if (!$pId) continue;
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $pId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        // ── من يملك اللوحة اليوم يبقى مالكاً لها ──
        //
        // بلا هذه الخطوة تُقفل الهجرةُ اللوحةَ على أصحابها لحظةَ نشرها: يصير
        // كل مسار يطلب صلاحية ولا أحد يملك أيّاً منها. فيُسند دور مدير
        // المنصّة لكل حساب إدارة قائم، ثم تُضبط الأدوار الحقيقية من اللوحة.
        $adminRoleId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', 'platform_admin')->value('id');

        $adminIds = DB::table('users')->where('type', 0)->pluck('id');
        foreach ($adminIds as $uid) {
            DB::table('admin_user_roles')->updateOrInsert(
                ['user_id' => $uid, 'role_id' => $adminRoleId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_roles');

        $roleIds = DB::table('roles')->whereNull('merchant_user_id')
            ->whereIn('code', array_keys(self::ROLES))->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
    }
};
