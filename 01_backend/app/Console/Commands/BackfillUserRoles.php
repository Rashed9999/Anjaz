<?php

namespace App\Console\Commands;

use App\Support\Access\AccessConstants as A;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-ROLE-SYNC-001 — تصحيح العمود `role` للحسابات الموجودة مسبقاً.
 *
 * الحسابات التي أُنشئت قبل خطّاف المزامنة تحمل role='user' الافتراضي رغم أن
 * نوعها تاجر/وكيل/أدمن — فلا يوجّهها التطبيق للوحة قطاعها. هذا الأمر يصلّح
 * الصفوف الموجودة دون مسّ أي بيانات أخرى (idempotent، آمن للتكرار عند كل إقلاع).
 *
 * لا يدهس الأدوار الصريحة (super_admin/pos/distributor): يعمل فقط على من
 * role الخاص بهم NULL أو '' أو 'user'.
 */
class BackfillUserRoles extends Command
{
    protected $signature = 'amial:backfill-roles';
    protected $description = 'مزامنة users.role مع users.type للحسابات القديمة (تاجر/وكيل/أدمن)';

    public function handle(): int
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'role')) {
            $this->warn('عمود role غير موجود — تخطّي.');
            return self::SUCCESS;
        }

        $map = [
            MERCHANT_TYPE => A::ROLE_MERCHANT,
            AGENT_TYPE    => A::ROLE_AGENT,
            ADMIN_TYPE    => A::ROLE_ADMIN,
        ];

        $total = 0;
        foreach ($map as $type => $role) {
            $affected = DB::table('users')
                ->where('type', $type)
                ->where(function ($q) {
                    $q->whereNull('role')->orWhere('role', '')->orWhere('role', A::ROLE_USER);
                })
                ->update(['role' => $role]);
            if ($affected > 0) {
                $this->info("✓ {$affected} حساباً من النوع {$type} → role={$role}");
            }
            $total += $affected;
        }

        $this->info($total > 0
            ? "اكتمل — صُحِّح {$total} حساباً."
            : 'لا حسابات بحاجة تصحيح — كل الأدوار متّسقة.');

        return self::SUCCESS;
    }
}
