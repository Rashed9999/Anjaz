<?php

/** Grant a narrow collection capability to existing built-in cashier roles.
 * Custom roles are left unchanged; merchants can grant debt.collect explicitly.
 * Do not grant cash.move, which also permits general cash expenses.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_roles') || !Schema::hasTable('merchant_role_permissions')) {
            return;
        }
        $ids=DB::table('merchant_roles')->where('is_system',true)
            ->whereIn('code',['cashier','collector'])->pluck('id');
        foreach ($ids as $id) {
            $exists=DB::table('merchant_role_permissions')
                ->where('merchant_role_id',$id)->where('permission_code','debt.collect')->exists();
            if (!$exists) {
                DB::table('merchant_role_permissions')->insert([
                    'merchant_role_id'=>$id,
                    'permission_code'=>'debt.collect',
                    'scope_type'=>'merchant',
                    'scope_id'=>null,
                    'max_amount'=>null,
                    'approval'=>'none',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchant_role_permissions')) {
            DB::table('merchant_role_permissions')
                ->where('permission_code','debt.collect')->delete();
        }
    }
};
