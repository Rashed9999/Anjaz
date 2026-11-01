<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BUSINESS-FEES-001 — الرسم الذي تحمّله التاجر من دفعة النيّة (صفرٌ اليوم).
 * أميال باي محرّك الرسوم الوحيد؛ يُرسل الرسم والصافي في كل حدث فتقيّدهما أميال أعمال
 * كما وقعا، ولا تحسب رسماً بنفسها أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_payment_intents', function (Blueprint $table) {
            $table->decimal('merchant_fee', 24, 4)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('business_payment_intents', fn (Blueprint $t) => $t->dropColumn('merchant_fee'));
    }
};
