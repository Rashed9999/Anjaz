<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-EMAIL-IDENTITY-001
 *
 * Email is a recovery credential, so its identity must be globally unique just
 * like the canonical phone number. Historical duplicates are not deleted or
 * silently reassigned: they are quarantined (canonical stays NULL), their email
 * verification flag is revoked, and a non-PII conflict record is kept for
 * support review. New accounts and verified changes are protected by the DB
 * unique constraint atomically.
 */
return new class extends Migration
{
    private const INDEX = 'users_email_canonical_unique';

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'email_canonical')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('email_canonical', 255)->nullable()->after('email');
            });
        }

        if (! Schema::hasTable('email_identity_conflicts')) {
            Schema::create('email_identity_conflicts', function (Blueprint $table): void {
                $table->id();
                $table->char('email_hash', 64)->unique();
                $table->string('email_masked', 255)->nullable();
                $table->json('user_ids');
                $table->timestamp('detected_at');
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolution_note', 500)->nullable();
                $table->timestamps();
            });
        }

        $groups = [];
        foreach (
            DB::table('users')
                ->select('id', 'email')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->orderBy('id')
                ->cursor() as $row
        ) {
            $canonical = mb_strtolower(trim((string) $row->email));
            if ($canonical !== '') {
                $groups[$canonical][] = (int) $row->id;
            }
        }

        $conflicts = 0;
        foreach ($groups as $canonical => $ids) {
            if (count($ids) === 1) {
                DB::table('users')->where('id', $ids[0])->update([
                    'email_canonical' => $canonical,
                ]);
                continue;
            }

            ++$conflicts;

            // A shared mailbox cannot be trusted as a recovery credential for
            // more than one account. Keep the original email for investigation,
            // but disable recovery until support resolves ownership.
            $updates = ['email_canonical' => null];
            if (Schema::hasColumn('users', 'is_email_verified')) {
                $updates['is_email_verified'] = 0;
            }
            DB::table('users')->whereIn('id', $ids)->update($updates);

            DB::table('email_identity_conflicts')->updateOrInsert(
                ['email_hash' => hash('sha256', $canonical)],
                [
                    'email_masked' => $this->maskEmail($canonical),
                    'user_ids' => json_encode(array_values($ids), JSON_UNESCAPED_SLASHES),
                    'detected_at' => now(),
                    'resolved_at' => null,
                    'resolution_note' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        // The migration is new, so the index cannot exist on a clean run. If a
        // previous partial deployment already created it, tolerate that state.
        try {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email_canonical', self::INDEX);
            });
        } catch (\Throwable $e) {
            if (! str_contains(mb_strtolower($e->getMessage()), 'already exists')
                && ! str_contains(mb_strtolower($e->getMessage()), 'duplicate key name')) {
                throw $e;
            }
        }

        if ($conflicts > 0) {
            Log::warning('AMIAL-EMAIL-IDENTITY-001: duplicate legacy email identities were quarantined.', [
                'conflict_groups' => $conflicts,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'email_canonical')) {
            try {
                Schema::table('users', function (Blueprint $table): void {
                    $table->dropUnique(self::INDEX);
                });
            } catch (\Throwable) {
                // A partial migration may not have reached index creation.
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('email_canonical');
            });
        }

        Schema::dropIfExists('email_identity_conflicts');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        $prefix = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $prefix . '***@' . $domain;
    }
};
