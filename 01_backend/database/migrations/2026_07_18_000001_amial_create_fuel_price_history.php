<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FUEL-PRICE-HISTORY-001 — سجلّ تغيّر أسعار الوقود.
 *
 * كل تحديث لسعر لتر وقود يُسجَّل هنا: السعر القديم والجديد والفرق ومن غيّره
 * ومتى — كما في تصميم «إعدادات تسعير الوقود» (ملخّص آخر تحديث + سجل 30 يوماً).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_price_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fuel_product_id');
            $table->unsignedBigInteger('station_id');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->decimal('old_price', 12, 4)->default(0);
            $table->decimal('new_price', 12, 4);
            $table->decimal('delta', 12, 4)->default(0); // new - old (سالب = خفض)
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fuel_product_id', 'created_at']);
            $table->index(['station_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_price_history');
    }
};
