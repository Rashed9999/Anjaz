<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'platform.registrations.view' => ['عرض ملفات التسجيل المؤرشفة', 'platform_pii'],
        'platform.registrations.create' => ['إنشاء ملف تسجيل بمساعدة موظف', 'platform_account'],
    ];
    public function up(): void {
        $now = now();
        foreach (self::PERMISSIONS as $code => [$label, $category]) DB::table('permissions')->updateOrInsert(['code'=>$code], ['label_ar'=>$label,'category'=>$category,'created_at'=>$now,'updated_at'=>$now]);
        foreach (['platform_admin'=>array_keys(self::PERMISSIONS),'platform_compliance'=>array_keys(self::PERMISSIONS)] as $role=>$codes) {
            $roleId=DB::table('roles')->whereNull('merchant_user_id')->where('code',$role)->value('id'); if(!$roleId) continue;
            foreach($codes as $code) { $pid=DB::table('permissions')->where('code',$code)->value('id'); if($pid) DB::table('role_permissions')->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$pid],['created_at'=>$now,'updated_at'=>$now]); }
        }
    }
    public function down(): void { foreach(array_keys(self::PERMISSIONS) as $code) DB::table('permissions')->where('code',$code)->delete(); }
};
