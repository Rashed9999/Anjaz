<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AGENT-SHIFT-REVIEW-001 — الورديّة المغلقة **ملفُّ تسوية**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * الصرّاف يُغلق ورديّته فيعدّ درجه ويكتب المبلغ. وذلك بذاته إقرارٌ ماليّ:
 * «استلمتُ كذا، وسلّمتُ كذا، وفي يدي كذا». وكان يُسجَّل الفرق ثمّ **يُترك**
 * — لا أحد يقرّ ولا أحد يسأل، فيمرّ العجز بصمتٍ ويصير عُرفاً.
 *
 * فتُضاف هنا حالةُ المراجعة: من قرأ الملفّ، ومتى، وبأيّ قرار.
 *
 * **والحالة لا تبدأ «مقبولة»**: ورديّةٌ بفرقٍ تبدأ `pending` وتنتظر إنساناً.
 * وورديّةٌ بلا فرقٍ تبدأ `balanced` — وهي ليست «مقبولة» أيضاً، هي **لا
 * تحتاج قراراً**. والتمييز بينهما مقصود: «قُبل» تعني أنّ أحداً نظر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_shifts', function (Blueprint $table) {
            // balanced | pending | accepted | investigating | resolved
            $table->string('review_status', 20)->default('balanced')->after('close_note');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');

            $table->index(['branch_id', 'review_status'], 'agent_shifts_branch_review_idx');
        });

        // الورديّات المغلقة قبل هذا التغيير: ما لها فرقٌ ينتظر قراراً،
        // والباقي متوازن. ولا تُعلَّم أيٌّ منها «مقبولة» — لم ينظر فيها أحد.
        if (Schema::hasTable('agent_shifts')) {
            \Illuminate\Support\Facades\DB::table('agent_shifts')
                ->where('status', 'closed')
                ->whereRaw('COALESCE(variance, 0) <> 0')
                ->update(['review_status' => 'pending']);
        }
    }

    public function down(): void
    {
        Schema::table('agent_shifts', function (Blueprint $table) {
            $table->dropIndex('agent_shifts_branch_review_idx');
            $table->dropColumn(['review_status', 'reviewed_by', 'reviewed_at', 'review_note']);
        });
    }
};
