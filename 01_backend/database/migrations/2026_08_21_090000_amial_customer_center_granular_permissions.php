<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'platform.customers.wallets.view' => ['عرض محافظ العميل', 'platform_read'],
        'platform.customers.notifications.view' => ['عرض إشعارات العميل', 'platform_read'],
        'platform.customers.security.view' => ['عرض مصادقة وأجهزة العميل', 'platform_read'],
        'platform.customers.kyc.view' => ['عرض حالة هوية العميل', 'platform_read'],
        'platform.customers.pii.reveal' => ['كشف بيانات اتصال العميل الحساسة', 'platform_pii'],
        'platform.customers.notes.create' => ['إضافة ملاحظة على ملف العميل', 'platform_support'],
        'platform.risk.investigations.create' => ['فتح تحقيق مخاطر للعميل', 'platform_decide'],
        'platform.customers.limits.update' => ['تعديل حدود عميل', 'platform_account'],
        'platform.customers.kyc.request' => ['طلب تحديث هوية العميل', 'platform_account'],
        'platform.customers.unfreeze.request' => ['طلب إلغاء تجميد عميل', 'platform_decide'],
        'platform.customers.lifecycle.manage' => ['إيقاف أو تفعيل دورة حياة العميل', 'platform_account'],
        'platform.customers.close.request' => ['طلب إغلاق حساب عميل', 'platform_decide'],
        'platform.customers.deceased.request' => ['فتح معاملة تعليم عميل متوفّى', 'platform_decide'],
    ];

    public function up(): void {
        $now = now();
        foreach (self::PERMISSIONS as $code => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'label_ar' => $label, 'category' => $category, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $rolePermissions = [
            'platform_admin' => array_keys(self::PERMISSIONS),
            'platform_support' => [
                'platform.customers.notifications.view',
                'platform.customers.notes.create',
                'platform.customers.pii.reveal',
            ],
            'platform_finance' => ['platform.customers.wallets.view'],
            'platform_risk' => [
                'platform.customers.security.view',
                'platform.customers.pii.reveal',
                'platform.risk.investigations.create',
                'platform.customers.lifecycle.manage',
            ],
            'platform_compliance' => ['platform.customers.kyc.view', 'platform.customers.kyc.request'],
            // الإشراف يقرأ الأدلة اللازمة لاعتماد الطلب، ولا تُعطى له كتابة
            // تلقائية على المال أو KYC لمجرد قدرته على الاعتماد.
            'platform_supervisor' => [
                'platform.customers.wallets.view',
                'platform.customers.security.view',
                'platform.customers.kyc.view',
                'platform.customers.notifications.view',
                'platform.customers.pii.reveal',
            ],
        ];
        foreach ($rolePermissions as $roleCode => $codes) {
            $roleId = DB::table('roles')->whereNull('merchant_user_id')->where('code', $roleCode)->value('id');
            if (!$roleId) continue;
            foreach ($codes as $code) {
                $permissionId = DB::table('permissions')->where('code', $code)->value('id');
                if ($permissionId) DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
    public function down(): void { foreach (array_keys(self::PERMISSIONS) as $code) DB::table('permissions')->where('code', $code)->delete(); }
};
