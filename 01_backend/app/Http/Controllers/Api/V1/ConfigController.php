<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Currency;
use App\CentralLogics\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Schema;

class ConfigController extends Controller
{
    public function configuration(): JsonResponse
    {
        $currencySymbol = Currency::where(['currency_code' => Helpers::currency_code()])->first();

        // AMIAL-FIX(DEPLOY): تحصين ضدّ غياب إعداد اللغة (كان foreach على null يُسقط
        // /api/v1/config بـ500 على تثبيت جديد بلا بذر لغة).
        $languageCode = null;
        $languages = Helpers::get_business_settings('language');
        foreach ((is_array($languages) ? $languages : []) as $language) {
            if (!empty($language['default'])) {
                $languageCode = $language['code'] ?? null;
            }
        }

        // AMIAL-CLEANUP: بوّابات الدفع الخارجية (paypal/stripe/paystack…) حُذفت —
        // أميال باي نظام محفظة داخلي + شبكة وكلاء. القائمة تبقى فارغة (التطبيق
        // يتوقّع المفتاح) ويمكن ملؤها لاحقاً إن رُبطت بوّابة محلّية.
        $paymentGateways = [];

        return response()->json([
            'company_name' => Helpers::get_business_settings('business_name'),
            'company_logo' => Helpers::get_business_settings('logo'),
            'company_address' => Helpers::get_business_settings('address'),
            'company_phone' => (string)Helpers::get_business_settings('phone'),
            'company_email' => Helpers::get_business_settings('email'),
            'base_urls' => [
                'customer_image_url' => dynamicStorage(path: 'storage/app/public/customer'),
                'agent_image_url' => dynamicStorage(path: 'storage/app/public/agent'),
                'linked_website_image_url' => dynamicStorage(path: 'storage/app/public/website'),
                'purpose_image_url' => dynamicStorage(path: 'storage/app/public/purpose'),
                'notification_image_url' => dynamicStorage(path: 'storage/app/public/notification'),
                'company_image_url' => dynamicStorage(path: 'storage/app/public/business'),
                'banner_image_url' => dynamicStorage(path: 'storage/app/public/banner'),
                'gateway_image_url' => dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image'),
            ],
            'currency_symbol' => $currencySymbol?->currency_symbol,
            'currency_position' => Helpers::get_business_settings('currency_symbol_position') ?? 'right',

            'cashout_charge_percent' => (float) Helpers::get_business_settings('cashout_charge_percent'),
            'sendmoney_charge_flat' => (float) Helpers::get_business_settings('sendmoney_charge_flat'),
            'agent_commission_percent' => (float) Helpers::get_business_settings('agent_commission_percent'),
            'withdraw_charge_percent' => (float) Helpers::get_business_settings('withdraw_charge_percent'),
            'admin_commission' => (float) Helpers::get_business_settings('admin_commission'),
            'two_factor' => (integer) Helpers::get_business_settings('two_factor'),
            'country' => Helpers::get_business_settings('country') ?? 'BD',

            'terms_and_conditions' => Helpers::get_business_settings('terms_and_conditions'),
            'privacy_policy' => Helpers::get_business_settings('privacy_policy'),
            'about_us' => Helpers::get_business_settings('about_us'),
            'phone_verification' => (int)Helpers::get_business_settings('phone_verification'),
            'email_verification' => (int)Helpers::get_business_settings('email_verification'),
            'user_app_theme' => (string) Helpers::get_business_settings('app_theme'),
            'software_version' => (string)env('SOFTWARE_VERSION')??null,
            'language_code' => (string)$languageCode,
            'active_payment_method_list' => $paymentGateways,
            'otp_resend_time' => Helpers::get_business_settings('otp_resend_time') ?? 60,
            'agent_self_registration' => Helpers::get_business_settings('agent_self_registration') ?? 1,
            'customer_self_delete' => Helpers::get_business_settings('customer_self_delete') ?? 0,
            'agent_self_delete' => Helpers::get_business_settings('agent_self_delete') ?? 0,
            'system_feature' => [
                // AMIAL-FIX-GATEWAYS — الافتراضي معطّل: بوّابات الدفع
                // الخارجية حُذفت (الوكلاء هم قناة الشحن الوحيدة في اليمن).
                // قابل للتفعيل لاحقاً من لوحة الإدارة إن رُبطت بوّابة محلّية.
                'add_money_status' => Helpers::get_business_settings('add_money_status') ?? 0,
                'send_money_status' => Helpers::get_business_settings('send_money_status') ?? 1,
                'cash_out_status' => Helpers::get_business_settings('cash_out_status') ?? 1,
                'send_money_request_status' => Helpers::get_business_settings('send_money_request_status') ?? 1,
                'withdraw_request_status' => Helpers::get_business_settings('withdraw_request_status') ?? 1,
                'linked_website_status' => Helpers::get_business_settings('linked_website_status') ?? 1,
                'banner_status' => Helpers::get_business_settings('banner_status') ?? 1,
                'faq_section_status' => Helpers::get_business_settings('faq_section_status') ?? 0,
            ],
            // AMIAL-FIX: كانت تُرجع null إن لم تُضبط → تُفشِل تحليل التطبيق
            // (CustomerLimit.fromJson(null)) → configModel=null → شاشات رمادية.
            // الآن تُرجع بنية كاملة دائماً.
            'customer_add_money_limit' => $this->limitOrDefault('customer_add_money_limit'),
            'customer_send_money_limit' => $this->limitOrDefault('customer_send_money_limit'),
            'customer_send_money_request_limit' => $this->limitOrDefault('customer_send_money_request_limit'),
            'customer_cash_out_limit' => $this->limitOrDefault('customer_cash_out_limit'),
            'customer_withdraw_request_limit' => $this->limitOrDefault('customer_withdraw_request_limit'),
            'agent_add_money_limit' => $this->limitOrDefault('agent_add_money_limit'),
            'agent_send_money_limit' => $this->limitOrDefault('agent_send_money_limit'),
            'agent_send_money_request_limit' => $this->limitOrDefault('agent_send_money_request_limit'),
            'agent_withdraw_request_limit' => $this->limitOrDefault('agent_withdraw_request_limit'),
            'favorite_number_status' =>  Helpers::get_business_settings('favorite_number_status') ?? 0,
            'favorite_number_limit' =>  Helpers::get_business_settings('favorite_number_limit') ?? 0,
            'favorite_number_cash_out_charge_discount' =>  Helpers::get_business_settings('favorite_number_cash_out_charge_discount') ?? 0,
            'favorite_number_send_money_charge_discount' =>  Helpers::get_business_settings('favorite_number_send_money_charge_discount') ?? 0,
            'report_disputes_status' =>  Helpers::get_business_settings('report_disputes_status') ?? 0,
            'report_dispute_time' =>  Helpers::get_business_settings('report_dispute_time') ?? 0,
            'report_dispute_time_type' =>  Helpers::get_business_settings('report_dispute_time_type') ?? 'day',
            'max_image_upload_size' => uploadMaxFileSize('image'),
        ]);
    }

    // AMIAL-CLEANUP: أُزيلت getPaymentMethods() — كانت تقرأ إعدادات بوّابات الدفع
    // (payment_config) من addon_settings؛ لا بوّابات خارجية في أميال باي.

    /**
     * AMIAL-FIX: يُرجع بنية حدّ معاملات كاملة دائماً (لا null أبداً) بالحقول
     * التي يتوقّعها التطبيق (CustomerLimit.fromJson) — status + الحدود الرقمية.
     * إن كان الإعداد مضبوطاً وصالحاً يُستخدم، وإلا يُرجَع افتراضي سخيّ.
     */
    private function limitOrDefault(string $key): array
    {
        $default = [
            'status' => 1,
            'transaction_limit_per_day' => 100,
            'max_amount_per_transaction' => 1000000,
            'total_transaction_amount_per_day' => 10000000,
            'transaction_limit_per_month' => 3000,
            'total_transaction_amount_per_month' => 300000000,
        ];

        $value = Helpers::get_business_settings($key);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($value)) {
            return $default;
        }

        // نضمن وجود كل مفتاح متوقّع (نملأ الناقص من الافتراضي)
        return array_merge($default, $value);
    }
}
