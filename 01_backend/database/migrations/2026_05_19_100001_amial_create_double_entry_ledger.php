<?php

/**
 * AMIAL-LEDGER-001 (v1.7)
 *
 * Immutable Double-Entry Ledger — دفتر محاسبي حقيقي.
 *
 * **المبدأ:**
 *   كل معاملة مالية تُسجَّل كـ "journal entry" يحتوي قيدين متوازنين على الأقل:
 *     - debit (مدين): المال يخرج من حساب
 *     - credit (دائن): المال يدخل إلى حساب
 *   مجموع debits = مجموع credits دائماً (التوازن المحاسبي).
 *
 * **القاعدة الذهبية (وفقاً للوثيقة):**
 *   - لا حذف للقيود
 *   - لا تعديل للقيود
 *   - التصحيح يتم بقيد عكسي (reversing entry)
 *
 * **الجداول:**
 *   - ledger_accounts: الحسابات (user wallet, platform fee, escrow, ...)
 *   - ledger_journal_entries: المعاملة الكاملة (الرأس)
 *   - ledger_entry_lines: السطور (debit/credit) — append-only
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== الحسابات المحاسبية ======
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 50)->unique(); // USER_WALLET_{id}, PLATFORM_FEE, ESCROW_HOLD
            $table->string('account_type', 30); // asset, liability, equity, revenue, expense
            $table->string('name_ar', 200);

            // الربط بالكيان (nullable لحسابات النظام مثل PLATFORM_FEE)
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_type', 30)->nullable(); // user, merchant, agent, platform

            // الرصيد الحالي (cached من القيود - للأداء)
            $table->decimal('current_balance', 24, 4)->default(0);

            // طبيعة الحساب: debit_normal (assets/expenses) أو credit_normal (liabilities/revenue)
            $table->enum('normal_balance', ['debit', 'credit'])->default('credit');

            $table->string('currency', 8)->default('YER');
            $table->string('zone_code', 16)->default('SOUTH');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['owner_user_id', 'owner_type']);
            $table->index('account_type');
        });

        // ====== Journal Entries (رأس المعاملة) ======
        Schema::create('ledger_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_ulid', 26)->unique();

            // ربط بالمعاملة المصدر
            $table->string('source_type', 50); // send_money, donation, safe_payment, refund, ...
            $table->string('source_id', 64)->nullable(); // transaction_id / ulid
            $table->string('idempotency_key', 80)->nullable()->unique();

            $table->text('description_ar');
            $table->decimal('total_amount', 24, 4); // المبلغ الإجمالي (للمرجع)

            // هل هذا قيد عكسي؟
            $table->boolean('is_reversal')->default(false);
            $table->unsignedBigInteger('reverses_entry_id')->nullable();

            // الحالة
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->unsignedBigInteger('reversed_by_entry_id')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->json('metadata')->nullable();

            // posted_at لا يتغير أبداً
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'posted_at']);
            $table->index('is_reversal');

            $table->foreign('reverses_entry_id')->references('id')->on('ledger_journal_entries')->onDelete('restrict');
        });

        // ====== Entry Lines (السطور - debit/credit) — APPEND ONLY ======
        Schema::create('ledger_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');

            $table->enum('direction', ['debit', 'credit']);
            $table->decimal('amount', 24, 4); // دائماً موجب

            // الرصيد بعد هذا القيد (snapshot - وفقاً للوثيقة)
            $table->decimal('balance_before', 24, 4);
            $table->decimal('balance_after', 24, 4);

            $table->string('description_ar', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
            $table->index('journal_entry_id');

            $table->foreign('journal_entry_id')->references('id')->on('ledger_journal_entries')->onDelete('restrict');
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entry_lines');
        Schema::dropIfExists('ledger_journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
