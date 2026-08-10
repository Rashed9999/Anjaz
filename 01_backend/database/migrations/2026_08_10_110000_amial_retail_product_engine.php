<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلتان ٢ و٣ — **محرّكُ الأصناف**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * وهو ما كان الخزّانُ في الوقود — **وهو أكبر**. فهناك مورِدٌ واحدٌ متّصل،
 * وهنا آلافُ الأصناف المنفصلة، لكلٍّ تصنيفٌ وعلامةٌ ووحدةٌ وباركود.
 *
 * ── ر-٤: التصنيفُ نصٌّ لا جدول ─────────────────────────────────────────
 *
 * `merchant_products.category` سلسلةٌ حرّة. **و«مشروبات» و«مشروبات » و
 * «المشروبات» ثلاثةُ تصنيفات**: تقريرُ الأصناف يوزّعها ثلاثاً، والبحثُ
 * بأحدها يُخفي الآخرين، والحدُّ الأدنى للطلب يُضبط على واحدٍ من ثلاثة.
 *
 * ── ر-٣: لا متغيّرات ولا SKU ───────────────────────────────────────────
 *
 * قميصٌ بثلاثة ألوانٍ وثلاثة مقاسات = **تسعةُ أصنافٍ مستقلّة** لا يجمعها
 * شيء: يُغيَّر سعرُ القميص فيُعدَّل تسع مرّات، وواحدةٌ تُنسى فتُباع بسعر
 * الشهر الماضي.
 *
 * **والمتغيّرُ هنا صنفٌ ابنٌ لا جدولٌ ثانٍ.** فالمتغيّرُ يُباع ويُخزَّن
 * ويُجرَد ويُرتجَع مثلَ أيّ صنف؛ وجدولٌ منفصلٌ له يعني ازدواجَ كلّ ذلك.
 * فيُضاف `parent_product_id` و`variant_attributes`، وتبقى المخزوناتُ
 * والأسطرُ والحركاتُ تشير إلى `merchant_products` وحدَه.
 *
 * ── والباركودُ عمودٌ واحد ──────────────────────────────────────────────
 *
 * وصنفٌ له باركودان — **الحبّةُ والكرتون** — لا مكان له. فيُمسح كرتونٌ
 * فلا يُعرَف، أو يُسجَّل صنفاً ثانياً بمخزونٍ ثانٍ للبضاعة نفسِها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والأعمدةُ القديمة تبقى مرايا** (`category` و`barcode`) — تقرؤها
 * شاشاتٌ اليوم، وتُزال حين لا يبقى قارئ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->categories();
        $this->brands();
        $this->units();
        $this->extendProducts();
        $this->barcodes();
        $this->backfill();
    }

    // ── التصنيفات — **شجرةٌ لا قائمة** ─────────────────────────────────
    //
    // «مواد غذائية ← ألبان ← أجبان» ثلاثةُ مستويات. وقائمةٌ مسطّحةٌ تجعل
    // «كم بعتُ من المواد الغذائية؟» سؤالاً بلا جواب.
    private function categories(): void
    {
        if (Schema::hasTable('merchant_categories')) {
            return;
        }

        Schema::create('merchant_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->string('name', 120);
            $table->string('code', 40);
            $table->string('icon', 40)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_user_id', 'code']);
            $table->index(['merchant_user_id', 'parent_id', 'is_active']);

            $table->foreign('parent_id')->references('id')->on('merchant_categories')
                ->nullOnDelete();
        });
    }

    private function brands(): void
    {
        if (Schema::hasTable('merchant_brands')) {
            return;
        }

        Schema::create('merchant_brands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');

            $table->string('name', 120);
            $table->string('code', 40);
            $table->string('country', 60)->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_user_id', 'code']);
            $table->index(['merchant_user_id', 'is_active']);
        });
    }

    // ── الوحدات — **والكسرُ يُقال في البنية لا في العرض** ───────────────
    //
    // `decimals` ليست تنسيقاً: بيعُ نصف حبّةٍ خطأ، وبيعُ نصف كيلو صواب.
    // فمن لم يُخزّن العدد المسموح من الكسور قَبِل «٢٫٥ ثلّاجة».
    private function units(): void
    {
        if (Schema::hasTable('merchant_units')) {
            return;
        }

        Schema::create('merchant_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');

            $table->string('name', 60);
            $table->string('code', 20);
            $table->unsignedTinyInteger('decimals')->default(0);

            // وحدةُ التعبئة: كرتونٌ = ٢٤ حبّة. **والمخزونُ يُمسك بالأساس
            // وحدَه** — ومسكُه بوحدتين يُنتج مجموعين لا يتّفقان.
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->decimal('factor', 16, 4)->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['merchant_user_id', 'code']);
            $table->foreign('base_unit_id')->references('id')->on('merchant_units')
                ->nullOnDelete();
        });
    }

    private function extendProducts(): void
    {
        Schema::table('merchant_products', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_products', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }
            if (! Schema::hasColumn('merchant_products', 'sku')) {
                $table->string('sku', 64)->nullable()->after('name');
            }
            if (! Schema::hasColumn('merchant_products', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('category');
            }
            if (! Schema::hasColumn('merchant_products', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('category_id');
            }
            if (! Schema::hasColumn('merchant_products', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable()->after('brand_id');
            }

            // ── المتغيّرات ────────────────────────────────────────────
            if (! Schema::hasColumn('merchant_products', 'parent_product_id')) {
                $table->unsignedBigInteger('parent_product_id')->nullable()->after('unit_id');
            }
            if (! Schema::hasColumn('merchant_products', 'variant_attributes')) {
                // {"اللون":"أحمر","المقاس":"L"} — **وصفُ المتغيّر لا اسمُه**.
                $table->json('variant_attributes')->nullable()->after('parent_product_id');
            }
            if (! Schema::hasColumn('merchant_products', 'is_variant_parent')) {
                // **الأبُ لا يُباع ولا يُخزَّن** — هو مِظلّةٌ للمتغيّرات.
                // وبيعُه يعني بيعَ «قميص» بلا لونٍ ولا مقاس.
                $table->boolean('is_variant_parent')->default(false)->after('variant_attributes');
            }

            // خدمةٌ أو صنفٌ بلا مخزون (شحن، تركيب) — **ولا يُقاس نقصُه**.
            if (! Schema::hasColumn('merchant_products', 'track_stock')) {
                $table->boolean('track_stock')->default(true)->after('is_variant_parent');
            }
            if (! Schema::hasColumn('merchant_products', 'reorder_level')) {
                $table->decimal('reorder_level', 16, 3)->default(0)->after('track_stock');
            }
        });

        Schema::table('merchant_products', function (Blueprint $table) {
            $table->index(['merchant_user_id', 'category_id'], 'mp_merchant_category_idx');
            $table->index(['merchant_user_id', 'brand_id'], 'mp_merchant_brand_idx');
            $table->index(['merchant_user_id', 'sku'], 'mp_merchant_sku_idx');
            $table->index('parent_product_id', 'mp_parent_idx');
        });
    }

    // ── الباركودات — **صنفٌ واحدٌ بأكثرَ من رمز** ───────────────────────
    private function barcodes(): void
    {
        if (Schema::hasTable('product_barcodes')) {
            return;
        }

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('product_id');

            $table->string('barcode', 64);
            $table->unsignedBigInteger('unit_id')->nullable();

            // كرتونٌ فيه ٢٤: مسحُه يضيف ٢٤ حبّةً للسلّة، **لا حبّةً واحدة**.
            $table->decimal('pack_size', 16, 3)->default(1);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // **فريدٌ داخل التاجر لا عالميّاً**: باركودُ منتجٍ محلّيٍّ قد
            // يتكرّر بين تاجرين، ومنعُه عالميّاً يُعطّل تاجراً بسبب آخر.
            $table->unique(['merchant_user_id', 'barcode']);
            $table->index(['product_id', 'is_primary']);

            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('merchant_units')
                ->nullOnDelete();
        });
    }

    /**
     * الهجرةُ التاريخيّة — **النصُّ الحرُّ يصير صفوفاً، ولا يُخترع شيء**.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('merchant_products')) {
            return;
        }

        // ① uuid لكلّ صنفٍ قائم
        DB::table('merchant_products')->whereNull('uuid')->orderBy('id')
            ->chunkById(300, function ($rows) {
                foreach ($rows as $r) {
                    DB::table('merchant_products')->where('id', $r->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        // ② التصنيفاتُ النصّيّة تصير صفوفاً — **بعد تطبيعِ الفراغ**، وإلّا
        //    نُقل العطلُ نفسُه من عمودٍ إلى جدول.
        $pairs = DB::table('merchant_products')
            ->whereNotNull('category')->where('category', '!=', '')
            ->select('merchant_user_id', 'category')->distinct()->get();

        $map = [];   // merchant → normalised → id
        foreach ($pairs as $p) {
            $clean = trim(preg_replace('/\s+/u', ' ', (string) $p->category));
            if ($clean === '') {
                continue;
            }
            $key = $p->merchant_user_id . '|' . $clean;
            if (isset($map[$key])) {
                continue;
            }

            $code = Str::slug($clean, '_');
            if ($code === '') {
                $code = 'cat_' . substr(md5($clean), 0, 8);
            }
            $code = substr($code, 0, 34) . '_' . substr(md5($clean), 0, 4);

            $map[$key] = DB::table('merchant_categories')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $p->merchant_user_id,
                'name' => $clean,
                'code' => $code,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ($map as $key => $id) {
            [$mid, $clean] = explode('|', $key, 2);
            DB::table('merchant_products')
                ->where('merchant_user_id', $mid)
                ->whereRaw("TRIM(category) = ?", [$clean])
                ->update(['category_id' => $id]);
        }

        // ③ وحدةٌ افتراضيّةٌ لكلّ تاجر: **حبّة**، بلا كسور.
        $merchants = DB::table('merchant_products')
            ->select('merchant_user_id')->distinct()->pluck('merchant_user_id');

        foreach ($merchants as $mid) {
            $unitId = DB::table('merchant_units')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $mid,
                'name' => 'حبة',
                'code' => 'PCS',
                'decimals' => 0,
                'factor' => 1,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('merchant_products')->where('merchant_user_id', $mid)
                ->whereNull('unit_id')->update(['unit_id' => $unitId]);
        }

        // ④ الباركودُ القديم يصير باركوداً أساسيّاً
        DB::table('merchant_products')
            ->whereNotNull('barcode')->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunkById(300, function ($rows) {
                foreach ($rows as $r) {
                    $exists = DB::table('product_barcodes')
                        ->where('merchant_user_id', $r->merchant_user_id)
                        ->where('barcode', $r->barcode)->exists();
                    if ($exists) {
                        continue;   // تاجرٌ كرّر الرمزَ على صنفين — يُترك للمراجعة
                    }

                    DB::table('product_barcodes')->insert([
                        'merchant_user_id' => $r->merchant_user_id,
                        'product_id' => $r->id,
                        'barcode' => $r->barcode,
                        'unit_id' => $r->unit_id,
                        'pack_size' => 1,
                        'is_primary' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');

        Schema::table('merchant_products', function (Blueprint $table) {
            foreach ([
                'mp_merchant_category_idx', 'mp_merchant_brand_idx',
                'mp_merchant_sku_idx', 'mp_parent_idx',
            ] as $idx) {
                try { $table->dropIndex($idx); } catch (\Throwable) {}
            }
        });

        Schema::table('merchant_products', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter([
                'uuid', 'sku', 'category_id', 'brand_id', 'unit_id',
                'parent_product_id', 'variant_attributes', 'is_variant_parent',
                'track_stock', 'reorder_level',
            ], fn ($c) => Schema::hasColumn('merchant_products', $c))));
        });

        Schema::dropIfExists('merchant_units');
        Schema::dropIfExists('merchant_brands');
        Schema::dropIfExists('merchant_categories');
    }
};
