<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-RECON-NIGHTLY-001 — سجلُّ المصالحات الليليّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا يُخزَّن أصلاً؟ ألا يكفي الإنذار عند الخلل؟**
 *
 * لا. **لأنّ صمتَ الإنذار لا يعني السلامة** — قد يعني أنّ المهمّة نفسَها
 * توقّفت. ومن غير صفٍّ يقول «فُحص في ٢:٠٠ ووجدنا صفر فرق»، تُقرأ الليالي
 * الصامتة كلُّها «كان كلُّ شيءٍ سليماً» — وفيها ليالٍ لم يُفحص فيها شيء.
 *
 * (القاعدة السابعة: «غير معروف» ليس صفراً. وهنا: ليلةٌ لم تُفحص ليست
 * ليلةً بلا فروق.)
 *
 * **والثاني: الفرقُ يُقرأ بتاريخه لا بلحظته.** رقمٌ واحدٌ الليلة لا يقول
 * إن كان الانحراف بدأ اليوم أم يكبر منذ أسبوع. والسلسلةُ تقول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $t) {
            $t->id();

            $t->timestamp('ran_at')->index();

            /**
             * `clean`    — فُحص ولا فرق
             * `diverged` — فُحص ووُجد فرق
             * `failed`   — لم يكتمل الفحص (وهو **ليس** `clean`)
             */
            $t->string('status', 16)->index();

            // ── ١) محافظ العملاء مقابل الدفتر ──
            $t->unsignedInteger('wallets_checked')->default(0);
            $t->unsignedInteger('wallets_diverged')->default(0);
            $t->decimal('wallets_gap', 20, 4)->default(0);

            // ── ٢) توازن الدفتر في ذاته ──
            $t->unsignedInteger('unbalanced_entries')->default(0);
            $t->decimal('ledger_net', 20, 4)->default(0);   // يجب أن يكون صفراً

            // ── ٣) خزائن النقد الورقيّ ──
            $t->unsignedInteger('tills_checked')->default(0);
            $t->unsignedInteger('tills_diverged')->default(0);
            $t->decimal('tills_gap', 20, 4)->default(0);

            /**
             * **العمى المُعلَن.** تدفّقاتٌ نعرف أنّها لا تُرحّل بعد، فما
             * ينقص فيها ليس ضياعَ مالٍ بل دَينٌ معلوم. ويُكتب هنا كي
             * يُقرأ التقرير بحدوده لا بإطلاقه.
             */
            $t->json('blind_spots')->nullable();

            $t->unsignedInteger('duration_ms')->default(0);
            $t->text('notes')->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
