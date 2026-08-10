<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **أسطرُ المبيعة جدولاً، والتكلفةُ
 * تُلتقَط لحظةَ البيع**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كانت أسطرُ المبيعة نصّاً مُسلسَلاً في `merchant_sales.items`. والأثرُ
 * ليس أداءً بل **أرقاماً لا تُحسب أصلاً**:
 *
 * | ما كان مستحيلاً | لماذا |
 * |---|---|
 * | «كم بعتُ من هذا الصنف هذا الشهر؟» | لا يُستعلَم داخل JSON |
 * | **تكلفةُ المبيعات** | التكلفةُ لحظةَ البيع غيرُ ملتقَطة |
 * | **هامشُ الربح** | إيرادٌ بلا تكلفةٍ رقمٌ لا معنى له |
 * | مرتجعُ صنفٍ واحدٍ من فاتورة | لا سطرَ يُشار إليه |
 * | الراكدُ وسريعُ الدوران | لا تاريخَ بيعٍ لكلّ صنف |
 *
 * **وأخطرُها التكلفة.** `merchant_products.cost_price` عمودٌ واحدٌ يُكتب
 * فوقه: من اشترى بـ٥٠٠ ثمّ بـ٦٠٠ **يفقد تكلفةَ ما باعه أمس**، فيصير
 * «الربح الإجماليّ» رقماً **يتغيّر بأثرٍ رجعيّ** كلّما تغيّر سعرُ الشراء.
 * فتقريرُ الشهر الماضي يُطبع مرّتين بعددين مختلفين، ولا خطأ في أيّ سجلّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **و`unit_cost` يقبل الفراغ عمداً — والفراغُ ليس صفراً** (القاعدة ٧).
 *
 * فبيعٌ بمبلغٍ حرٍّ بلا منتج، أو منتجٌ لم تُدخَل تكلفتُه، تكلفتُه **غيرُ
 * معروفة**. ووضعُ صفرٍ مكانَها يجعل الهامش ١٠٠٪ ويُقرأ ربحاً ممتازاً.
 * ولذلك `cost_source` يقول من أين جاء الرقم، والتقاريرُ تفصل «محسوبٌ»
 * عن «غيرُ معروف» بدل أن تخلطهما في مجموعٍ واحدٍ يكذب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والهجرةُ التاريخيّة لا تدّعي معرفة.** أسطرُ المبيعات القديمة تُنقل
 * كما هي، وتكلفتُها تُقدَّر من `cost_price` الحاليّ **موسومةً
 * `estimated_backfill`** — لأنّها فعلاً تقديرٌ لا التقاط. فمن أراد ربحاً
 * دقيقاً عرف أنّه يبدأ من اليوم، ولم يُعطَ رقماً قديماً يظنّه مقيساً.
 *
 * **وعمودُ JSON لا يُحذف** — يبقى مرآةً كما بقي `merchant_products.quantity`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_sale_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('sale_id');
            // مكرَّرٌ عمداً: المرتجعاتُ تُشير إلى `original_sale_ulid`، فبه
            // يُوصَل السطرُ بالمرتجع بلا وصلةٍ ثالثة.
            $table->string('sale_ulid', 40)->index();

            // **يقبل الفراغ**: بيعٌ بمبلغٍ حرٍّ بلا منتجٍ في الكتالوج.
            $table->unsignedBigInteger('product_id')->nullable();

            // **لقطةُ الاسم** — المنتجُ يُعاد تسميتُه ويُحذف، والفاتورةُ
            // المطبوعةُ لا تتغيّر بعد طباعتها.
            $table->string('name', 160);
            $table->string('barcode', 64)->nullable();

            $table->decimal('quantity', 16, 3);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('line_discount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);

            // **التكلفةُ لحظةَ البيع** — فراغُها «غيرُ معروف» لا صفر.
            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->decimal('line_cost', 20, 4)->nullable();
            $table->enum('cost_source', [
                'captured',            // قُرئت من المنتج لحظةَ البيع
                'estimated_backfill',  // قُدّرت في الهجرة من التكلفة الحاليّة
                'unknown',             // لا منتجَ أو لا تكلفةَ مُدخَلة
            ])->default('unknown');

            // المرتجعُ سطراً سطراً (المرحلة ٦ تستعملها؛ والعمودُ مكانُه هنا).
            $table->decimal('returned_quantity', 16, 3)->default(0);

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['sale_id']);
            $table->index(['merchant_user_id', 'product_id', 'created_at'], 'msi_merchant_product_time_idx');
            $table->index(['merchant_user_id', 'created_at']);

            $table->foreign('sale_id')->references('id')->on('merchant_sales')
                ->cascadeOnDelete();
        });

        $this->backfill();
    }

    /**
     * نقلُ ما في JSON إلى أسطرٍ — **بلا ادّعاء تكلفةٍ مقيسة**.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('merchant_sales')) {
            return;
        }

        $hasCost = Schema::hasColumn('merchant_products', 'cost_price');

        DB::table('merchant_sales')
            ->whereNotNull('items')
            ->orderBy('id')
            ->chunk(200, function ($sales) use ($hasCost) {
                $rows = [];

                foreach ($sales as $sale) {
                    $items = json_decode((string) $sale->items, true);
                    if (! is_array($items) || $items === []) {
                        continue;
                    }

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            continue;
                        }

                        // **المفتاحان مقبولان هنا**: الكاشير يرسل `qty`
                        // والمطعم يرسل `quantity`. وكانا يُقرآن في موضعٍ
                        // ويُهمَل أحدهما في موضع — فيُوحَّدان عند العتبة.
                        $qty = (string) ($item['quantity'] ?? $item['qty'] ?? 1);
                        $price = (string) ($item['price'] ?? 0);
                        $pid = $item['product_id'] ?? null;

                        $cost = null;
                        $source = 'unknown';
                        if ($pid && $hasCost) {
                            $c = DB::table('merchant_products')->where('id', $pid)->value('cost_price');
                            if ($c !== null && bccomp((string) $c, '0', 4) > 0) {
                                $cost = (string) $c;
                                $source = 'estimated_backfill';
                            }
                        }

                        $rows[] = [
                            'uuid' => (string) Str::uuid(),
                            'merchant_user_id' => $sale->merchant_user_id,
                            'sale_id' => $sale->id,
                            'sale_ulid' => $sale->sale_ulid,
                            'product_id' => $pid ?: null,
                            'name' => (string) ($item['name'] ?? 'صنف'),
                            'barcode' => $item['barcode'] ?? null,
                            'quantity' => $qty,
                            'unit_price' => $price,
                            'line_discount' => 0,
                            'line_total' => bcmul($qty, $price, 4),
                            'unit_cost' => $cost,
                            'line_cost' => $cost !== null ? bcmul($qty, $cost, 4) : null,
                            'cost_source' => $source,
                            'returned_quantity' => 0,
                            'zone_code' => $sale->zone_code ?? 'SOUTH',
                            'created_at' => $sale->created_at,
                            'updated_at' => $sale->created_at,
                        ];
                    }
                }

                if ($rows !== []) {
                    foreach (array_chunk($rows, 200) as $batch) {
                        DB::table('merchant_sale_items')->insert($batch);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_sale_items');
    }
};
