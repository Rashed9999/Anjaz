<?php

/**
 * AMIAL-AGENT-NETWORK-001 (v2.3)
 *
 * شبكة الوكلاء (الصرّافون) — تحويل الوكيل الفردي إلى شبكة منظّمة.
 *
 * **النموذج (M-Pesa-style):**
 *   - الصرّاف = وكيل يبيع/يشتري رصيد رقمي مقابل كاش
 *   - cash-in: يبيع رصيد للعميل (رصيده ينقص، كاشه يزيد)
 *   - cash-out: يشتري رصيد من العميل (رصيده يزيد، كاشه ينقص)
 *   - عند نفاد الرصيد: يشتري من الموزّع/الإدارة (settlement)
 *
 * **البنية الهرمية المرنة:**
 *   - موزّع (distributor) تحته عدة صرّافين، أو
 *   - صرّاف مستقل (= موزّع بلا فروع، parent_id = null)
 *
 * **الحماية:**
 *   - حدود يومية/شهرية لكل وكيل (سقف cash-in)
 *   - تتبع السيولة (float balance)
 *   - تسوية موثّقة مع الإدارة
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== ملف الوكيل الموسّع ======
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique(); // ربط بـ users (type=AGENT)

            // الهرمية: parent_id = الموزّع الأعلى (null = موزّع/مستقل)
            $table->unsignedBigInteger('parent_agent_id')->nullable();
            $table->enum('agent_level', ['distributor', 'sub_agent', 'independent'])
                ->default('independent');

            // معلومات الصرافة
            $table->string('business_name', 200)->nullable();
            $table->string('license_number', 100)->nullable(); // رخصة الصرافة
            $table->string('location_city', 100)->nullable();
            $table->string('location_address', 300)->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();

            // حدود السيولة والعمليات
            $table->decimal('daily_cash_in_limit', 20, 4)->default('500000');
            $table->decimal('daily_cash_out_limit', 20, 4)->default('500000');
            $table->decimal('single_transaction_limit', 20, 4)->default('100000');
            $table->decimal('min_float_balance', 20, 4)->default('0'); // حد أدنى تنبيه

            // العمولة
            $table->decimal('commission_rate', 5, 2)->default('0.50'); // %

            $table->enum('status', ['active', 'suspended', 'pending_approval'])
                ->default('pending_approval');
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['parent_agent_id', 'status']);
            $table->index(['agent_level', 'status']);
            $table->index('location_city');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // ====== سجل السيولة اليومي (float tracking) ======
        Schema::create('agent_float_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_user_id');
            $table->date('log_date')->index();

            // حركة اليوم
            $table->decimal('opening_float', 20, 4)->default('0'); // رصيد البداية
            $table->decimal('cash_in_total', 20, 4)->default('0'); // باع رصيد
            $table->decimal('cash_out_total', 20, 4)->default('0'); // اشترى رصيد
            $table->decimal('topup_total', 20, 4)->default('0'); // شراء من الإدارة
            $table->decimal('commission_earned', 20, 4)->default('0');
            $table->decimal('closing_float', 20, 4)->default('0'); // رصيد النهاية
            $table->integer('transaction_count')->default(0);

            $table->timestamps();

            $table->unique(['agent_user_id', 'log_date'], 'unique_agent_date');
        });

        // ====== تسويات الوكيل (settlement) ======
        Schema::create('agent_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_ulid', 26)->unique();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('settled_with_id'); // الموزّع أو الإدارة

            $table->enum('settlement_type', ['topup', 'payout', 'reconciliation'])
                ->default('topup');
            // topup: الوكيل يشتري رصيد (يدفع كاش، يأخذ رصيد رقمي)
            // payout: الوكيل يبيع رصيد فائض (يأخذ كاش، يعطي رصيد)
            // reconciliation: تسوية حسابية

            $table->decimal('amount', 20, 4);
            $table->decimal('commission_amount', 20, 4)->default('0');

            $table->enum('status', ['pending', 'completed', 'rejected'])
                ->default('pending')->index();

            $table->string('payment_method', 50)->nullable(); // bank, cash, internal
            $table->string('payment_reference', 100)->nullable();
            $table->string('ledger_entry_ulid', 26)->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['agent_user_id', 'status', 'created_at']);

            $table->foreign('agent_user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_settlements');
        Schema::dropIfExists('agent_float_logs');
        Schema::dropIfExists('agent_profiles');
    }
};
