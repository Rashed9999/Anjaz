<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المراحل ٧ و٨ و٩ — **التعميم والحجز**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ── ٧) نسخُ الأسعار ونقدُ الوردية — **تُعمَّم ولا تُنسخ** ────────────────
 *
 * `fuel_price_versions` و`fuel_shift_cash_movements` بُنيتا في جولة
 * الوقود، **وليس فيهما شيءٌ خاصٌّ بالوقود** غير الاسم. ونسخُهما بجدولين
 * جديدين يعني منطقَين يتباعدان: يُصلَح عطلٌ في أحدهما ويبقى في الآخر.
 *
 * فيُبنى الجدولان عامّين، **وتُنقل صفوفُ الوقود إليهما**، وتصير خدمةُ
 * الوقود واجهةً رفيعةً فوق الخدمة العامّة.
 *
 * ── ٩) الحجز — **المخزونُ يُقيَّد عند نجاح الدفع لا عند الضغط** ──────────
 *
 * الدفعُ بأميال باي غيرُ متزامن: يُعرض رمزٌ وينتظر الكاشيرُ المسح.
 * وينقص المخزونُ اليوم عند تسجيل البيعة — **فإن لم يُدفع، نقص لبيعةٍ لم
 * تقع**. وإن لم يُنقص أصلاً، بيع الصنفُ نفسُه مرّتين في الدقيقة نفسِها.
 *
 * **فالحجزُ هو الجواب** — وعمودُ `product_stocks.reserved` بُني له في
 * المرحلة ٠ ولم يُستعمل بعد: البضاعةُ تبقى موجودةً وتخرج من المتاح.
 *
 * **وحجزٌ بلا انتهاءٍ أسوأ من غيابه**: زبونٌ انصرف يترك بضاعةً محجوزةً
 * إلى الأبد، ويُقرأ المتجرُ فارغاً وأرففُه ملأى. فلكلّ حجزٍ `expires_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->priceVersions();
        $this->shiftCash();
        $this->reservations();
    }

    // ══════════ ٧أ) نسخُ الأسعار — عامّةً ══════════
    private function priceVersions(): void
    {
        Schema::create('product_price_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('product_id');
            // فرعٌ يسعّر خلافَ غيره — والفراغُ يعني «كلَّ المواقع».
            $table->unsignedBigInteger('location_id')->nullable();

            $table->decimal('price', 20, 4);
            $table->decimal('offer_price', 20, 4)->nullable();
            $table->decimal('cost_price_at_time', 20, 4)->nullable();

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->enum('status', ['proposed', 'approved', 'active', 'expired', 'rejected'])
                ->default('proposed');

            // **المقترِحُ ليس المعتمِد** — يُحرَس في الخدمة لا هنا.
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'effective_from'], 'ppv_product_status_idx');
            $table->index(['merchant_user_id', 'status']);

            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
        });
    }

    // ══════════ ٧ب) نقدُ الوردية — عامّاً ══════════
    private function shiftCash(): void
    {
        Schema::create('merchant_shift_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id')->nullable();

            // **الورديّةُ نوعٌ ومعرّف** — محطّةٌ أو كاشير، والمنطقُ واحد.
            $table->enum('shift_type', ['fuel', 'cashier']);
            $table->unsignedBigInteger('shift_id');

            $table->enum('direction', ['in', 'out']);
            $table->string('reason', 40);
            $table->decimal('amount', 20, 4);

            $table->string('reference', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['shift_type', 'shift_id'], 'mscm_shift_idx');
            $table->index(['merchant_user_id', 'direction']);
        });

        // نقلُ صفوف الوقود — **ولا يُترك تاريخٌ خلف الجدار**.
        if (Schema::hasTable('fuel_shift_cash_movements')) {
            DB::table('fuel_shift_cash_movements')->orderBy('id')
                ->chunk(200, function ($rows) {
                    $out = [];
                    foreach ($rows as $r) {
                        $out[] = [
                            'uuid' => (string) Str::uuid(),
                            'merchant_user_id' => null,
                            'shift_type' => 'fuel',
                            'shift_id' => $r->shift_id,
                            'direction' => $r->direction,
                            'reason' => $r->reason,
                            'amount' => $r->amount,
                            'reference' => $r->reference,
                            'note' => $r->note,
                            'actor_user_id' => $r->actor_user_id,
                            'approved_by_user_id' => $r->approved_by_user_id,
                            'created_at' => $r->created_at,
                            'updated_at' => $r->updated_at,
                        ];
                    }
                    if ($out !== []) {
                        DB::table('merchant_shift_cash_movements')->insert($out);
                    }
                });
        }
    }

    // ══════════ ٩) الحجز ══════════
    private function reservations(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');

            $table->unsignedBigInteger('sale_id')->nullable();
            $table->string('sale_ulid', 40)->nullable()->index();

            $table->decimal('quantity', 16, 3);

            // held: محجوزٌ ينتظر · consumed: دُفع فصار حركةً · released: أُفرج
            $table->enum('status', ['held', 'consumed', 'released'])->default('held');

            // **ولا حجزَ بلا أجل** — انظر شرح الملفّ.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->string('release_reason', 60)->nullable();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'location_id', 'status'], 'sr_prod_loc_status_idx');
            $table->index(['merchant_user_id', 'status']);

            $table->foreign('product_id')->references('id')->on('merchant_products')
                ->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('merchant_locations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('merchant_shift_cash_movements');
        Schema::dropIfExists('product_price_versions');
    }
};
