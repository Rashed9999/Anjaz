<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — حسابات الشركات (B2B) عابرة للقطاعات.
 *
 * شركة تفتح حساباً لدى التاجر بحدّ ائتمان؛ أعضاؤها (موظفون/مركبات/بطاقات)
 * يشترون على الحساب ضمن الحدّ، ويُسوّى دورياً. للباقة المؤسسية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('corporate_accounts')) {
            Schema::create('corporate_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_user_id')->index();
                $table->string('account_code', 24)->unique();  // CORP-xxxxx
                $table->string('company_name', 160);
                $table->string('contact_person', 120)->nullable();
                $table->string('contact_phone', 20)->nullable();
                $table->string('tax_number', 40)->nullable();
                $table->decimal('credit_limit', 18, 4)->default(0);
                $table->decimal('monthly_limit', 18, 4)->nullable();
                $table->decimal('current_balance', 18, 4)->default(0); // المستحقّ على الشركة
                $table->string('status', 16)->default('active');       // active | suspended
                $table->timestamp('last_settlement_at')->nullable();
                $table->string('zone_code', 16)->default('SOUTH');
                $table->timestamps();
                $table->index(['merchant_user_id', 'status'], 'corp_acct_merchant_status_idx');
            });
        }

        if (!Schema::hasTable('corporate_account_members')) {
            Schema::create('corporate_account_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('corporate_account_id')->index();
                $table->string('member_name', 120);
                $table->string('identifier', 40)->nullable();  // بطاقة/رمز/لوحة مركبة
                $table->decimal('per_txn_limit', 18, 4)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->unique(['corporate_account_id', 'identifier'], 'corp_member_ident_unique');
            });
        }

        if (!Schema::hasTable('corporate_account_movements')) {
            Schema::create('corporate_account_movements', function (Blueprint $table) {
                $table->id();
                $table->ulid('movement_ulid')->unique();
                $table->unsignedBigInteger('corporate_account_id')->index();
                $table->unsignedBigInteger('member_id')->nullable();
                $table->string('type', 16);                    // charge | payment | adjustment
                $table->decimal('amount', 18, 4);              // موقّع: + دَين، - سداد
                $table->decimal('balance_after', 18, 4);
                $table->date('due_date')->nullable();
                $table->string('reference_type', 40)->nullable();
                $table->string('reference_id', 64)->nullable();
                $table->string('reference_number', 64)->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->string('zone_code', 16)->default('SOUTH');
                $table->timestamps();
                $table->index(['corporate_account_id', 'created_at'], 'corp_mov_acct_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_account_movements');
        Schema::dropIfExists('corporate_account_members');
        Schema::dropIfExists('corporate_accounts');
    }
};
