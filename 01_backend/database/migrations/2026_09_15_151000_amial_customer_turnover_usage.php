<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-002
 *
 * سجل مستقل لاستخدام حدود KYC. لا نستخدم الرسوم أو عمولات أميال في هذا
 * العداد؛ القيمة المسجلة هي أصل العملية (`transactions.amount`) فقط.
 *
 * الدفتر يبقى مصدر الحقيقة المحاسبي، وtransactions يبقى سجل العملية، وهذا
 * الجدول هو projection مخصص لحدود KYC حتى لا تختلط المحاسبة بسياسة التوثيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_turnover_usage')) {
            Schema::create('customer_turnover_usage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('transaction_row_id')->unique();
                $table->string('transaction_id', 80)->nullable()->index();
                $table->string('transaction_type', 64)->nullable()->index();
                $table->string('direction', 8)->index(); // in | out
                $table->decimal('principal_amount', 24, 4);
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['user_id', 'occurred_at']);
            });
        }

        // Backfill الشهر الجاري فقط: هو المطلوب لحدود اليوم/الشهر لحظة النشر.
        // نأخذ amount لا debit/credit حتى لا تدخل الرسوم ضمن الحد.
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
                        'transaction_row_id' => (int) $row->id,
                        'transaction_id' => $row->transaction_id,
                        'transaction_type' => $row->transaction_type,
                        'direction' => $direction,
                        'principal_amount' => (string) $row->amount,
                        'occurred_at' => $row->created_at ?: $now,
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
