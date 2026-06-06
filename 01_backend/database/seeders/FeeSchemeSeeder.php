<?php

namespace Database\Seeders;

use App\Models\FeeScheme;
use Illuminate\Database\Seeder;

/**
 * AMIAL-FEE-ENGINE-001
 *
 * نسخ رسوم افتراضية (version 1) مبنية على استراتيجية الربح:
 *   - التحويل بين الأفراد: رسم منخفض جداً لتشجيع الانتشار.
 *   - السحب النقدي: عمولة ثابتة، جزء منها للوكيل.
 *   - الإيداع (cash-in): مجاني للعميل (ندعمه ليدخل المال للنظام)،
 *     لكن للوكيل عمولة تحفيزية (يتحمّلها النظام، bearer=receiver=0 رسم على العميل).
 *   - دفع التاجر (QR/POS): نسبة استقطاع (take-rate) — أهم مصدر ربح، يتحملها التاجر.
 *   - الدفع الآمن: نسبة أعلى (خدمة حماية مميزة).
 *   - الفواتير: رسم رمزي + عمولة المزود تأتي منفصلة.
 *   - الاسترجاع وتقسيم الفاتورة والصندوق العائلي: بلا رسم في v1.
 *
 * كل الأرقام placeholders للسوق الجنوبي (SOUTH) — تضبطها الإدارة من لوحة التحكم.
 * يستخدم updateOrCreate حتى لا يكرّر عند إعادة التشغيل.
 */
class FeeSchemeSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // [code, label, fee_type, percent, fixed, min, max, agent%, agent_fixed, applies_to, bearer]
            ['SEND_MONEY',        'تحويل بين الأفراد', 'percent_plus_fixed', '0.5000', '0',    null,  '500', '0',      '0', 'customer', 'sender'],
            ['CASH_OUT',          'سحب نقدي',          'percent_plus_fixed', '0.7500', '0',    '50',  null,  '40.0000','0', 'customer', 'sender'],
            ['CASH_IN',           'إيداع نقدي',        'fixed',              '0',      '0',    null,  null,  '0',      '0', 'customer', 'sender'],
            ['MERCHANT_QR',       'دفع تاجر QR',       'percent',            '1.0000', '0',    null,  null,  '0',      '0', 'merchant', 'merchant'],
            ['MERCHANT_POS',      'دفع تاجر POS',      'percent',            '1.0000', '0',    null,  null,  '0',      '0', 'merchant', 'merchant'],
            ['SAFE_PAYMENT',      'الدفع الآمن',       'percent',            '1.5000', '0',    '100', null,  '0',      '0', 'customer', 'sender'],
            ['BILL_PAY',          'تسديد فاتورة',      'fixed',              '0',      '10',   null,  null,  '0',      '0', 'customer', 'sender'],
            ['SPLIT_BILL',        'تقسيم فاتورة',      'percent',            '0',      '0',    null,  null,  '0',      '0', 'customer', 'sender'],
            ['REFUND',            'استرجاع مبلغ',      'fixed',              '0',      '0',    null,  null,  '0',      '0', 'merchant', 'merchant'],
            ['FAMILY_FUND_CONTRIB','مساهمة صندوق عائلي','fixed',             '0',      '0',    null,  null,  '0',      '0', 'customer', 'sender'],
        ];

        foreach ($defaults as $row) {
            [$code, $label, $type, $percent, $fixed, $min, $max, $agentP, $agentF, $appliesTo, $bearer] = $row;

            FeeScheme::updateOrCreate(
                ['code' => $code, 'zone_code' => 'SOUTH', 'applies_to' => $appliesTo, 'version' => 1],
                [
                    'label' => $label,
                    'fee_type' => $type,
                    'percent_rate' => $percent,
                    'fixed_amount' => $fixed,
                    'min_fee' => $min,
                    'max_fee' => $max,
                    'agent_commission_percent' => $agentP,
                    'agent_commission_fixed' => $agentF,
                    'bearer' => $bearer,
                    'is_active' => true,
                    'effective_from' => now(),
                    'notes' => 'Seeded default (v1) — adjust from admin panel.',
                ]
            );
        }
    }
}
