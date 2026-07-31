<?php

namespace Database\Seeders;

use App\Models\Aml\AmlRule;
use Illuminate\Database\Seeder;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * بذور قواعد AML الافتراضية. القيم موضوعة بناءً على افتراضات للسوق اليمني،
 * يمكن للإدارة تعديلها بعد التشغيل.
 */
class AmlDefaultRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // ==================== Hard limits (block) ====================
            [
                'code' => 'MAX_SINGLE_TX_HARD',
                'name_ar' => 'الحد الأقصى للمعاملة الواحدة (صلب)',
                'description_ar' => 'يرفض أي معاملة تتجاوز 50,000 ر.س مهما كانت',
                'rule_type' => 'max_single_transaction',
                'applies_to' => 'send_money,safe_payment,donation,bill_pay',
                'parameters' => ['threshold_amount' => '50000'],
                'action_on_match' => 'block',
                'risk_score_contribution' => 100,
                'priority' => 10,
                // AMIAL-AML-ENFORCE-001: هذه وحدها تُنفَّذ من أوّل يوم.
                //
                // «الإطلاق الآمن» (observe ← enforce) صحيحٌ للقواعد التي قد
                // تُخطئ: السرعة والتوقيت والحساب الجديد كلّها تقديرات، وإيقافها
                // بريئاً أسوأ من تمريرها. أمّا الحدّ الأقصى المطلق فليس تقديراً
                // بل سياسة: «لا تحويل يتجاوز 50,000 مهما كان».
                //
                // وإبقاؤها في الظلّ يعني أن المنصّة **بلا حدٍّ أقصى إطلاقاً** —
                // فتمرّ معاملةٌ بمليون وتُسجَّل في aml_shadow_decisions أنها
                // «كانت ستُمنع». وهذا أسوأ من عدم وجود القاعدة: يظنّ المشغّل
                // أن الحدّ قائم لأنه يراه في الشاشة.
                'shadow_mode' => false,
            ],

            // ==================== Hold (للمراجعة) ====================
            [
                'code' => 'MAX_SINGLE_TX_SOFT',
                'name_ar' => 'مبلغ كبير يحتاج مراجعة',
                'description_ar' => 'معاملة > 10,000 ر.س تنتظر مراجعة الإدارة',
                'rule_type' => 'max_single_transaction',
                'applies_to' => 'send_money,safe_payment',
                'parameters' => ['threshold_amount' => '10000'],
                'action_on_match' => 'hold',
                'risk_score_contribution' => 40,
                'priority' => 20,
            ],

            // ==================== Velocity ====================
            [
                'code' => 'VELOCITY_5MIN',
                'name_ar' => 'سرعة معاملات مفرطة',
                'description_ar' => 'أكثر من 5 معاملات في 5 دقائق = نشاط آلي محتمل',
                'rule_type' => 'velocity',
                'applies_to' => 'send_money,safe_payment,bill_pay,donation',
                'parameters' => ['max_count' => 5, 'window_minutes' => 5],
                'action_on_match' => 'hold',
                'risk_score_contribution' => 50,
                'priority' => 30,
            ],
            [
                'code' => 'VELOCITY_1HOUR',
                'name_ar' => 'سرعة معاملات في ساعة',
                'description_ar' => 'أكثر من 20 معاملة في ساعة = flag للمراجعة',
                'rule_type' => 'velocity',
                'applies_to' => 'send_money,safe_payment,bill_pay,donation',
                'parameters' => ['max_count' => 20, 'window_minutes' => 60],
                'action_on_match' => 'flag',
                'risk_score_contribution' => 25,
                'priority' => 40,
            ],

            // ==================== Daily aggregate ====================
            [
                'code' => 'DAILY_AGGREGATE_HIGH',
                'name_ar' => 'مجموع يومي عالي',
                'description_ar' => 'مجموع تحويلات اليوم > 30,000 ر.س',
                'rule_type' => 'daily_aggregate',
                'applies_to' => 'send_money,safe_payment',
                'parameters' => ['threshold_amount' => '30000'],
                'action_on_match' => 'flag',
                'risk_score_contribution' => 30,
                'priority' => 50,
            ],
            [
                'code' => 'DAILY_AGGREGATE_VERY_HIGH',
                'name_ar' => 'مجموع يومي مرتفع جداً',
                'description_ar' => 'مجموع تحويلات اليوم > 100,000 ر.س = hold',
                'rule_type' => 'daily_aggregate',
                'applies_to' => 'send_money,safe_payment',
                'parameters' => ['threshold_amount' => '100000'],
                'action_on_match' => 'hold',
                'risk_score_contribution' => 60,
                'priority' => 50,
            ],

            // ==================== Off hours ====================
            [
                'code' => 'OFF_HOURS_LARGE',
                'name_ar' => 'معاملة كبيرة في ساعات غير عادية',
                'description_ar' => 'معاملة > 5,000 ر.س بين 2-5 صباحاً',
                'rule_type' => 'off_hours',
                'applies_to' => 'send_money,safe_payment',
                'parameters' => [
                    'start_hour' => 2,
                    'end_hour' => 5,
                    'min_amount' => '5000',
                ],
                'action_on_match' => 'flag',
                'risk_score_contribution' => 30,
                'priority' => 60,
            ],

            // ==================== New account ====================
            [
                'code' => 'NEW_ACCOUNT_HIGH_VALUE',
                'name_ar' => 'حساب جديد + معاملة كبيرة',
                'description_ar' => 'حساب < 7 أيام + معاملة > 3,000 ر.س',
                'rule_type' => 'new_account_high_value',
                'applies_to' => 'send_money,safe_payment',
                'parameters' => [
                    'max_account_age_days' => 7,
                    'min_amount' => '3000',
                ],
                'action_on_match' => 'hold',
                'risk_score_contribution' => 50,
                'priority' => 70,
            ],

            // ==================== Structuring (AML الأهم) ====================
            [
                'code' => 'STRUCTURING_CLASSIC',
                'name_ar' => 'تقسيم مشبوه (Smurfing)',
                'description_ar' => '5+ معاملات قريبة من 9,000 ر.س خلال 24 ساعة',
                'rule_type' => 'structuring',
                'applies_to' => 'send_money,safe_payment',
                'parameters' => [
                    'threshold_amount' => '9000',
                    'window_hours' => 24,
                    'min_count' => 5,
                ],
                'action_on_match' => 'hold',
                'risk_score_contribution' => 70,
                'priority' => 80,
            ],

            // ==================== AMIAL-AML-002 (v2.5): قواعد سلوكية جديدة ====================
            // تبدأ كلها في shadow_mode = true (مراقبة دون إيقاف) لضبطها قبل التفعيل
            [
                'code' => 'CIRCULAR_TRANSFER',
                'name_ar' => 'حوالة دائرية',
                'description_ar' => 'A→B→A أو A→B→C→A خلال 24 ساعة (غسل أموال محتمل)',
                'rule_type' => 'circular_transfer',
                'applies_to' => 'send_money',
                'parameters' => [
                    'window_hours' => 24,
                    'min_cycle_amount' => '5000',
                ],
                'action_on_match' => 'hold',
                'risk_score_contribution' => 55,
                'priority' => 75,
                'shadow_mode' => true,
            ],
            [
                'code' => 'AGENT_VELOCITY_IDENTICAL',
                'name_ar' => 'نشاط وكيل غير طبيعي',
                'description_ar' => '5+ عمليات cash-in بمبالغ متطابقة خلال 10 دقائق',
                'rule_type' => 'agent_velocity',
                'applies_to' => 'agent_cash_in',
                'parameters' => [
                    'window_minutes' => 10,
                    'max_identical_count' => 5,
                    'max_distinct_customers' => 15,
                ],
                'action_on_match' => 'flag',
                'risk_score_contribution' => 50,
                'priority' => 65,
                'shadow_mode' => true,
            ],
        ];

        // AMIAL-AUDIT-FIX-001: إطلاق مصرفي آمن (observe ← enforce).
        // كل القواعد تبدأ في shadow_mode=true: المحرّك يراقب ويسجّل في
        // aml_shadow_decisions دون إيقاف أي معاملة. بعد مراجعة أسابيع من
        // البيانات، يُفعّل الأدمن الإنفاذ لكل قاعدة بإيقاف shadow_mode.
        // AMIAL-AML-ENFORCE-001: يُحترم إعلان القاعدة لنفسها.
        //
        // كان السطر: array_merge(['shadow_mode'=>true], $r, ['shadow_mode'=>true])
        // فيكتب الظلّ **بعد** $r ويُلغي أي إعلانٍ فيها. أي أن كتابة
        // 'shadow_mode' => false في تعريف قاعدةٍ كانت بلا أثر، صامتةً.
        //
        // ولا يُلمَس ما غيّره المشغّل يدوياً: `updateOrCreate` يعيد الكتابة على
        // الصفّ القائم، فمن أطفأ الظلّ عن قاعدةٍ بعد مراجعة بياناته يجد قراره
        // مُلغى عند أوّل إعادة بذر. فالظلّ يُكتب عند الإنشاء فقط.
        $enforced = 0;
        foreach ($rules as $r) {
            $shadow = $r['shadow_mode'] ?? true;
            unset($r['shadow_mode']);

            $existing = AmlRule::where('code', $r['code'])->first();

            AmlRule::updateOrCreate(
                ['code' => $r['code']],
                array_merge(
                    $r,
                    ['is_active' => true],
                    // قرار المشغّل يبقى؛ والافتراض للجديدة فقط.
                    $existing ? [] : ['shadow_mode' => $shadow],
                ),
            );

            if (!$shadow) $enforced++;
        }

        $this->command->info('✓ Seeded ' . count($rules) . " AML rules ({$enforced} تُنفَّذ فعلياً، والباقي مراقبة)");
        $this->command->info('  الحدّ الأقصى المطلق يُنفَّذ من أوّل يوم — سياسةٌ لا تقدير.');
        $this->command->info('  والباقي يراقب ويسجّل؛ فعّله بـ php artisan amial:aml-enforce');
    }
}
