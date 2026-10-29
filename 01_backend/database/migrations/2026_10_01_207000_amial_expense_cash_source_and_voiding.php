<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_expenses', function (Blueprint $table) {
            $table->string('expense_ulid', 26)->nullable()->after('id')->unique();
            $table->string('payment_source', 24)->default('external')->after('amount')->index();
            $table->unsignedBigInteger('cashier_shift_id')->nullable()->after('payment_source')->index();
            $table->string('status', 16)->default('active')->after('cashier_shift_id')->index();
            $table->timestamp('voided_at')->nullable()->after('status');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
        });

        DB::table('merchant_expenses')->whereNull('expense_ulid')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('merchant_expenses')->where('id', $row->id)->update([
                        'expense_ulid' => (string) Str::ulid(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('merchant_expenses', function (Blueprint $table) {
            $table->dropUnique(['expense_ulid']);
            $table->dropIndex(['payment_source']);
            $table->dropIndex(['cashier_shift_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'expense_ulid', 'payment_source', 'cashier_shift_id',
                'status', 'voided_at', 'void_reason',
            ]);
        });
    }
};
