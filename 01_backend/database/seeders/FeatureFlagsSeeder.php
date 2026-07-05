<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

/**
 * AMIAL-MAINT-001 — كتالوج ميزات «الصيانة الأولية».
 *
 * كل ميزة تبدأ مفعّلة. money_source يربط الميزة بحاسبة تراكمها المالي
 * (الميزات المالية فقط)؛ البقية بلا أموال فتُغلق بحرّية.
 */
class FeatureFlagsSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            // ===== مالية (لها تراكم مالي — لا تُغلق إلا عند صفر) =====
            ['safe_payment', 'الدفع الآمن', 'حجز المبلغ حتى تأكيد الاستلام', 'financial', 'safe_payment', true],
            ['family_fund', 'الصندوق العائلي', 'صناديق ادّخار ومساهمات عائلية', 'financial', 'family_fund', false],
            ['donations', 'التبرّعات', 'حملات وتبرّعات خيرية', 'financial', 'donations', false],
            ['bill_pay', 'دفع الفواتير', 'كهرباء/ماء/اتصالات عبر مزوّدين', 'financial', 'bill_pay', false],
            ['send_money', 'تحويل الأموال', 'التحويل بين المحافظ', 'financial', 'pending_transfers', true],

            // ===== مالية بلا احتجاز (فورية — تراكمها صفر دائماً) =====
            ['cash_out', 'السحب النقدي', 'سحب عبر الوكلاء', 'financial', null, true],
            ['cash_in', 'الإيداع النقدي', 'شحن الرصيد عبر الوكلاء', 'financial', null, true],
            ['merchant_payment', 'الدفع للتاجر', 'دفع QR/POS للتجار', 'merchant', null, false],
            ['split_bill', 'تقسيم الفاتورة', 'تقسيم فاتورة بين عدّة أشخاص', 'merchant', null, false],

            // ===== تجارية (لا أموال منصّة — تُغلق بحرّية) =====
            ['cashier', 'الكاشير (نقطة البيع)', 'مبيعات التجّار وإدارة المنتجات', 'merchant', null, false],
            ['credit_notebook', 'دفتر الديون', 'ديون عملاء التاجر', 'merchant', null, false],
            ['wholesale', 'قطاع الجملة', 'كتالوج ومبيعات الجملة', 'merchant', null, false],
            ['pharmacy', 'قطاع الصيدليات', 'كتالوج وصرف الأدوية', 'merchant', null, false],
            ['fuel_station', 'محطات الوقود', 'مبيعات ومناوبات الوقود', 'merchant', null, false],
            ['barcode_scan', 'مسح الباركود', 'المسح المستمر للمنتجات', 'merchant', null, false],

            // ===== قنوات وأدوات (لا أموال) =====
            ['whatsapp_bot', 'بوت واتساب', 'التحويل والاستعلام عبر واتساب', 'channel', null, false],
            ['receipts', 'الإيصالات والفواتير', 'إصدار وطباعة الإيصالات', 'general', null, false],
            ['thermal_print', 'الطباعة الحرارية', 'طباعة إيصالات 58/80مم', 'general', null, false],
        ];

        foreach ($features as [$key, $name, $desc, $cat, $money, $core]) {
            FeatureFlag::updateOrCreate(
                ['key' => $key],
                [
                    'name_ar' => $name, 'description_ar' => $desc, 'category' => $cat,
                    'money_source' => $money, 'is_core' => $core,
                    // لا نلمس enabled إن كانت الميزة موجودة (نحترم قرار الأدمن السابق)
                ] + (FeatureFlag::where('key', $key)->exists() ? [] : ['enabled' => true])
            );
        }

        if (isset($this->command)) {
            $this->command->info('✓ Seeded ' . count($features) . ' feature flags (كلها مفعّلة).');
        }
    }
}
