<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use App\Services\ZoneAssignmentService;
use App\Services\ZonePolicyService;
use Illuminate\Console\Command;

/**
 * AMIAL-ZONE-001 hotfix (v0.7-A.1)
 *
 * Artisan command لإدارة zone_code للمستخدمين.
 *
 * أمثلة:
 *   php artisan amial:zone:list                          # كم مستخدم في كل zone
 *   php artisan amial:zone:set 42 NORTH                  # تعيين zone لمستخدم
 *   php artisan amial:zone:set --phone=+967777111 SOUTH  # بالهاتف
 *   php artisan amial:zone:bulk-set SOUTH --where=type=2 # كل العملاء على SOUTH
 *
 * هذا الـ command الوحيد المُتاح لتعيين zone حتى v0.8 حين يصبح
 * هذا جزء من admin panel عبر UI.
 */
class ManageZoneCommand extends Command
{
    protected $signature = 'amial:zone
                            {action : list|set|bulk-set}
                            {user_id? : User ID للتعيين الفردي}
                            {zone? : SOUTH|NORTH|MIDDLE|OTHER|UNKNOWN}
                            {--phone= : بدلاً من user_id، استخدم رقم الهاتف}
                            {--where= : للـ bulk-set: أحد المرشحات الآمنة type= أو zone_code= أو is_active=}
                            {--dry-run : عرض ما سيتم بدون تنفيذ}
                            {--admin-id= : ID مدير المنصة المنفذ (إلزامي عند التغيير)}
                            {--reason= : سبب تشغيلي قابل للمراجعة، 10 أحرف على الأقل}';

    protected $description = 'إدارة zone_code للمستخدمين (Amial Zone Policy)';

    public function handle(AuditService $audit, ZoneAssignmentService $zones): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->actionList(),
            'set' => $this->actionSet($audit, $zones),
            'bulk-set' => $this->actionBulkSet($audit, $zones),
            default => $this->error("Unknown action: {$action}") ?? 1,
        };
    }

    private function actionList(): int
    {
        $this->info('=== Amial Zone Distribution ===');
        $rows = User::selectRaw('zone_code, type, COUNT(*) as count')
            ->groupBy('zone_code', 'type')
            ->orderBy('zone_code')
            ->orderBy('type')
            ->get();

        $this->table(
            ['Zone', 'User Type', 'Count'],
            $rows->map(fn($r) => [
                $r->zone_code ?? '(NULL)',
                $this->typeLabel($r->type),
                $r->count,
            ])->toArray(),
        );

        $total = User::count();
        $this->newLine();
        $this->info("Total: {$total} users");
        $this->info('استعمل لوحة سياسة المناطق لمعرفة السطح المالي المسموح؛ العدّ وحده لا يثبت صلاحية المعاملة.');

        return self::SUCCESS;
    }

    private function actionSet(AuditService $audit, ZoneAssignmentService $zones): int
    {
        $zone = strtoupper((string)$this->argument('zone'));
        if (!in_array($zone, ZonePolicyService::VALID_ZONES, true)) {
            $this->error("Invalid zone: {$zone}. Valid: " . implode(', ', ZonePolicyService::VALID_ZONES));
            return self::FAILURE;
        }

        $user = null;
        if ($id = $this->argument('user_id')) {
            $user = User::find((int)$id);
        } elseif ($phone = $this->option('phone')) {
            $user = User::where('phone', $phone)->first();
        }

        if (!$user) {
            $this->error('User not found');
            return self::FAILURE;
        }

        [$adminId, $reason] = $this->mutationContext();
        if ($adminId === null || $reason === null) {
            return self::FAILURE;
        }

        $oldZone = $user->zone_code ?? 'UNKNOWN';
        if ($oldZone === $zone) {
            $this->info("User #{$user->id} already in zone {$zone} — no change");
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: would change user #{$user->id} ({$user->phone}) from {$oldZone} → {$zone}");
            return self::SUCCESS;
        }

        // لا تكتب zone_code مباشرةً من CLI: الخدمة الواحدة تحفظ تاريخ القرار
        // وإشاراته، فتكون الواجهة والـ Artisan متكافئين في الأثر.
        $zones->assignByAdmin($user, $zone, $adminId, $reason);

        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $adminId,
            'subject_type' => 'user',
            'subject_id' => (string)$user->id,
            'action' => 'ZONE_CHANGED',
            'decision_code' => 'ZONE_SET',
            'reason' => $reason,
            'zone_code' => $zone,
            'severity' => 'notice',
            'context' => ['old_zone' => $oldZone, 'new_zone' => $zone],
        ]);

        $this->info("✓ User #{$user->id} zone: {$oldZone} → {$zone}");
        return self::SUCCESS;
    }

    private function actionBulkSet(AuditService $audit, ZoneAssignmentService $zones): int
    {
        $zone = strtoupper((string)$this->argument('user_id') ?: (string)$this->argument('zone'));
        // ملاحظة: في bulk-set، الـ argument الأول (user_id) يحمل الـ zone
        // مثال: php artisan amial:zone bulk-set SOUTH --where=type=2

        if (!in_array($zone, ZonePolicyService::VALID_ZONES, true)) {
            $this->error("Invalid zone: {$zone}");
            return self::FAILURE;
        }

        [$adminId, $reason] = $this->mutationContext();
        if ($adminId === null || $reason === null) {
            return self::FAILURE;
        }

        $filter = $this->safeBulkFilter();
        if ($filter === null) {
            return self::FAILURE;
        }

        [$column, $value, $label] = $filter;
        $users = User::where($column, $value);
        $count = $users->count();
        $this->warn("This will change zone of {$count} users to {$zone}");

        if (!$this->option('dry-run') && !$this->confirm('Are you sure?', false)) {
            $this->info('Aborted');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: would update {$count} users to zone {$zone}");
            return self::SUCCESS;
        }

        $affected = 0;
        // كل مستخدم يمرّ من الخدمة. update جماعيّ سريع هنا كان يتجاوز سجلّ
        // zone_assignments ويجعل مصدر القرار مختلفاً عن الواجهة.
        $users->orderBy('id')->chunkById(100, function ($chunk) use ($zones, $zone, $adminId, $reason, &$affected) {
            foreach ($chunk as $user) {
                if (($user->zone_code ?? ZoneAssignmentService::ZONE_UNKNOWN) === $zone) {
                    continue;
                }

                $zones->assignByAdmin($user, $zone, $adminId, $reason);
                $affected++;
            }
        });

        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $adminId,
            'subject_type' => 'system',
            'action' => 'ZONE_BULK_CHANGED',
            'decision_code' => 'ZONE_BULK_SET',
            'reason' => $reason,
            'zone_code' => $zone,
            'severity' => 'warning',
            'context' => ['filter' => $label, 'affected' => $affected, 'requested_count' => $count],
        ]);

        $this->info("✓ Updated {$affected} users to zone {$zone}");
        return self::SUCCESS;
    }

    /** @return array{0:int,1:string}|array{null,null} */
    private function mutationContext(): array
    {
        $adminId = filter_var($this->option('admin-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) $this->option('reason'));

        $actor = $adminId === false ? null : User::whereKey($adminId)
            ->where('type', ADMIN_TYPE)->first();
        if (! $actor || ! $actor->hasPlatformPermission('platform.approvals.decide')) {
            $this->error('--admin-id يجب أن يشير إلى موظف منصّة يملك صلاحية اعتماد قرارات المناطق');
            return [null, null];
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason مطلوب بين 10 و500 حرفاً؛ لا تُنفّذ قرارات المناطق بلا تعليل قابل للمراجعة');
            return [null, null];
        }

        return [(int) $adminId, $reason];
    }

    /** @return array{0:string,1:int|string,2:string}|null */
    private function safeBulkFilter(): ?array
    {
        $where = trim((string) $this->option('where'));
        if (!preg_match('/^(type|zone_code|is_active)\\s*=\\s*([A-Za-z0-9_]+)$/', $where, $matches)) {
            $this->error('--where مطلوب بصيغة آمنة فقط: type=2 أو zone_code=UNKNOWN أو is_active=0');
            return null;
        }

        [, $column, $value] = $matches;
        if ($column === 'type' && !in_array((int) $value, [0, 1, 2, 3], true)) {
            $this->error('type يجب أن يكون 0 أو 1 أو 2 أو 3');
            return null;
        }
        if ($column === 'is_active' && !in_array($value, ['0', '1'], true)) {
            $this->error('is_active يجب أن يكون 0 أو 1');
            return null;
        }
        if ($column === 'zone_code' && !in_array(strtoupper($value), ZonePolicyService::VALID_ZONES, true)) {
            $this->error('zone_code غير صالح');
            return null;
        }

        $normalized = $column === 'zone_code' ? strtoupper($value) : (int) $value;
        return [$column, $normalized, "{$column}={$normalized}"];
    }

    private function typeLabel(int $type): string
    {
        return match ($type) {
            0 => 'Admin',
            1 => 'Agent',
            2 => 'Customer',
            3 => 'Merchant',
            default => "Type {$type}",
        };
    }
}
