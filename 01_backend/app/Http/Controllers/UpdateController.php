<?php

namespace App\Http\Controllers;

ini_set('max_execution_time', 180);

use App\Models\FAQ;
use App\Models\HelpTopic;
use App\Models\Setting;
use App\Traits\AddonHelper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Mockery\Exception;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Traits\ActivationClass;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class UpdateController extends Controller
{
    use ActivationClass, AddonHelper;

    public function updateSoftwareIndex(): View
    {
        return view('update.update-software');
    }

    public function updateSoftware(Request $request): RedirectResponse
    {
        Helpers::setEnvironmentValue('SOFTWARE_ID', 'MzczNTQxNDc=');
        Helpers::setEnvironmentValue('BUYER_USERNAME', $request['username']);
        Helpers::setEnvironmentValue('PURCHASE_CODE', $request['purchase_key']);
        Helpers::setEnvironmentValue('APP_MODE', 'live');
        Helpers::setEnvironmentValue('SOFTWARE_VERSION', '4.8');
        Helpers::setEnvironmentValue('APP_NAME', '6cash' . time());

        $data = $this->actch();
        try {
            if (!$data->getData()->active) {
                return redirect(base64_decode('aHR0cHM6Ly82YW10ZWNoLmNvbS9zb2Z0d2FyZS1hY3RpdmF0aW9u'));
            }
        } catch (Exception $exception) {
            Toastr::error('verification failed! try again');
            return back();
        }

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('storage:link');
        $this->checkSystemAddonsSymbolicLink();
        $previousRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.php');
        $newRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.txt');
        copy($newRouteServiceProvider, $previousRouteServiceProvider);
        Artisan::call('optimize:clear');
        if (!BusinessSetting::where(['key' => 'payment_otp_verification'])->first()) {
            BusinessSetting::insert([
                'key' => 'payment_otp_verification',
                'value' => 1
            ]);
        }
        if (!BusinessSetting::where(['key' => 'hotline_number'])->first()) {
            BusinessSetting::insert([
                'key' => 'hotline_number',
                'value' => '134679'
            ]);
        }
        if (!BusinessSetting::where(['key' => 'merchant_commission_percent'])->first()) {
            BusinessSetting::insert([
                'key' => 'merchant_commission_percent',
                'value' => 10
            ]);
        }
        if (!BusinessSetting::where(['key' => 'payment'])->first()) {
            BusinessSetting::insert([
                'key' => 'payment',
                'value' => '{"status":1,"message":"payment done successfully."}'
            ]);
        }
        if (!BusinessSetting::where(['key' => 'withdraw_charge_percent'])->first()) {
            BusinessSetting::insert([
                'key' => 'withdraw_charge_percent',
                'value' => 5
            ]);
        }
        if (!BusinessSetting::where(['key' => 'add_money_bonus'])->first()) {
            BusinessSetting::insert([
                'key' => 'add_money_bonus',
                'value' => '{"status":1,"message":"Added to your account with bonus."}'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'agent_self_registration'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_self_registration'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'maximum_otp_hit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'maximum_otp_hit'], [
                'value' => 5
            ]);
        }

        if (!BusinessSetting::where(['key' => 'otp_resend_time'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'otp_resend_time'], [
                'value' => 60
            ]);
        }

        if (!BusinessSetting::where(['key' => 'temporary_block_time'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'temporary_block_time'], [
                'value' => 600
            ]);
        }

        if (!BusinessSetting::where(['key' => 'maximum_login_hit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'maximum_login_hit'], [
                'value' => 5
            ]);
        }

        if (!BusinessSetting::where(['key' => 'temporary_login_block_time'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'temporary_login_block_time'], [
                'value' => 600
            ]);
        }

        if (!BusinessSetting::where(['key' => 'add_money_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'add_money_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'send_money_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'send_money_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'cash_out_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'cash_out_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'send_money_request_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'send_money_request_status'], [
                'value' => 1
            ]);
        }
        if (!BusinessSetting::where(['key' => 'withdraw_request_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'withdraw_request_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'linked_website_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'linked_website_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'banner_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'banner_status'], [
                'value' => 1
            ]);
        }

        //for 4.1
        if (!BusinessSetting::where(['key' => 'agent_self_delete'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_self_delete'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'customer_self_delete'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_self_delete'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_intro_section_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_intro_section_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'intro_section'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'intro_section'], [
                'value' => '{"title":"","description":"","download_link":"","button_name":"","intro_left_image":"","intro_middle_image":"","intro_right_image":""}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'user_rating_with_total_user_section'])->first()) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'user_rating_with_total_user_section'],
                [
                    'value' => '{"reviewer_name":"","rating":"","total_user_count":0,"total_user_content":"","review_user_icon":"","user_image_one":"","user_image_two":"","user_image_three":""}',
                ]
            );
        }

        if (!BusinessSetting::where(['key' => 'landing_feature_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_feature_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_feature_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_feature_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_screenshots_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_screenshots_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_why_choose_us_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_why_choose_us_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_why_choose_us_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_why_choose_us_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_agent_registration_section_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_agent_registration_section_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'agent_registration_section'])->first()) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'agent_registration_section'],
                [
                    'value' => '{"title":"","banner":""}',
                ]
            );
        }

        if (!BusinessSetting::where(['key' => 'landing_how_it_works_section_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_how_it_works_section_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_how_it_works_section_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_how_it_works_section_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_download_section_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_download_section_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'download_section'])->first()) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'download_section'],
                [
                    'value' => '{"title":"","sub_title":"","play_store_link":"","app_store_link":"","image":""}',
                ]
            );
        }

        if (!BusinessSetting::where(['key' => 'landing_business_statistics_status'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_business_statistics_status'], [
                'value' => 1
            ]);
        }

        if (!BusinessSetting::where(['key' => 'business_statistics_download'])->first()) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'business_statistics_download'],
                [
                    'value' => '{"download_count":"","review_count":"","title":"","sub_title":"","country_count":"","download_sort_description":"","review_sort_description":"","country_sort_description":"","download_icon":"","review_icon":"","country_icon":""}',
                ]
            );
        }

        if (!BusinessSetting::where(['key' => 'contact_us_section'])->first()) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'contact_us_section'],
                [
                    'value' => '{"title":"","sub_title":""}',
                ]
            );
        }

        if (!BusinessSetting::where(['key' => 'about_us_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'about_us_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'about_us_sub_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'about_us_sub_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'about_us_image'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'about_us_image'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'privacy_policy_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'privacy_policy_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'privacy_policy_sub_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'privacy_policy_sub_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'terms_and_conditions_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'terms_and_conditions_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'terms_and_conditions_sub_title'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'terms_and_conditions_sub_title'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'business_short_description'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'business_short_description'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'landing_page_logo'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'landing_page_logo'], [
                'value' => ""
            ]);
        }

        if (!BusinessSetting::where(['key' => 'mail_config'])->first()) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'mail_config'],
                [
                    'value' => '{"status":"0","name":"","host":"","driver":"","port":"","username":"","email_id":"","encryption":"","password":""}',
                ]
            );
        }

        if (!BusinessSetting::where(['key' => 'customer_add_money_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_add_money_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'customer_send_money_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_send_money_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'customer_send_money_request_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_send_money_request_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'customer_cash_out_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_cash_out_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'customer_withdraw_request_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_withdraw_request_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'agent_add_money_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_add_money_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'agent_send_money_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_send_money_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'agent_send_money_request_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_send_money_request_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        if (!BusinessSetting::where(['key' => 'agent_withdraw_request_limit'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_withdraw_request_limit'], [
                'value' => '{"status":0,"transaction_limit_per_day":3,"max_amount_per_transaction":10,"total_transaction_amount_per_day":20,"transaction_limit_per_month":5,"total_transaction_amount_per_month":50}',
            ]);
        }

        //version 4.3
        if (!BusinessSetting::where(['key' => 'push_notification_service_file_content'])->first()) {
            DB::table('business_settings')->updateOrInsert(['key' => 'push_notification_service_file_content'], [
                'value' => ''
            ]);
        }

        //version 4.4
        $this->insertNewAddonData();

        //version 4.5
        $this->updatedV4_5();

        return redirect(env('APP_URL'));
    }

    private function insertNewAddonData(): void
    {
        $gatewayKeys = ['instamojo', 'phonepe', 'cashfree'];
        $existingGateways = Setting::whereIn('key_name', $gatewayKeys)->pluck('key_name')->toArray();

        $newGateways = [
            [
                'id' => '42a8cad7-6736-11ee-909d-0c7a158e4469',
                'key_name' => 'instamojo',
                'live_values' => json_encode([
                    'gateway' => 'instamojo',
                    'mode' => 'live',
                    'status' => 0,
                    'client_id' => '',
                    'client_secret' => ''
                ]),
                'test_values' => json_encode([
                    'gateway' => 'instamojo',
                    'mode' => 'test',
                    'status' => 0,
                    'client_id' => '',
                    'client_secret' => ''
                ]),
                'settings_type' => 'payment_config',
                'mode' => 'test',
                'is_active' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'additional_data' => null,
            ],
            [
                'id' => 'a40991e4-6735-11ee-909d-0c7a158e4469',
                'key_name' => 'phonepe',
                'live_values' => json_encode([
                    'gateway' => 'phonepe',
                    'mode' => 'live',
                    'status' => 0,
                    'merchant_id' => '',
                    'salt_Key' => '',
                    'salt_index' => ''
                ]),
                'test_values' => json_encode([
                    'gateway' => 'phonepe',
                    'mode' => 'test',
                    'status' => 0,
                    'merchant_id' => '',
                    'salt_Key' => '',
                    'salt_index' => ''
                ]),
                'settings_type' => 'payment_config',
                'mode' => 'test',
                'is_active' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'additional_data' => null,
            ],
            [
                'id' => 'cc90e5f2-6735-11ee-909d-0c7a158e4469',
                'key_name' => 'cashfree',
                'live_values' => json_encode([
                    'gateway' => 'cashfree',
                    'mode' => 'live',
                    'status' => 0,
                    'client_id' => '',
                    'client_secret' => ''
                ]),
                'test_values' => json_encode([
                    'gateway' => 'cashfree',
                    'mode' => 'test',
                    'status' => 0,
                    'client_id' => '',
                    'client_secret' => ''
                ]),
                'settings_type' => 'payment_config',
                'mode' => 'test',
                'is_active' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'additional_data' => null,
            ],
        ];

        $newGateways = array_filter($newGateways, function ($gateway) use ($existingGateways) {
            return !in_array($gateway['key_name'], $existingGateways);
        });

        if (!empty($newGateways)) {
            Setting::insert($newGateways);
        }
    }

    private function updatedV4_5(): void
    {
        if (!BusinessSetting::where(['key' => 'blog_section_status'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'blog_section_status'], [
                'value' => 0
            ]);
        }

        if (!BusinessSetting::where(['key' => 'blog_intro_title'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'blog_intro_title'], [
                'value' => 'Blog'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'blog_intro_subtitle'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'blog_intro_subtitle'], [
                'value' => 'Stay updated with the latest tips, news, and insights about digital wallets, payments, and financial technology. Explore expert advice, industry trends, and innovations to enhance your digital wallet experience.'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'blog_category_priority_type'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'blog_category_priority_type'], [
                'value' => 'latest'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'blog_priority_type'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'blog_priority_type'], [
                'value' => 'latest'
            ]);
        }


        if (!BusinessSetting::where(['key' => 'faq_section_status'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'faq_section_status'], [
                'value' => 0
            ]);
        }

        if (!BusinessSetting::where(['key' => 'faq_intro_title'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'faq_intro_title'], [
                'value' => 'Frequently Asked Questions (FAQ)'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'faq_intro_subtitle'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'faq_intro_subtitle'], [
                'value' => 'Find quick answers to common questions about our digital wallet, including features, security, payments, and account management'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'faq_category_priority_type'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'faq_category_priority_type'], [
                'value' => 'latest'
            ]);
        }

        if (!BusinessSetting::where(['key' => 'faq_priority_type'])->first()) {
            BusinessSetting::updateOrInsert(['key' => 'faq_priority_type'], [
                'value' => 'latest'
            ]);
        }

        $helpTopics = HelpTopic::all();

        if ($helpTopics->isNotEmpty()) {
            foreach ($helpTopics as $topic) {
                $faq = new FAQ();
                $faq->category_id = null;
                $faq->question = $topic->question;
                $faq->answer = $topic->answer;
                $faq->status = $topic->status;

                if ($faq->save()) {
                    $topic->delete();
                }
            }
        }
    }
}
