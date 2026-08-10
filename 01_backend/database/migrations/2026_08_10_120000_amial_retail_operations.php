<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المراحل ٤ و٥ و٦ — **التحويلات والجرد
 * والهالك والمرتجعات**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ── ٤) التحويلُ بمراحله — **وليس حركتين متقابلتين** ────────────────────
 *
 * أسهلُ تنفيذٍ للتحويل: أنقص من هنا وأزد هناك في لحظة. **وهو خطأ.**
 *
 * فالبضاعةُ تركب سيّارةً وتقضي في الطريق ساعاتٍ أو يوماً. فإن نقصت من
 * المصدر وزادت في الوجهة فوراً، **ظهر في تقرير الوجهة مخزونٌ لم يصل**،
 * فبِيع ما ليس في الرفّ ووُعد زبونٌ ببضاعةٍ في الطريق.
 *
 * وإن نقصت من المصدر ولم تُسجَّل في الطريق، **اختفت من الدنيا**: لا في
 * فرعٍ ولا في آخر — والفرقُ يُقرأ سرقة.
 *
 * فالمراحلُ خمس، **ولكلٍّ فاعلٌ ووقت**:
 *
 * ```
 * طلب ← اعتماد ← إرسال ← (في الطريق) ← استلام
 * ```
 *
 * **والمستلَمُ قد يقلّ عن المرسَل** — كسرٌ أو نقصٌ أو سرقةٌ في الطريق —
 * فيُسجَّل الفرقُ باسمه ولا يُساوى الرقمان بالقوّة.
 *
 * ── ٥) الجرد — **والفرقُ يُقال سببَه** ──────────────────────────────────
 *
 * جردٌ يُخرج «ناقص ٧» ولا يقول لماذا هو رقمٌ يُقفَل ولا يُصلَح. فلكلّ سطرٍ
 * سببٌ اختياريّ من قائمة، **واعتمادٌ قبل أن يمسّ المخزون**: من عدّ ليس
 * من يعتمد، وإلّا كان الجردُ باباً خلفيّاً لتسوية النقص.
 *
 * **و`system_qty` لقطةٌ تُكتب لحظةَ بدء العدّ** — لا تُقرأ عند الاعتماد.
 * فبيعٌ يقع أثناء العدّ يغيّر النظامَ تحت يد العادّ، فيُنسب البيعُ فرقاً.
 *
 * ── ٦) الهالك والمرتجعات ───────────────────────────────────────────────
 *
 * والهالكُ **باعتماد**: من يُتلف بلا مُعتمِدٍ يُخرج بضاعةً بلا أثر.
 * والمرتجعُ **سطراً سطراً** بقرار إعادة تخزين: مرتجعٌ سليمٌ يعود للرفّ،
 * وتالفٌ يذهب هالكاً — **وخلطُهما يُعيد التالفَ للبيع**.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ══════════ ٤) التحويلات ══════════

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('code', 32);

            $table->unsignedBigInteger('from_location_id');
            $table->unsignedBigInteger('to_location_id');

            $table->enum('status', [
                'draft', 'requested', 'approved', 'shipped', 'received',
                'partially_received', 'cancelled',
            ])->default('draft');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('shipped_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('note', 255)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->unique(['merchant_user_id', 'code']);
            $table->index(['merchant_user_id', 'status']);
            $table->index(['from_location_id', 'status']);
            $table->index(['to_location_id', 'status']);

            $table->foreign('from_location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
            $table->foreign('to_location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name', 160);

            $table->decimal('requested_quantity', 16, 3);
            $table->decimal('shipped_quantity', 16, 3)->nullable();
            // **الفراغُ «لم يُستلَم بعد»، والصفرُ «وصل صفر»** (القاعدة ٧).
            $table->decimal('received_quantity', 16, 3)->nullable();
            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->string('variance_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['transfer_id']);
            $table->index(['product_id']);

            $table->foreign('transfer_id')->references('id')->on('stock_transfers')
                ->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
        });

        // ══════════ ٥) الجرد ══════════

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('location_id');
            $table->string('code', 32);

            // كامل: كلُّ الأصناف · دوريّ: تصنيفٌ في دورٍ · موضعيّ: أصنافٌ بعينها
            $table->enum('kind', ['full', 'cycle', 'spot'])->default('full');
            $table->enum('status', ['draft', 'counting', 'review', 'approved', 'cancelled'])
                ->default('draft');

            $table->unsignedBigInteger('scope_category_id')->nullable();

            $table->unsignedBigInteger('started_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->string('note', 255)->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->unique(['merchant_user_id', 'code']);
            $table->index(['merchant_user_id', 'status']);
            $table->index(['location_id', 'status']);

            $table->foreign('location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('count_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name', 160);

            // **لقطةٌ عند بدء العدّ** — ولا تُقرأ من الجدول عند الاعتماد.
            $table->decimal('system_quantity', 16, 3);
            // **الفراغُ «لم يُعدّ»، والصفرُ «عُدّ فلم يوجد»** (القاعدة ٧).
            $table->decimal('counted_quantity', 16, 3)->nullable();
            $table->decimal('variance', 16, 3)->nullable();

            $table->enum('variance_reason', [
                'damaged', 'expired', 'theft', 'entry_error',
                'unrecorded_sale', 'found', 'other',
            ])->nullable();

            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['count_id', 'product_id']);
            $table->index('product_id');

            $table->foreign('count_id')->references('id')->on('stock_counts')
                ->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
        });

        // ══════════ ٦) الهالك ══════════

        Schema::create('stock_wastes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name', 160);

            $table->decimal('quantity', 16, 3);
            $table->enum('reason', [
                'expired', 'damaged', 'theft', 'sample', 'production_loss', 'other',
            ]);

            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->decimal('total_cost', 20, 4)->nullable();

            // **باعتماد** — ومن يُتلف بلا مُعتمِدٍ يُخرج بضاعةً بلا أثر.
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 255)->nullable();

            $table->string('note', 255)->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'status']);
            $table->index(['location_id', 'reason']);
            $table->index('product_id');

            $table->foreign('location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
        });

        // ══════════ ٦) المرتجعات بأسطرها ══════════

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('sale_id');
            $table->string('sale_ulid', 40)->index();
            $table->unsignedBigInteger('location_id')->nullable();

            $table->decimal('total_amount', 20, 4)->default(0);
            $table->enum('refund_method', ['cash', 'wallet', 'credit_account', 'exchange'])
                ->default('cash');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // يُربط بالمرتجع الماليّ القائم إن أُنشئ — **ولا يُبنى مسارُ
            // مالٍ ثانٍ**: `MerchantSaleRefundService` يملك المال.
            $table->string('refund_ulid', 40)->nullable()->index();

            $table->string('reason', 255)->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'status']);

            $table->foreign('sale_id')->references('id')->on('merchant_sales')
                ->cascadeOnDelete();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id');
            // **السطرُ الأصليّ** — وبه يُمنع ارتجاعُ أكثر ممّا بيع.
            $table->unsignedBigInteger('sale_item_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name', 160);

            $table->decimal('quantity', 16, 3);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('line_total', 20, 4);
            $table->decimal('unit_cost', 20, 4)->nullable();

            // **قرارُ الرفّ**: سليمٌ يعود، وتالفٌ يذهب هالكاً.
            $table->enum('condition', ['good', 'damaged', 'expired'])->default('good');
            $table->boolean('restock')->default(true);
            $table->timestamps();

            $table->index('return_id');
            $table->index('sale_item_id');

            $table->foreign('return_id')->references('id')->on('sale_returns')
                ->cascadeOnDelete();
            $table->foreign('sale_item_id')->references('id')->on('merchant_sale_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('stock_wastes');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
