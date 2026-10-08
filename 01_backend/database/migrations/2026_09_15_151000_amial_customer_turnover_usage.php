<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-003
 *
 * Projection مستقل لحدود KYC. القيمة هي أصل الحركة فقط (principal)، ولا
 * تشمل الرسوم أو العمولات. يدعم posted للحركة النهائية، reserved للحركة
 * المحجوزة، وreleased للحركة الملغاة/المستردة حتى لا يُعاقب العميل مرتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_turnover_usage')) {
            Schema::create('customer_turnover_usage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('source_key', 191)->unique();
                $table->unsignedBigInteger('transaction_row_id')->nullable()->unique();
                $table->string('transaction_id', 80)->nullable()->index();
                $table->string('transaction_type', 64)->nullable()->index();
                $table->string('direction', 8)->index(); // in | out
                $table->decimal('principal_amount', 24, 4);
                $table->string('status', 16)->default('posted')->index(); // reserved|posted|released
                $table->timestamp('occurred_at')->index();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('release_reason', 255)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status', 'occurred_at']);
            });
        }

        // Backfill الشهر الجاري فقط. transactions.amount هو أصل العملية،
        // بينما debit قد يشمل رسوماً في بعض المسارات.
        if (!Schema::hasTable('transactions') || !Schema::hasTable('users')) {
            return;
        }

        $monthStart = Carbon::now()->startOfMonth();

        DB::table('transactions as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('u.type', 2)
            ->where('t.created_at', '>=', $monthStart)
            ->where('t.amount', '>', 0)
            ->where(function ($q) {
                $q->where('t.debit', '>', 0)->orWhere('t.credit', '>', 0);
            })
            ->orderBy('t.id')
            ->select([
                't.id', 't.user_id', 't.transaction_id', 't.transaction_type',
                't.debit', 't.credit', 't.amount', 't.created_at',
            ])
            ->chunk(500, function ($rows): void {
                $now = now();
                $insert = [];
                foreach ($rows as $row) {
                    $debit = (string) ($row->debit ?? '0');
                    $credit = (string) ($row->credit ?? '0');
                    $direction = bccomp($debit, '0', 4) > 0 ? 'out'
                        : (bccomp($credit, '0', 4) > 0 ? 'in' : null);
                    if ($direction === null) {
                        continue;
                    }

                    $insert[] = [
                        'user_id' => (int) $row->user_id,
                        'source_key' => 'transaction:' . (int) $row->id,
                        'transaction_row_id' => (int) $row->id,
                        'transaction_id' => $row->transaction_id,
                        'transaction_type' => $row->transaction_type,
                        'direction' => $direction,
                        'principal_amount' => (string) $row->amount,
                        'status' => 'posted',
                        'occurred_at' => $row->created_at ?: $now,
                        'finalized_at' => $row->created_at ?: $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insert !== []) {
                    DB::table('customer_turnover_usage')->insertOrIgnore($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_turnover_usage');
    }
};
