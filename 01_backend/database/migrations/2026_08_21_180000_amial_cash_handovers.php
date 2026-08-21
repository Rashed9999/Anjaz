<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CASH-HANDOVER-001 — **قيدٌ في الدفتر ليس دليلاً على أنّ النقد تحرّك.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما تطلبه وثيقةُ الوكيل نصّاً (المحور ٩):**
 *
 *     لا تعتبر تغيير Ledger دليلاً على أنّ Cash انتقل في الواقع.
 *     عند قول النظام «الوكيل سلّم ٥٠٠٬٠٠٠ نقداً لأميال» **يجب وجود
 *     حركة Treasury/Cash قابلة للتتبّع**… ولا تجعل Note نصّيّةً مثل
 *     «استلم النقد» هي **الدليل المالي الوحيد**.
 *
 * وقِيس: `agent_settlements` تحمل `payment_method` و`payment_reference`
 * و`note` — **ولا تحمل من عناصر التسليم الأحد عشرَ إلّا اثنين**. فمن
 * سأل «من سلّم؟ ومن استلم؟ وأين؟ ومتى؟» لم يجد إلّا سطراً نصّيّاً كتبه
 * طرفٌ واحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والضابطُ الأهمُّ في هذا الجدول: المستلِمُ هو من يؤكّد، لا المُسلِّم.**
 *
 * تسليمٌ يعلنه المُرسِلُ وحدَه **دعوى** لا إثبات. وحقلا `delivered_by`
 * و`received_by` منفصلان عمداً، والحالةُ لا تصير `confirmed` إلّا بفعلِ
 * الطرف الثاني. وهي أربعُ عيونٍ على النقد الورقيّ كما هي على الإلكترونيّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_handovers')) {
            return;
        }

        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->ulid('handover_ulid')->unique();

            // **الاتّجاهُ صريحٌ ولا يُستنتَج من الإشارة.** ومبلغٌ سالبٌ
            // يعني اتّجاهاً معكوساً في نصف القراءات وخطأً في نصفها الآخر.
            $table->string('direction', 24)->index();   // agent_to_platform | platform_to_agent
            $table->decimal('amount', 24, 4);

            // ما تُسوَّى به — إن وُجد. وتسليمٌ نقديٌّ قد يقع بلا تسويةٍ
            // (‏توريدُ خزينةٍ ابتدائيّ)، فلا يُشترط الربط.
            $table->string('settlement_ulid', 40)->nullable()->index();

            $table->unsignedBigInteger('from_user_id')->nullable()->index();
            $table->unsignedBigInteger('to_user_id')->nullable()->index();

            // الخزنة/الموقع — نصٌّ حرٌّ عمداً: قد يكون فرعاً أو مكتباً أو
            // نقطةَ التقاءٍ متّفقاً عليها، ولا قائمةَ تحصرها.
            $table->string('location', 160)->nullable();

            $table->string('status', 24)->default('pending')->index();
            $table->string('reference', 120)->nullable()->index();

            $table->unsignedBigInteger('delivered_by_user_id')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // **المستلِمُ منفصلٌ عن المُسلِّم** — وهو بيتُ القصيد.
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('evidence_path', 255)->nullable();
            $table->text('note')->nullable();
            $table->text('dispute_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'direction'], 'cash_handover_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_handovers');
    }
};
