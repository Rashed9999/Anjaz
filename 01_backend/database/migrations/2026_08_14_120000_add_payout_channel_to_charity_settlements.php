<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CHARITY-PAYOUT-001 — **صرفُ مال التبرّعات: من العهدة إلى يدٍ.**
 *
 * ══════════════════════════════════════════════════════════════════
 * كلُّ تبرّعٍ يُقيَّد: مدين محفظة المتبرّع · دائن `CHARITY_ESCROW`.
 * و`CHARITY_ESCROW` **يُدان في موضعٍ واحدٍ في المشروع كلِّه: لا موضع.**
 *
 * أي أنّ العهدة تكبر ولا تُفرَّغ أبداً، وأنّ «تمّ التحويل» كان — حرفيّاً —
 * حقلَ نصٍّ يُكتب فيه رقمُ حوالة. المالُ لم يتحرّك ولم يُقيَّد، والالتزامُ
 * على المنصّة باقٍ في الدفتر إلى الأبد.
 *
 * فتُضاف قناةُ الصرف: **محفظة أميال · وكيل · بنك** — ولكلٍّ قيدُها الذي
 * يُدين العهدة فعلاً، ومعرّفُ قيدٍ يربط الصرفَ بالدفتر.
 * ══════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charity_settlements', function (Blueprint $table) {
            // 'bank' هي سلوكُ ما قبل هذه الهجرة — فتبقى الافتراضيّ حتّى لا
            // تُوصَف تسويةٌ قديمةٌ بقناةٍ لم تُستعمل فيها.
            $table->enum('payout_method', ['bank', 'wallet', 'agent'])
                ->default('bank')->after('status');

            // من قبض: محفظةُ الجمعيّة أو ممثّلها، أو الوكيلُ الذي دفع نقداً.
            $table->unsignedBigInteger('payout_user_id')->nullable()->after('payout_method');

            // الرابطُ إلى الدفتر — بلا هذا يبقى الصرفُ ادّعاءً لا أثراً.
            $table->unsignedBigInteger('payout_journal_entry_id')->nullable()
                ->after('payout_user_id');

            $table->index('payout_user_id', 'charity_settlements_payout_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('charity_settlements', function (Blueprint $table) {
            $table->dropIndex('charity_settlements_payout_user_idx');
            $table->dropColumn(['payout_method', 'payout_user_id', 'payout_journal_entry_id']);
        });
    }
};
