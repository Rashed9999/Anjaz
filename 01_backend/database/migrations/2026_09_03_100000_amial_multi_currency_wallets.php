<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MULTI-CURRENCY-002 — **المحفظةُ تصير محافظ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان في المنصّة رصيدٌ واحدٌ بالريال اليمنيّ، وجدولُ `merchant_currencies`
 * لا يفعل غيرَ طباعةِ سطرِ مكافئٍ على الإيصال. فصار لكلّ مستخدمٍ **محفظةٌ
 * لكلّ عملة**.
 *
 * **وأخطرُ ما في هذه الهجرة أنّها تمسّ `e_money` — والرصيدُ فيها هو المال
 * نفسُه.** فثلاثةُ قراراتٍ تُقرأ ولا تُخمَّن:
 *
 * ① **الافتراضيُّ `YER` وليس فارغاً.** فكلُّ صفٍّ قائمٍ يصير محفظةَ
 *    ريالٍ صراحةً — لا صفَّ بلا عملة، ولا هجرةَ بياناتٍ لاحقة.
 *
 * ② **المفتاحُ الفريدُ ينتقل من `user_id` إلى `(user_id, currency)`.**
 *    ولولاه لصار للمستخدم صفّان بعملةٍ واحدةٍ ينقسم المالُ بينهما صامتاً.
 *    **ويُسقَط القديمُ أوّلاً ثمّ يُنشأ الجديد** — والترتيبُ ملزِم: قيدٌ
 *    فريدٌ على `user_id` وحدَه يمنع إنشاءَ محفظةِ دولارٍ من أصلها.
 *
 * ③ **`transactions` تحمل السعرَ مجمَّداً لا مرجعاً إليه.** فسعرُ الصرف
 *    يتغيّر، وقراءةُ فاتورةِ الأمس بسعر اليوم تُعيد كتابةَ التاريخ. وهو
 *    ما وقع في تسعيرة الباقات من قبل. فالسعرُ يُنسَخ في المعاملة ولا
 *    يُشار إليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ① أسعارُ الصرف: مصدرٌ مركزيٌّ واحدٌ بتاريخٍ لا يُمحى ──────────
        //
        // **جدولٌ لا يُعدَّل ولا يُحذَف منه** — سعرٌ جديدٌ صفٌّ جديد. فمعرفةُ
        // «بكم كان الدولارُ يومَ الفاتورة» شرطُ أيّ تدقيقٍ أو مصالحة،
        // وتعديلُ صفٍّ قائمٍ يمحو ذلك بلا أثر.
        if (!Schema::hasTable('fx_rates')) {
            Schema::create('fx_rates', function (Blueprint $table) {
                $table->id();
                $table->string('currency', 3)->index();
                $table->string('base_currency', 3)->default('YER');
                // ١ وحدةٍ من `currency` = كم من الأساس.
                $table->decimal('rate_to_base', 24, 8);
                // **المصدرُ إلزاميّ**: رقمٌ بلا مصدرٍ لا يُدقَّق.
                $table->string('source', 40);            // manual_admin · central_bank · …
                $table->string('source_note', 160)->nullable();
                $table->timestamp('effective_at');       // متى يبدأ العملُ به
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['currency', 'effective_at'], 'fx_rate_lookup_idx');
            });
        }

        // ── ② المحفظة: مفتاحٌ مركّبٌ (مستخدم، عملة) ────────────────────
        if (Schema::hasTable('e_money') && !Schema::hasColumn('e_money', 'currency')) {
            Schema::table('e_money', function (Blueprint $table) {
                $table->string('currency', 3)->default('YER')->after('user_id');
            });

            // كلُّ ما كان قائماً محفظةُ ريال — يُقال صراحةً ولا يُترك للافتراضيّ.
            DB::table('e_money')->update(['currency' => 'YER']);

            // **الترتيبُ ملزِم**: يُسقَط الفريدُ القديم قبل إنشاء الجديد.
            $this->dropIndexIfExists('e_money', 'e_money_user_id_unique');

            Schema::table('e_money', function (Blueprint $table) {
                $table->unique(['user_id', 'currency'], 'e_money_user_currency_unique');
            });
        }

        // ── ③ القيدُ يعرف عملتَه ──────────────────────────────────────
        //
        // **على القيد لا على السطر**: القاعدةُ المفروضةُ أنّ كلَّ سطور
        // القيد الواحد بعملةٍ واحدة (وإلّا فقيدٌ «متوازنٌ» عبر عملتين وهو
        // ليس متوازناً)، فعمودٌ واحدٌ على الرأس يقولها ويُدقَّق بها.
        if (Schema::hasTable('ledger_journal_entries')
            && !Schema::hasColumn('ledger_journal_entries', 'currency')) {
            Schema::table('ledger_journal_entries', function (Blueprint $table) {
                $table->string('currency', 3)->default('YER')->after('total_amount')->index();
            });
            DB::table('ledger_journal_entries')->update(['currency' => 'YER']);
        }

        // ── ④ المعاملةُ تحمل عملتَها وسعرَها مجمَّداً ────────────────────
        if (Schema::hasTable('transactions') && !Schema::hasColumn('transactions', 'currency')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('currency', 3)->default('YER')->after('amount')->index();
                // السعرُ لحظةَ العمليّة — منسوخٌ لا مُشارٌ إليه.
                $table->decimal('fx_rate_to_base', 24, 8)->default(1)->after('currency');
                // المكافئُ بالأساس — تُجمَع به التقاريرُ والحدود.
                $table->decimal('base_amount', 24, 4)->nullable()->after('fx_rate_to_base');
                $table->unsignedBigInteger('fx_rate_id')->nullable()->after('base_amount');
            });
            DB::statement('UPDATE transactions SET currency = ?, fx_rate_to_base = 1, base_amount = amount', ['YER']);
        }

        // ── ⑤ العملاتُ التي يقبلها التاجر ───────────────────────────────
        //
        // `merchant_currencies` كان يحمل سعراً يكتبه التاجرُ بيده. **والسعرُ
        // انتقل إلى المركز** (`fx_rates`) — فبقاؤه هنا مصدرٌ ثانٍ للحقيقة:
        // فاتورةٌ تُطبع بسعر التاجر ومحفظةٌ تُقيَّد بسعر المنصّة، فيختلف
        // الرقمان على الورقة نفسِها. فصار الجدولُ **اختيارَ عملاتٍ** لا
        // تسعيراً، ويُعلَّم العمودُ القديم بأنّه للعرض التاريخيّ وحدَه.
        if (Schema::hasTable('merchant_currencies')
            && !Schema::hasColumn('merchant_currencies', 'accepts_payments')) {
            Schema::table('merchant_currencies', function (Blueprint $table) {
                $table->boolean('accepts_payments')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'currency')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn(['currency', 'fx_rate_to_base', 'base_amount', 'fx_rate_id']);
            });
        }

        if (Schema::hasTable('ledger_journal_entries')
            && Schema::hasColumn('ledger_journal_entries', 'currency')) {
            Schema::table('ledger_journal_entries', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }

        if (Schema::hasTable('merchant_currencies')
            && Schema::hasColumn('merchant_currencies', 'accepts_payments')) {
            Schema::table('merchant_currencies', function (Blueprint $table) {
                $table->dropColumn('accepts_payments');
            });
        }

        if (Schema::hasTable('e_money') && Schema::hasColumn('e_money', 'currency')) {
            // **لا يُتراجَع على بياناتٍ حيّة**: محافظُ العملات الأخرى تحمل
            // مالاً، وإسقاطُ العمود يدمج أرصدةً مختلفةَ العملات في صفوفٍ
            // متصادمة. فيُرفَض التراجعُ صراحةً بدل أن يُتلف صامتاً.
            $foreign = DB::table('e_money')->where('currency', '!=', 'YER')->count();
            if ($foreign > 0) {
                throw new RuntimeException(
                    "لا يُتراجَع عن تعدّد العملات و{$foreign} محفظةً بعملةٍ غير الريال تحمل رصيداً. "
                    .'صفِّها أوّلاً (تحويلٌ إلى الريال) ثمّ أعِد المحاولة.'
                );
            }

            $this->dropIndexIfExists('e_money', 'e_money_user_currency_unique');
            Schema::table('e_money', function (Blueprint $table) {
                $table->dropColumn('currency');
                $table->unique('user_id', 'e_money_user_id_unique');
            });
        }

        Schema::dropIfExists('fx_rates');
    }

    /** إسقاطُ فهرسٍ قد لا يوجد — ولا يُسقِط الهجرةَ إن غاب. */
    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($i) => $i->Key_name === $index);

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }
};
