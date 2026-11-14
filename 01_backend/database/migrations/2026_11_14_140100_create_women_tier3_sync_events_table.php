<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WOMEN-TIER3-001 — إشعارُ موثّق بقرارٍ صدر في أميال باي، لا طلبَ قرار.
 *
 * عكس `muwathaq_review_requests` تمامًا: هناك موثّق يملك القرار وأميال باي
 * يطبّقه؛ هنا أميال باي قرَّر فعلاً (إدارته تأكّدت حضوريًا أو بالاتصال
 * المرئي) وموثّق يُبلَّغ فقط ليبقى سجلّه متزامنًا. جدولٌ مستقلٌّ عمدًا —
 * خلطُه بجدول الطلبات يوهم بأنّ هذا طلبٌ ينتظر قرار موثّق، وهو ليس كذلك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('women_tier3_sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_ulid', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->constrained('users');
            $table->string('verification_method', 32);
            $table->string('verification_reference', 255);
            $table->string('sync_status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('women_tier3_sync_events');
    }
};
