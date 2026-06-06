<?php

namespace App\Console\Commands;

use App\Services\EncryptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * MigratePiiCommand — يهاجر PII الموجود (plaintext) إلى encrypted columns.
 *
 * **التشغيل:**
 *   php artisan amial:migrate-pii --pretend            # dry run (للفحص أولاً)
 *   php artisan amial:migrate-pii                       # تنفيذ
 *   php artisan amial:migrate-pii --table=users         # جدول واحد
 *   php artisan amial:migrate-pii --batch-size=500      # حجم batch
 *
 * **المنطق:**
 *   - يقرأ rows لم تُهاجر بعد (pii_migrated_at IS NULL)
 *   - يشفر الحقول حسب الـ config
 *   - يحفظ في encrypted + blind_index + masked columns
 *   - يضع pii_migrated_at = now()
 *
 * **مهم:**
 *   - الـ plaintext columns لا تُحذف في v1.3 — للـ rollback
 *   - شغل --pretend أولاً
 *   - backup كامل قبل التنفيذ
 *   - بعد التأكد من نجاح كل شيء عبر بضعة أيام، شغل cleanup migration في v1.4
 */
class MigratePiiCommand extends Command
{
    protected $signature = 'amial:migrate-pii
        {--pretend : Run without saving changes}
        {--table=users : Table to migrate (users, account_recovery_requests)}
        {--batch-size=200 : Number of rows per batch}';

    protected $description = 'Migrate plaintext PII to encrypted columns (AMIAL-PII-ENCRYPTION-001)';

    public function handle(EncryptionService $encryption): int
    {
        $isPretend = $this->option('pretend');
        $table = $this->option('table');
        $batchSize = (int)$this->option('batch-size');

        if (!in_array($table, ['users', 'account_recovery_requests'])) {
            $this->error("Unsupported table: {$table}");
            return self::FAILURE;
        }

        $this->info("════════════════════════════════════════════════════");
        $this->info("AMIAL PII Migration v1.3");
        $this->info("════════════════════════════════════════════════════");
        $this->line("Table:      {$table}");
        $this->line("Mode:       " . ($isPretend ? 'PRETEND (dry run)' : 'EXECUTE'));
        $this->line("Batch size: {$batchSize}");
        $this->newLine();

        if (!$isPretend && !$this->confirm('This will modify data. Have you taken a backup?')) {
            $this->warn('Aborted by user.');
            return self::FAILURE;
        }

        $totalCount = match ($table) {
            'users' => DB::table('users')
                ->whereNull('pii_migrated_at')
                ->where(function ($q) {
                    $q->whereNotNull('phone')
                      ->orWhereNotNull('email')
                      ->orWhereNotNull('f_name');
                })
                ->count(),
            'account_recovery_requests' => DB::table('account_recovery_requests')
                ->whereNull('old_phone_blind_index')
                ->whereNotNull('old_phone')
                ->count(),
        };

        $this->info("Found {$totalCount} rows to migrate.");
        if ($totalCount === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }
        $this->newLine();

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $migrated = 0;
        $errors = 0;

        $processor = match ($table) {
            'users' => fn($row) => $this->migrateUser($row, $encryption, $isPretend),
            'account_recovery_requests' => fn($row) => $this->migrateRecovery($row, $encryption, $isPretend),
        };

        DB::table($table)
            ->when($table === 'users', fn($q) => $q->whereNull('pii_migrated_at'))
            ->when($table === 'account_recovery_requests', fn($q) => $q->whereNull('old_phone_blind_index'))
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use (&$migrated, &$errors, $bar, $processor) {
                foreach ($rows as $row) {
                    try {
                        $processor($row);
                        $migrated++;
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->newLine();
                        $this->error("Row #{$row->id}: " . $e->getMessage());
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("════════════════════════════════════════════════════");
        $this->info("Migration complete:");
        $this->line("  Migrated: {$migrated}");
        if ($errors > 0) {
            $this->warn("  Errors:   {$errors}");
        }
        if ($isPretend) {
            $this->warn("  PRETEND mode — no changes were saved.");
        }
        $this->info("════════════════════════════════════════════════════");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migrateUser($row, EncryptionService $encryption, bool $isPretend): void
    {
        $update = ['pii_migrated_at' => now()];

        if (!empty($row->phone)) {
            $update['phone_encrypted'] = $encryption->encrypt($row->phone);
            $update['phone_blind_index'] = $encryption->blindIndex($row->phone, 'phone');
            $update['phone_masked'] = $encryption->maskPhone($row->phone);
        }

        if (!empty($row->email)) {
            $update['email_encrypted'] = $encryption->encrypt($row->email);
            $update['email_blind_index'] = $encryption->blindIndex($row->email, 'email');
            $update['email_masked'] = $encryption->maskEmail($row->email);
        }

        if (!empty($row->f_name)) {
            $update['f_name_encrypted'] = $encryption->encrypt($row->f_name);
        }
        if (!empty($row->l_name)) {
            $update['l_name_encrypted'] = $encryption->encrypt($row->l_name);
        }

        // إذا الـ User schema يحتوي national_id, address, dob — هاجرهم
        foreach (['national_id' => 'national_id', 'address' => 'address', 'date_of_birth' => 'dob'] as $col => $key) {
            if (isset($row->{$col}) && !empty($row->{$col})) {
                $update["{$key}_encrypted"] = $encryption->encrypt($row->{$col});
                if (in_array($key, ['national_id'], true)) {
                    $update["{$key}_blind_index"] = $encryption->blindIndex($row->{$col}, $key);
                    $update["{$key}_masked"] = $encryption->maskNationalId($row->{$col});
                }
            }
        }

        if (!$isPretend) {
            DB::table('users')->where('id', $row->id)->update($update);
        }
    }

    private function migrateRecovery($row, EncryptionService $encryption, bool $isPretend): void
    {
        $update = [];
        if (!empty($row->old_phone)) {
            $update['old_phone_encrypted'] = $encryption->encrypt($row->old_phone);
            $update['old_phone_blind_index'] = $encryption->blindIndex($row->old_phone, 'phone');
        }
        if (!empty($row->new_phone)) {
            $update['new_phone_encrypted'] = $encryption->encrypt($row->new_phone);
            $update['new_phone_blind_index'] = $encryption->blindIndex($row->new_phone, 'phone');
        }

        if (!empty($update) && !$isPretend) {
            DB::table('account_recovery_requests')->where('id', $row->id)->update($update);
        }
    }
}
