<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
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
                            {--where= : للـ bulk-set: شرط SQL على users}
                            {--dry-run : عرض ما سيتم بدون تنفيذ}
                            {--admin-id=1 : ID admin الذي ينفذ (للـ audit)}';

    protected $description = 'إدارة zone_code للمستخدمين (Amial Zone Policy)';

    public function handle(AuditService $audit): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->actionList(),
            'set' => $this->actionSet($audit),
            'bulk-set' => $this->actionBulkSet($audit),
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
        $south = User::where('zone_code', 'SOUTH')->count();
        $this->newLine();
        $this->info("Total: {$total} users");
        $this->info("SOUTH (can transact): {$south}");
        $this->info("Read-only mode: " . ($total - $south));

        return self::SUCCESS;
    }

    private function actionSet(AuditService $audit): int
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

        $oldZone = $user->zone_code ?? 'UNKNOWN';
        if ($oldZone === $zone) {
            $this->info("User #{$user->id} already in zone {$zone} — no change");
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: would change user #{$user->id} ({$user->phone}) from {$oldZone} → {$zone}");
            return self::SUCCESS;
        }

        $user->zone_code = $zone;
        $user->save();

        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => (int)$this->option('admin-id'),
            'subject_type' => 'user',
            'subject_id' => (string)$user->id,
            'action' => 'ZONE_CHANGED',
            'decision_code' => 'ZONE_SET',
            'reason' => "Zone changed via CLI: {$oldZone} → {$zone}",
            'zone_code' => $zone,
            'severity' => 'notice',
            'context' => ['old_zone' => $oldZone, 'new_zone' => $zone],
        ]);

        $this->info("✓ User #{$user->id} zone: {$oldZone} → {$zone}");
        return self::SUCCESS;
    }

    private function actionBulkSet(AuditService $audit): int
    {
        $zone = strtoupper((string)$this->argument('user_id') ?: (string)$this->argument('zone'));
        // ملاحظة: في bulk-set، الـ argument الأول (user_id) يحمل الـ zone
        // مثال: php artisan amial:zone bulk-set SOUTH --where=type=2

        if (!in_array($zone, ZonePolicyService::VALID_ZONES, true)) {
            $this->error("Invalid zone: {$zone}");
            return self::FAILURE;
        }

        $where = $this->option('where') ?? '1=1';

        // أمان: نسمح فقط بـ where بسيط (column=value) — لا SQL injection
        if (!preg_match('/^[a-z_]+\s*=\s*[\'"]?[a-zA-Z0-9_+\-]+[\'"]?$/i', $where)) {
            $this->error('--where supports only "column=value" syntax for safety');
            return self::FAILURE;
        }

        $count = User::whereRaw($where)->count();
        $this->warn("This will change zone of {$count} users to {$zone}");

        if (!$this->option('dry-run') && !$this->confirm('Are you sure?', false)) {
            $this->info('Aborted');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: would update {$count} users to zone {$zone}");
            return self::SUCCESS;
        }

        $affected = User::whereRaw($where)->update(['zone_code' => $zone]);

        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => (int)$this->option('admin-id'),
            'subject_type' => 'system',
            'action' => 'ZONE_BULK_CHANGED',
            'decision_code' => 'ZONE_BULK_SET',
            'reason' => "Bulk zone update via CLI: where=({$where}) → {$zone}",
            'zone_code' => $zone,
            'severity' => 'warning',
            'context' => ['where' => $where, 'affected' => $affected],
        ]);

        $this->info("✓ Updated {$affected} users to zone {$zone}");
        return self::SUCCESS;
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
