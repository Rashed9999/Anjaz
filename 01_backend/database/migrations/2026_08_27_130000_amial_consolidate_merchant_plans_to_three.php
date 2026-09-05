<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الكتالوج التجاري أصبح ثلاث باقات فقط:
 *   free (0) -> business (35) -> enterprise (99).
 *
 * لا نعيد كتابة SubscriptionChange لأنّه سجل مالي/تدقيقي تاريخي؛ نغيّر
 * الاشتراك الحيّ وقرارات plan_capabilities فقط. وعند تعارض قرار قديم مع
 * قرار الباقة الهدف، يسبق قرار الباقة الهدف لأنه القرار الذي سيبقى مرئياً.
 */
return new class extends Migration
{
    private const MAP = ['starter' => 'business', 'merchant_pro' => 'enterprise'];

    public function up(): void
    {
        if (Schema::hasTable('merchant_profiles')) {
            foreach (self::MAP as $from => $to) {
                DB::table('merchant_profiles')
                    ->where('subscription_plan', $from)
                    ->update(['subscription_plan' => $to, 'updated_at' => now()]);
            }
        }

        if (Schema::hasTable('plan_capabilities')) {
            foreach (self::MAP as $from => $to) {
                $legacy = DB::table('plan_capabilities')->where('plan', $from)->get();
                foreach ($legacy as $row) {
                    $exists = DB::table('plan_capabilities')
                        ->where('plan', $to)
                        ->where('capability_code', $row->capability_code)
                        ->exists();
                    if (! $exists) {
                        DB::table('plan_capabilities')->where('id', $row->id)->update([
                            'plan' => $to,
                            'updated_at' => now(),
                        ]);
                    }
                }
                DB::table('plan_capabilities')->where('plan', $from)->delete();
            }
        }

        Cache::forget('amial_plans_catalog');
    }

    public function down(): void
    {
        // الرجوع لا يعيد اشتراكات حيّة إلى باقات ملغاة ولا يزوّر السجل التاريخي.
        Cache::forget('amial_plans_catalog');
    }
};
