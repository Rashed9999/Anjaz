<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DAILY-MOVEMENT-001 — **الشراءُ نصفُ الحركة، وكان نصفَ مبنيّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **من أين جاءت:** طلب صاحبُ المشروع «الحركةَ اليوميّةَ الكاملة» — مصفوفةَ
 * المنافس: مبيعٌ وشراءٌ ومرتجعاهما، نقداً وآجلاً. وقِيس ما يمكن أن تقرأه
 * المصفوفةُ اليومَ فإذا **نصفُها لا مصدرَ له**:
 *
 *   المبيع        ✓ `merchant_sales` · `pharmacy_sales` · `wholesale_invoices`
 *   مرتجع المبيع  ✓ `sale_returns` · `wholesale_returns`
 *   الشراء        ~ `supplier_ledger` — **وكلُّه آجلٌ بلا استثناء**
 *   مرتجع الشراء  ✗ **لا جدولَ ولا خدمةَ ولا نقطةَ نهاية**
 *
 * **ومصفوفةٌ نصفُها أصفارٌ كاذبةٌ أسوأ من غيابها** (القاعدة السابعة):
 * صفرٌ في «مرتجع الشراء» يُقرأ «لم يُرتجَع شيءٌ اليوم»، والحقيقةُ «لا
 * يمكن أن يُرتجَع شيءٌ أصلاً».
 *
 * ─────────────────────────────────────────────────────────────────────
 * **① `purchase_returns` — والسببُ ليس اكتمالَ مصفوفة.**
 *
 * التاجرُ يستلم بضاعةً تالفةً أو منتهيةً أو زائدةً عن أمره، فيردّها. واليومَ
 * لا سبيلَ إلّا «تسويةٌ يدويّة» في دفتر المورد: **المخزونُ لا ينقص** فيبقى
 * التالفُ معروضاً للبيع، **والقيمةُ لا تُنسَب** فيُقرأ نقصُ الدين سداداً.
 *
 * **و`purchase_return` سببُ حركةِ مخزونٍ معرَّفٌ في `StockMovement::REASONS`
 * منذ بُني المخزون — ولا مُصدِرَ له في المشروع كلِّه** (قِيس بالبحث). أي
 * أنّ الرفَّ كان ينتظر هذه العمليّةَ ولم تُبنَ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **② `supplier_ledger.cash_amount` — الشراءُ النقديُّ لم يكن ممكناً.**
 *
 * `poReceive` يُنشئ `po_receive` **فيرفع دينَ المورد دائماً**. فمن اشترى
 * نقداً من مورّدٍ عابرٍ لا يجد إلّا: مورّدٌ فأمرٌ فاعتمادٌ فاستلامٌ فسدادٌ —
 * خمسُ خطوات، **أو لا يسجّلها إطلاقاً**. فيغيب الشراءُ النقديُّ عن الحركة.
 *
 * **ولا يُخترَع جدولٌ ثانٍ للشراء النقديّ**: هو الاستلامُ نفسُه ومعه ما
 * دُفع فوراً. فالعمودُ يقول **كم دُفع نقداً من هذا الاستلام** — والباقي
 * آجلٌ بالطرح، لا بعمودٍ ثانٍ يمكن أن يناقضه (القاعدة السادسة).
 *
 * **و`null` تعني «استلامٌ قديمٌ قبل هذا العمود» لا «دُفع صفر»** (القاعدة
 * السابعة) — والتقريرُ يعدّها آجلاً كما كانت فعلاً يومَ وقعت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();

            // **والأمرُ اختياريّ**: تُردُّ بضاعةٌ استُلمت بلا أمرٍ مسجَّل
            // (شراءٌ نقديٌّ قديم)، ومنعُ ذلك يُقفل الباب على أكثر الحالات.
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();

            // pending → approved | rejected — **والبضاعةُ لا تتحرّك قبل
            // الاعتماد**، كما في `SaleReturnService` بالحرف.
            $table->string('status', 16)->default('pending')->index();

            // credit_note: يُخصَم من دين المورد · cash_refund: استُرِدّ نقداً
            $table->string('settlement_type', 24)->default('credit_note');

            $table->decimal('total_amount', 20, 4)->default(0);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('zone_code', 16)->nullable();
            $table->timestamps();

            $table->index(['merchant_user_id', 'status']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();

            // **وبندُ الأمر يُقفَل به** — فلا يُردّ أكثرُ ممّا استُلم.
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();

            $table->string('name', 200);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->timestamps();
        });

        // **وما رُدّ يُقفَل على بنده** — كـ`returned_quantity` في أسطر البيع.
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 12, 3)->default(0)
                ->after('received_quantity');
        });

        Schema::table('supplier_ledger', function (Blueprint $table) {
            $table->decimal('cash_amount', 20, 4)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_ledger', function (Blueprint $table) {
            $table->dropColumn('cash_amount');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
