<?php

/**
 * USE-001 — جهات التسوية الخارجية (بنوك + صرافات).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('type', 24)->default('exchange');
            $table->string('account_number', 64)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('swift', 11)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_name', 80)->nullable();
            $table->string('currency', 3)->default('YER');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_partners');
    }
};
