<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_products', function (Blueprint $table) {
            // generic_name وحده لا يكفي لاعتبار دواءين بديلين: قد يختلف
            // التركيز أو الشكل (شراب/أقراص). هذه الحقول يدوّنها الصيدلي.
            $table->string('active_ingredient', 200)->nullable()->after('generic_name');
            $table->string('strength', 80)->nullable()->after('active_ingredient');
            $table->string('dosage_form', 80)->nullable()->after('strength');
            $table->index(['pharmacy_id', 'active_ingredient', 'strength', 'dosage_form'], 'pharmacy_product_alternative_lookup');
        });

        Schema::table('pharmacy_batches', function (Blueprint $table) {
            $table->string('disposition_type', 32)->nullable()->after('recalled_at');
            $table->text('disposition_reason')->nullable()->after('disposition_type');
            $table->unsignedBigInteger('disposed_by_user_id')->nullable()->after('disposition_reason');
            $table->timestamp('disposed_at')->nullable()->after('disposed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_batches', function (Blueprint $table) {
            $table->dropColumn(['disposition_type', 'disposition_reason', 'disposed_by_user_id', 'disposed_at']);
        });
        Schema::table('pharmacy_products', function (Blueprint $table) {
            $table->dropIndex('pharmacy_product_alternative_lookup');
            $table->dropColumn(['active_ingredient', 'strength', 'dosage_form']);
        });
    }
};
