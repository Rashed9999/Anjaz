<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FUEL-VERTICAL-001 · المراحل ١–٧.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ١) الخزّانات والمسدسات — العدّادُ ينزل من المضخّة إلى المسدس، والمسدسُ
 *    يُربط بخزّان. فبلا هذا لا تُنسب اللترات إلى مصدرها ولا تُحسب مصالحة.
 * ٢) الموردون والتوريدات — المخزونُ يدخل بمستندٍ ثلاثيّ الحال، ولا يُرفع
 *    إلّا بعد الترحيل.
 * ٣) القياسات والمصالحة — معادلةُ المخزون الرطب ونتيجتُها تحقيقٌ لا اتّهام.
 * ٤+٥) الصلاحيّةُ بالفعل والنطاق والحدّ، والدورُ يملكه التاجر.
 * ٦) نسخُ الأسعار بسريانٍ واعتماد — لا يُكتب فوق سعرٍ قديم.
 * ٧) حركةُ نقد الوردية — مصروفاتٌ وإيداعاتٌ وسحوبات، فلا تظهر عجزاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ١) الخزّانات ────────────────────────────────────────────────
        Schema::create('fuel_tanks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('fuel_stations')->cascadeOnDelete();
            $table->unsignedInteger('tank_number');
            $table->string('name', 120)->nullable();
            $table->foreignId('fuel_product_id')->constrained('fuel_products')->cascadeOnDelete();

            $table->decimal('capacity_liters', 14, 3);
            // **الكميّةُ الدفتريّة** — تُحرَّك بالتوريد والبيع، وتُقارَن
            // بالمقيس. ولا تُعدّ حقيقةً وحدَها: القياسُ هو الحَكَم.
            $table->decimal('book_liters', 14, 3)->default(0);
            $table->decimal('min_alert_liters', 14, 3)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['station_id', 'tank_number']);
            $table->index(['station_id', 'is_active']);
        });

        // ── ١) المسدسات ────────────────────────────────────────────────
        Schema::create('fuel_nozzles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pump_id')->constrained('fuel_pumps')->cascadeOnDelete();
            $table->unsignedInteger('nozzle_number');
            $table->foreignId('fuel_product_id')->constrained('fuel_products')->cascadeOnDelete();
            $table->foreignId('tank_id')->nullable()->constrained('fuel_tanks')->nullOnDelete();

            // **العدّادُ هنا لا على المضخّة.** مضخّةٌ بمسدسَي بنزينٍ وديزل
            // لها عدّادٌ واحد اليوم، فلا يُعرف كم خرج من أيّ نوع.
            $table->decimal('current_meter_reading', 14, 3)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pump_id', 'nozzle_number']);
            $table->index('tank_id');
        });

        // نقلُ الفوّهات القائمة من الجدول الوسيط — بلا فقدِ ربط.
        if (Schema::hasTable('fuel_pump_products')) {
            foreach (DB::table('fuel_pump_products')->orderBy('id')->get() as $row) {
                $pump = DB::table('fuel_pumps')->where('id', $row->pump_id)->first();

                DB::table('fuel_nozzles')->insert([
                    'pump_id' => $row->pump_id,
                    'nozzle_number' => $row->nozzle_number ?: 1,
                    'fuel_product_id' => $row->fuel_product_id,
                    'tank_id' => null,   // يُربط يدويّاً — «غير معروف» ليس صفراً
                    'current_meter_reading' => $pump->current_meter_reading ?? 0,
                    'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            Schema::drop('fuel_pump_products');
        }

        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->unsignedBigInteger('nozzle_id')->nullable()->after('pump_id');
            $table->unsignedBigInteger('tank_id')->nullable()->after('nozzle_id');

            $table->foreign('nozzle_id')->references('id')->on('fuel_nozzles')->nullOnDelete();
            $table->foreign('tank_id')->references('id')->on('fuel_tanks')->nullOnDelete();
            $table->index(['tank_id', 'status'], 'fuel_sales_tank_status_idx');
        });

        // ── ٢) الموردون والتوريدات ──────────────────────────────────────
        Schema::create('fuel_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_user_id', 'is_active']);
        });

        Schema::create('fuel_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_ulid', 40)->unique();
            $table->foreignId('station_id')->constrained('fuel_stations')->cascadeOnDelete();
            $table->foreignId('tank_id')->constrained('fuel_tanks')->cascadeOnDelete();
            $table->foreignId('fuel_product_id')->constrained('fuel_products')->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();

            $table->string('delivery_number', 80)->nullable();
            $table->string('invoice_number', 80)->nullable();

            $table->decimal('quantity_liters', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 16, 4)->default(0);

            // قياسُ الخزّان قبل التفريغ وبعده — به يُتحقَّق من الكميّة.
            $table->decimal('dip_before_liters', 14, 3)->nullable();
            $table->decimal('dip_after_liters', 14, 3)->nullable();

            // **ثلاثةُ أحوال، والمخزونُ لا يرتفع إلّا بالثالث.**
            $table->enum('status', ['received', 'verified', 'posted', 'rejected'])
                ->default('received');

            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->text('note')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['station_id', 'status']);
            $table->index(['tank_id', 'posted_at']);
        });

        // ── ٣) القياسات والمصالحة ───────────────────────────────────────
        Schema::create('fuel_tank_dips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tank_id')->constrained('fuel_tanks')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->enum('dip_type', ['opening', 'closing', 'spot', 'delivery']);
            $table->decimal('dip_liters', 14, 3);
            $table->decimal('temperature_c', 6, 2)->nullable();
            $table->unsignedBigInteger('taken_by_user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['tank_id', 'taken_at']);
            $table->index(['shift_id', 'dip_type']);
        });

        Schema::create('fuel_stock_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('recon_ulid', 40)->unique();
            $table->foreignId('station_id')->constrained('fuel_stations')->cascadeOnDelete();
            $table->foreignId('tank_id')->constrained('fuel_tanks')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();

            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Opening + Deliveries − Sales = Expected ؛ Expected − Actual = Variance
            $table->decimal('opening_liters', 14, 3);
            $table->decimal('delivered_liters', 14, 3)->default(0);
            $table->decimal('sold_liters', 14, 3)->default(0);
            $table->decimal('expected_closing_liters', 14, 3);
            $table->decimal('actual_closing_liters', 14, 3);
            $table->decimal('variance_liters', 14, 3);
            $table->decimal('variance_percent', 8, 4)->default(0);

            // **النتيجةُ تحقيقٌ لا اتّهام.**
            $table->enum('status', ['within_tolerance', 'investigating', 'resolved', 'written_off'])
                ->default('within_tolerance');
            $table->text('investigation_note')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['station_id', 'status']);
            $table->index(['tank_id', 'period_end']);
        });

        // ── ٦) نسخُ الأسعار ─────────────────────────────────────────────
        Schema::create('fuel_price_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_product_id')->constrained('fuel_products')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('fuel_stations')->cascadeOnDelete();

            $table->decimal('price_per_liter', 14, 4);
            $table->timestamp('effective_from');
            // فارغٌ = سارٍ الآن. ويُختم عند سريان التالي.
            $table->timestamp('effective_to')->nullable();

            $table->enum('status', ['pending_approval', 'active', 'superseded', 'rejected'])
                ->default('pending_approval');

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('reason', 255);

            $table->timestamps();

            $table->index(['fuel_product_id', 'status', 'effective_from'], 'fpv_product_status_from_idx');
        });

        // ── ٧) حركةُ نقد الوردية ────────────────────────────────────────
        Schema::create('fuel_shift_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->enum('direction', ['in', 'out']);
            $table->enum('reason', ['expense', 'cash_in', 'cash_drop', 'change_fund', 'refund']);
            $table->decimal('amount', 16, 4);
            $table->string('reference', 80)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'direction']);
        });

        // ── ٤+٥) الأدوار والصلاحيّات التي يملكها التاجر ─────────────────
        Schema::create('merchant_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('code', 64);
            $table->string('name_ar', 120);
            $table->string('description_ar', 255)->nullable();
            // دورٌ بذرةٌ من المنصّة — يُنسخ للتاجر ولا يُحذف.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['merchant_user_id', 'code']);
        });

        Schema::create('merchant_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_role_id')->constrained('merchant_roles')->cascadeOnDelete();
            $table->string('permission_code', 80);

            // **الصلاحيّةُ ثلاثيّةٌ لا مفردة.**
            $table->enum('scope_type', ['merchant', 'station', 'branch', 'shift', 'own'])
                ->default('merchant');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->decimal('max_amount', 16, 4)->nullable();   // فارغ = بلا حدّ
            $table->enum('approval', ['none', 'supervisor', 'manager', 'owner'])
                ->default('none');

            $table->timestamps();

            $table->unique(['merchant_role_id', 'permission_code', 'scope_type', 'scope_id'],
                'mrp_role_perm_scope_unique');
        });

        Schema::create('merchant_user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('merchant_role_id')->constrained('merchant_roles')->cascadeOnDelete();
            $table->unsignedBigInteger('scope_station_id')->nullable();
            $table->unsignedBigInteger('scope_branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'merchant_role_id'], 'mur_user_role_unique');
            $table->index(['merchant_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->dropForeign(['nozzle_id']);
            $table->dropForeign(['tank_id']);
            $table->dropIndex('fuel_sales_tank_status_idx');
            $table->dropColumn(['nozzle_id', 'tank_id']);
        });

        foreach ([
            'merchant_user_roles', 'merchant_role_permissions', 'merchant_roles',
            'fuel_shift_cash_movements', 'fuel_price_versions',
            'fuel_stock_reconciliations', 'fuel_tank_dips',
            'fuel_deliveries', 'fuel_suppliers',
            'fuel_nozzles', 'fuel_tanks',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('fuel_pump_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pump_id');
            $table->unsignedBigInteger('fuel_product_id');
            $table->unsignedInteger('nozzle_number')->default(1);
            $table->timestamps();
        });
    }
};
