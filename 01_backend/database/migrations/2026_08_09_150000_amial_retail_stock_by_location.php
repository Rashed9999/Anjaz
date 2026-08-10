<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٠ — **المخزون بالموقع وبالحركة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان المخزونُ عموداً واحداً: `merchant_products.quantity`، يُنقص مباشرةً
 * في `CashierService`.
 *
 * **وثلاثةُ أعطالٍ فيه:**
 *
 * ① **لا موقع.** متجرٌ بثلاثة فروعٍ ومستودع له رقمٌ واحد — فبيعٌ في عدن
 *   ينقص مخزونَ المكلا، ويُطلب توريدٌ لفرعٍ ممتلئ ويقف فرعٌ فارغ.
 *
 * ② **لا حركة.** الرقمُ يُكتب فوقه، فلا يُعرف من أين نقص: بيعٌ؟ هالك؟
 *   تحويل؟ خطأُ إدخال؟ **والجردُ يُخرج فرقاً بلا أثرٍ يُراجَع.**
 *
 * ③ **ولا يُمنع السالب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعمودُ القديم لا يُحذف** — يُبقى مرآةً لمجموع المواقع.
 *
 * فالشاشاتُ والتقاريرُ ونقطةُ البيع تقرؤه اليوم في مواضعَ كثيرة، وحذفُه
 * في هجرةٍ واحدة يُعطّل ما يعمل. يُصبح **مشتقّاً لا مصدراً**، ويُحرَس
 * تطابقُه، ويُزال حين لا يبقى قارئ.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── مواقعُ التخزين ─────────────────────────────────────────────
        //
        // **والمستودعُ ليس متجراً.** يستلم ويخزّن ويجهّز تحويلاتٍ ولا
        // يبيع. وجعلُه فرعاً يُدخله في تقارير المبيعات بصفرٍ دائم.
        Schema::create('merchant_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');

            $table->enum('kind', ['store', 'warehouse']);
            $table->string('name', 160);
            $table->string('code', 40);

            // يُربط بفرعٍ قائمٍ إن وُجد — **ولا يُنشأ جدولُ فروعٍ ثانٍ**.
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('city', 80)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_user_id', 'code']);
            $table->index(['merchant_user_id', 'kind', 'is_active']);
            $table->index('branch_id');
        });

        // ── رصيدُ الصنف في الموقع ──────────────────────────────────────
        //
        // **لقطةٌ لا مصدر.** المصدرُ `stock_movements`، وهذه تُقرأ سريعاً
        // ويُحرَس تطابقُها مع مجموع الحركات.
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');

            $table->decimal('on_hand', 16, 3)->default(0);
            // محجوزٌ لطلبٍ لم يُسلَّم بعد — يُنقص المتاح ولا يُنقص الموجود.
            $table->decimal('reserved', 16, 3)->default(0);

            $table->decimal('reorder_level', 16, 3)->default(0);
            $table->decimal('max_level', 16, 3)->default(0);

            $table->timestamp('last_counted_at')->nullable();
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
            $table->index(['location_id', 'on_hand']);

            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
        });

        // ── حركةُ المخزون — **مصدرُ الحقيقة** ──────────────────────────
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');

            // **السببُ إلزاميّ** — «نقص ٣» بلا سبب لا يُراجَع.
            $table->enum('reason', [
                'sale', 'sale_return', 'purchase_receive', 'purchase_return',
                'transfer_out', 'transfer_in',
                'count_adjustment', 'waste', 'opening_balance', 'correction',
            ]);

            // موجبٌ يزيد وسالبٌ ينقص — **إشارةٌ واحدةٌ لا عمودان**.
            // وعمودان (`in`/`out`) يسمحان بصفٍّ فيه الاثنان، وبمجموعٍ
            // يختلف بحسب من يقرأ.
            $table->decimal('quantity_delta', 16, 3);

            $table->decimal('balance_after', 16, 3);

            // **تكلفةُ الوحدة لحظةَ الحركة** — بها تُحسب تكلفةُ المبيعات.
            $table->decimal('unit_cost', 16, 4)->nullable();

            // المستند: بيعةٌ أو أمرُ شراءٍ أو تحويلٌ أو جرد.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['product_id', 'location_id', 'created_at'], 'sm_prod_loc_time_idx');
            $table->index(['merchant_user_id', 'reason']);
            $table->index(['source_type', 'source_id']);

            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
        });

        // ── الهجرةُ التاريخيّة ─────────────────────────────────────────
        //
        // **لكلّ تاجرٍ موقعٌ افتراضيّ، ورصيدُه اليوم يصير حركةَ افتتاح.**
        //
        // ولا يُنقل الرقمُ صامتاً: يُكتب `opening_balance` بتاريخه، فيبقى
        // للجرد الأوّل أساسٌ يُقاس عليه بدل أن يبدأ من فراغ.
        if (! Schema::hasTable('merchant_products')) {
            return;
        }

        $merchants = DB::table('merchant_products')
            ->select('merchant_user_id')->distinct()->pluck('merchant_user_id');

        foreach ($merchants as $mid) {
            $locationId = DB::table('merchant_locations')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'merchant_user_id' => $mid,
                'kind' => 'store',
                'name' => 'الفرع الرئيسي',
                'code' => 'MAIN',
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('merchant_products')
                ->where('merchant_user_id', $mid)
                ->orderBy('id')
                ->chunk(200, function ($products) use ($locationId, $mid) {
                    foreach ($products as $p) {
                        $qty = (string) ($p->quantity ?? '0');

                        DB::table('product_stocks')->insert([
                            'product_id' => $p->id,
                            'location_id' => $locationId,
                            'on_hand' => $qty,
                            'reserved' => 0,
                            'last_movement_at' => now(),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);

                        if (bccomp($qty, '0', 3) === 0) {
                            continue;
                        }

                        DB::table('stock_movements')->insert([
                            'uuid' => (string) \Illuminate\Support\Str::uuid(),
                            'merchant_user_id' => $mid,
                            'product_id' => $p->id,
                            'location_id' => $locationId,
                            'reason' => 'opening_balance',
                            'quantity_delta' => $qty,
                            'balance_after' => $qty,
                            'unit_cost' => $p->cost_price ?? null,
                            'note' => 'رصيد افتتاحيّ عند تفعيل المخزون بالموقع',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('merchant_locations');
    }
};
