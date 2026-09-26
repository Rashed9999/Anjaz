<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PHONE-IDENTITY-001
 *
 * القيد السابق يحمي القيمة الحرفية في `users.phone` فقط؛ لذلك قد يظهر
 * الرقم نفسه بصيغ دولية مختلفة. هذا العمود هو مفتاح هوية الهاتف الموحد.
 *
 * الحسابات التاريخية المتصادمة لا تُعدّل تلقائياً ولا يوقف وجودها النشر:
 * تُترك بلا مفتاح حتى تُراجع، أما كل حساب جديد أو تغيير رقم فيأخذ المفتاح
 * ويخضع للقيد الذرّي في قاعدة البيانات.
 */
return new class extends Migration
{
    private const INDEX = 'users_phone_canonical_unique';

    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'phone_canonical')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_canonical', 24)->nullable()->after('phone');
        });

        $groups = [];
        foreach (DB::table('users')->select('id', 'phone')->whereNotNull('phone')->where('phone', '!=', '')->orderBy('id')->cursor() as $row) {
            $canonical = Phone::canonical((string) $row->phone);
            if ($canonical !== '') {
                $groups[$canonical][] = (int) $row->id;
            }
        }

        $conflicts = 0;
        foreach ($groups as $canonical => $ids) {
            if (count($ids) !== 1) {
                ++$conflicts;
                continue;
            }

            DB::table('users')->where('id', $ids[0])->update(['phone_canonical' => $canonical]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('phone_canonical', self::INDEX);
        });

        if ($conflicts > 0) {
            Log::warning('AMIAL-PHONE-IDENTITY-001: توجد مجموعات هواتف تاريخية متعارضة لم تُفعّل لها هوية الهاتف الموحدة.', [
                'conflict_groups' => $conflicts,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'phone_canonical')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
            $table->dropColumn('phone_canonical');
        });
    }
};
