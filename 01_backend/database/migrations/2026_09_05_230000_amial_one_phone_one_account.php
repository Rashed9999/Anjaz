<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-IDENTITY-UNIQUE-001 — **هاتفٌ واحدٌ لحسابٍ واحد، بقيدٍ في القاعدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** `unique:users` مكتوبةٌ في تسجيل العميل والوكيل والتاجر،
 * **والفهرسُ في القاعدة `Non_unique = 1`** — أي أنّ المنعَ في التطبيق
 * وحدَه. وله ثلاثةُ ثقوبٍ كلُّها وقع مثلُها في هذا المشروع سلفاً:
 *
 *   ① **سباقُ التسجيل.** طلبان في اللحظة نفسِها يقرآن «غير موجود» ثمّ
 *      يُدرجان معاً — وهو بعينه ما أسقط `USER_WALLET_{id}` بـ
 *      `1062 Duplicate entry`، ومكتوبٌ في `CLAUDE.md`. **والقيدُ في
 *      القاعدة هو الشيءُ الوحيد الذرّيّ.**
 *
 *   ② **والأوامرُ الإداريّةُ لا تمرّ بالتحقّق** — `EnsureDemoStaff`
 *      و`EnsureDemoMerchants` تكتب مباشرةً.
 *
 *   ③ **وصيغُ الرقم.** `967771234567` و`+967…` و`00967…` قيمٌ مختلفةٌ
 *      في العمود، فالتحقّقُ الحرفيُّ لا يراها مكرّرة. والتوحيدُ مبنيٌّ
 *      في `RegisterController` (‏`Phone::canonical`)، **والقيدُ يحرس ما
 *      وراءه.**
 *
 * **ولا تُطبَّق على قاعدةٍ فيها تكرارٌ قائم.** هجرةٌ تسقط في منتصفها
 * تترك الخادمَ في حالةٍ لا تُقرأ — فيُفحَص أوّلاً، ويُقال السببُ صراحةً
 * ولا يُسكَت عنه. (وقِيس قبل الكتابة: صفرُ تكراراتٍ وصفرُ هواتفَ فارغة.)
 */
return new class extends Migration
{
    private const INDEX = 'users_phone_unique_idx';

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'phone')) {
            return;
        }

        if ($this->hasIndex()) {
            return;
        }

        // **الفحصُ قبل القيد** — وهجرةٌ تسقط في منتصفها أسوأ من غيابها.
        $duplicates = DB::table('users')
            ->select('phone')
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->groupBy('phone')->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        if ($duplicates->isNotEmpty()) {
            // ولا تُرمى استثناءً يُوقف النشرَ كلَّه: يُسجَّل ويُتخطّى،
            // فالمنعُ في التطبيق قائمٌ ولم يزل، **والصمتُ هو المرفوض**.
            \Illuminate\Support\Facades\Log::warning(
                'AMIAL-IDENTITY-UNIQUE-001: لم يُضَف قيدُ تفرُّد الهاتف — '
                .'أرقامٌ مكرّرةٌ قائمة: '.$duplicates->implode('، ')
                .'. وحّدها ثمّ أعِد `php artisan migrate`.');

            return;
        }

        Schema::table('users', function ($table) {
            $table->unique('phone', self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && $this->hasIndex()) {
            Schema::table('users', function ($table) {
                $table->dropUnique(self::INDEX);
            });
        }
    }

    private function hasIndex(): bool
    {
        return collect(DB::select('SHOW INDEX FROM users'))
            ->contains(fn ($i) => $i->Key_name === self::INDEX);
    }
};
