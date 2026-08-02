<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DAILY-SETTLEMENT-001 — التسوية اليوميّة بين الوكيل وأميال.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الريال الورقيّ يصير إلكترونياً، والإلكترونيّ يصير ورقيّاً — وهذه
 * اللحظةُ التي يقع فيها التحوّل.**
 *
 * فحين يودع عميلٌ ألفاً في فرعٍ: يسلّم ورقاً للوكيل، ويخرج من رصيد
 * الوكيل الإلكترونيّ ألفٌ إلى محفظة العميل. فيمتلئ درجُ الوكيل ورقاً
 * ويفرغ رصيدُه — والورقُ الذي في يده **غطاءُ** رصيدٍ صار عند العميل.
 *
 * وحين يسحب عميل: العكس تماماً.
 *
 * فآخر اليوم يكون للوكيل **صافٍ** في اتّجاهٍ واحد:
 *
 *   • إيداعاتٌ أكثر ⇒ عنده ورقٌ زائدٌ ورصيدٌ ناقص
 *     ⇒ يسلّم الورق لأميال ويستلم رصيداً  (topup)
 *
 *   • سحوباتٌ أكثر ⇒ عنده رصيدٌ زائدٌ وورقٌ ناقص
 *     ⇒ يعيد الرصيد لأميال ويستلم ورقاً  (payout)
 *
 * وبلا هذه التسوية اليوميّة يتراكم الاختلال حتى يعجز الوكيل عن الخدمة
 * ذات صباح — ويبقى في الشبكة رصيدٌ إلكترونيٌّ غطاؤه ورقٌ في درج رجلٍ
 * في المكلا لا في خزينة المنصّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولذلك يُقفل اليوم في وقتٍ محدَّد.** والتأخّر ليس تفصيلاً إجرائياً:
 * هو مالٌ بلا غطاءٍ مقابلٍ ليلةً كاملة. فيُسجَّل، ويحتاج فكّاً من إدارة
 * أميال، ولا يُغتفر بصمت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_daily_settlements', function (Blueprint $table) {
            $table->id();
            $table->ulid('settlement_ulid')->unique();

            $table->unsignedBigInteger('agent_user_id')->index();
            $table->date('settlement_date');

            // ── ما جرى في اليوم — محسوبٌ من المصدر عند الرفع ─────────
            $table->unsignedInteger('deposits_count')->default(0);
            $table->decimal('deposits_total', 20, 4)->default(0);
            $table->unsignedInteger('withdrawals_count')->default(0);
            $table->decimal('withdrawals_total', 20, 4)->default(0);
            $table->decimal('fees_collected', 20, 4)->default(0);
            $table->decimal('agent_commission', 20, 4)->default(0);

            // ── الفروق: عجزاً وفائضاً منفصلَين ولا يُقاصّان ───────────
            $table->unsignedInteger('shortage_count')->default(0);
            $table->decimal('shortage_total', 20, 4)->default(0);
            $table->unsignedInteger('overage_count')->default(0);
            $table->decimal('overage_total', 20, 4)->default(0);
            $table->unsignedInteger('unclosed_shifts')->default(0);
            $table->unsignedInteger('suspicious_count')->default(0);

            // ── التحوّل: ورقٌ ⇄ إلكترونيّ ──────────────────────────────
            // `net_cash` موجبٌ = ورقٌ زائدٌ في يد الوكيل (إيداعاتٌ أكثر).
            $table->decimal('net_cash', 20, 4)->default(0);
            $table->decimal('net_float', 20, 4)->default(0);
            // topup = يسلّم ورقاً ويستلم رصيداً · payout = العكس · none
            $table->string('conversion', 10)->default('none');
            $table->decimal('conversion_amount', 20, 4)->default(0);

            // ── الحالة والنافذة ───────────────────────────────────────
            // draft | submitted | accepted | rejected
            $table->string('status', 20)->default('draft')->index();
            // on_time | late | unlocked
            $table->string('window_state', 12)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by_staff_id')->nullable();

            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            // التسوية الماليّة الناتجة (agent_settlements) بعد القبول.
            $table->string('linked_settlement_ulid', 40)->nullable();

            // فكُّ التأخير: من أذن، ومتى، ولماذا.
            $table->unsignedBigInteger('unlocked_by_user_id')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->string('unlock_reason', 500)->nullable();

            $table->json('detail')->nullable();
            $table->timestamps();

            // يومٌ واحدٌ لكلّ وكيل — لا تُرفع تسويتان لليوم نفسه.
            $table->unique(['agent_user_id', 'settlement_date'], 'agent_daily_once_idx');
            $table->index(['settlement_date', 'status'], 'agent_daily_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_daily_settlements');
    }
};
