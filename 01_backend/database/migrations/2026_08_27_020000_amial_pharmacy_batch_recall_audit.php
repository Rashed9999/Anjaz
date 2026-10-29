<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pharmacy_batches', function (Blueprint $table) {
            $table->text('recall_reason')->nullable()->after('status');
            $table->unsignedBigInteger('recalled_by_user_id')->nullable()->after('recall_reason');
            $table->timestamp('recalled_at')->nullable()->after('recalled_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_batches', function (Blueprint $table) {
            $table->dropColumn(['recall_reason', 'recalled_by_user_id', 'recalled_at']);
        });
    }
};
