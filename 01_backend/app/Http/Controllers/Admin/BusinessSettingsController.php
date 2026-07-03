<?php

namespace App\Http\Controllers\Admin;

use App\Mail\TestEmailSender;
use App\Models\Setting;
use App\Traits\UploadSizeHelperTrait;
use Illuminate\Http\Request;
use App\Models\LinkedWebsite;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BusinessSettingsController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private BusinessSetting $businessSetting,
        private LinkedWebsite   $linkedWebsite
    )
    {
    }

    public function businessIndex(): View
    {
        $logo=Helpers::get_business_settings('logo');
        $favicon = Helpers::get_business_settings('favicon');
        $landingPageLogo=Helpers::get_business_settings('landing_page_logo');
        $logo = Helpers::onErrorImage($logo, dynamicStorage(path: 'storage/app/public/business') . '/' . $logo, dynamicAsset(path: 'public/assets/admin/img/160x160/img2.jpg'), 'business/');
        $favicon = Helpers::onErrorImage($favicon, dynamicStorage(path: 'storage/app/public/favicon') . '/' . $favicon, dynamicAsset(path: 'public/assets/admin/img/160x160/img2.jpg'), 'favicon/');
        $landingPageLogo = Helpers::onErrorImage($landingPageLogo, dynamicStorage(path: 'storage/app/public/business') . '/' . $landingPageLogo, dynamicAsset(path: 'public/assets/admin/img/160x160/img2.jpg'), 'business/');

        return view('admin-views.business-settings.business-index', compact('logo', 'landingPageLogo', 'favicon'));
    }

    public function mailConfigIndex(): View
    {
        return view('admin-views.business-settings.mail-config-index');
    }

    public function testMailIndex(): View
    {
        return view('admin-views.business-settings.send-mail-index');
    }

    public function chargeSetupIndex(): View
    {
        return view('admin-views.business-settings.charge-setup-index');
    }

    public function businessSetup(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['logo', 'favicon', 'landing_page_logo']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'logo' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'favicon' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'landing_page_logo' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('update_option_is_disable_for_demo'));
            return back();
        }

        DB::table('business_settings')->updateOrInsert(['key' => 'business_name'], [
            'value' => $request['restaurant_name']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'currency'], [
            'value' => $request['currency']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'pagination_limit'], [
            'value' => $request['pagination_limit']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'timezone'], [
            'value' => $request['timezone']
        ]);

        $currentLogo = $this->businessSetting->where(['key' => 'logo'])->first() ?? '';
        if ($request->has('logo')) {
            $imageName = Helpers::update('business/', $currentLogo->value ?? '', APPLICATION_IMAGE_FORMAT, $request->file('logo'));
        } else {
            $imageName = $currentLogo['value'] ?? '';
        }

        DB::table('business_settings')->updateOrInsert(['key' => 'logo'], [
            'value' => $imageName
        ]);

        $currentLandingLogo = $this->businessSetting->where(['key' => 'landing_page_logo'])->first() ?? '';
        if ($request->has('landing_page_logo')) {
            $landingLogoName = Helpers::update('business/', $currentLandingLogo->value ?? '', APPLICATION_IMAGE_FORMAT, $request->file('landing_page_logo'));
        } else {
            $landingLogoName = $currentLandingLogo['value'] ?? '';
        }

        DB::table('business_settings')->updateOrInsert(['key' => 'landing_page_logo'], [
            'value' => $landingLogoName
        ]);

        $currentFaviconIcon = helpers::get_business_settings('favicon');
        if ($request->has('favicon')) {
            $faviconName = Helpers::update('favicon/', $currentFaviconIcon ?? '', APPLICATION_IMAGE_FORMAT, $request->file('favicon'));
        } else {
            $faviconName = $currentFaviconIcon ?? '';
        }

        DB::table('business_settings')->updateOrInsert(['key' => 'favicon'], [
            'value' => $faviconName
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'phone'], [
            'value' => $request['phone']
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'hotline_number'], [
            'value' => $request['hotline_number']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'email'], [
            'value' => $request['email']
        ]);


        DB::table('business_settings')->updateOrInsert(['key' => 'inactive_auth_minute'], [
            'value' => $request['inactive_auth_minute']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'two_factor'], [
            'value' => $request['two_factor']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'phone_verification'], [
            'value' => $request['phone_verification']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'email_verification'], [
            'value' => $request['email_verification']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'refer_commission'], [
            'value' => $request['refer_commission']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'address'], [
            'value' => $request['address']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'footer_text'], [
            'value' => $request['footer_text']
        ]);


        DB::table('business_settings')->updateOrInsert(['key' => 'currency_symbol_position'], [
            'value' => $request['currency_symbol_position']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'admin_commission'], [
            'value' => $request['admin_commission']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'country'], [
            'value' => $request['country']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'agent_self_registration'], [
            'value' => $request['agent_self_registration']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'customer_self_delete'], [
            'value' => $request['customer_self_delete']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'agent_self_delete'], [
            'value' => $request['agent_self_delete']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'business_short_description'], [
            'value' => $request['business_short_description']
        ]);


        Toastr::success(translate('successfully_updated_to_changes_restart_the_app'));
        return back();
    }

    public function chargeSetupUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'agent_commission_percent'], [
            'value' => $request['agent_commission_percent']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'cashout_charge_percent'], [
            'value' => $request['cashout_charge_percent']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'sendmoney_charge_flat'], [
            'value' => $request['sendmoney_charge_flat']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'withdraw_charge_percent'], [
            'value' => $request['withdraw_charge_percent']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'favorite_number_status'], [
            'value' => $request['favorite_number_status'] == 'on' ? 1 : 0
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'favorite_number_limit'], [
            'value' => $request['favorite_number_limit']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'favorite_number_cash_out_charge_discount'], [
            'value' => $request['favorite_number_cash_out_charge_discount']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'favorite_number_send_money_charge_discount'], [
            'value' => $request['favorite_number_send_money_charge_discount']
        ]);

        Toastr::success(translate('successfully_updated'));
        return back();
    }

    public function paymentIndex(): View
    {
        $publishedStatus = addon_published_status('Gateways');
        $routes = config('addon_admin_routes');
        $desiredName = 'payment_setup';
        $paymentUrl = '';

        foreach ($routes as $routeArray) {
            foreach ($routeArray as $route) {
                if ($route['name'] === $desiredName) {
                    $paymentUrl = $route['url'];
                    break 2;
                }
            }
        }

        //$this->insertNewAddonData();

        $dataValues = Setting::whereIn('settings_type', ['payment_config'])->whereIn('key_name', ['ssl_commerz', 'paypal', 'stripe', 'razor_pay', 'senang_pay', 'paystack', 'paymob_accept', 'flutterwave', 'bkash', 'mercadopago', 'phonepe', 'instamojo', 'cashfree'])->get();

        return view('admin-views.business-settings.payment-index', compact('publishedStatus', 'paymentUrl', 'dataValues'));
    }

    public function paymentUpdate(Request $request, string $name): RedirectResponse
    {
        if ($name == 'cash_on_delivery') {
            $payment = $this->businessSetting->where('key', 'cash_on_delivery')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'cash_on_delivery',
                    'value' => json_encode([
                        'status' => $request['status'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'cash_on_delivery'])->update([
                    'key' => 'cash_on_delivery',
                    'value' => json_encode([
                        'status' => $request['status'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'digital_payment') {
            $payment = $this->businessSetting->where('key', 'digital_payment')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'digital_payment',
                    'value' => json_encode([
                        'status' => $request['status'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'digital_payment'])->update([
                    'key' => 'digital_payment',
                    'value' => json_encode([
                        'status' => $request['status'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'ssl_commerz_payment') {
            $payment = $this->businessSetting->where('key', 'ssl_commerz_payment')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'ssl_commerz_payment',
                    'value' => json_encode([
                        'status' => 1,
                        'store_id' => '',
                        'store_password' => '',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'ssl_commerz_payment'])->update([
                    'key' => 'ssl_commerz_payment',
                    'value' => json_encode([
                        'status' => $request['status'],
                        'store_id' => $request['store_id'],
                        'store_password' => $request['store_password'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'razor_pay') {
            $payment = $this->businessSetting->where('key', 'razor_pay')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'razor_pay',
                    'value' => json_encode([
                        'status' => 1,
                        'razor_key' => '',
                        'razor_secret' => '',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'razor_pay'])->update([
                    'key' => 'razor_pay',
                    'value' => json_encode([
                        'status' => $request['status'],
                        'razor_key' => $request['razor_key'],
                        'razor_secret' => $request['razor_secret'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'paypal') {
            $payment = $this->businessSetting->where('key', 'paypal')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'paypal',
                    'value' => json_encode([
                        'status' => 1,
                        'paypal_client_id' => '',
                        'paypal_secret' => '',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'paypal'])->update([
                    'key' => 'paypal',
                    'value' => json_encode([
                        'status' => $request['status'],
                        'paypal_client_id' => $request['paypal_client_id'],
                        'paypal_secret' => $request['paypal_secret'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'stripe') {
            $payment = $this->businessSetting->where('key', 'stripe')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'stripe',
                    'value' => json_encode([
                        'status' => 1,
                        'api_key' => '',
                        'published_key' => '',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'stripe'])->update([
                    'key' => 'stripe',
                    'value' => json_encode([
                        'status' => $request['status'],
                        'api_key' => $request['api_key'],
                        'published_key' => $request['published_key'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'senang_pay') {
            $payment = $this->businessSetting->where('key', 'senang_pay')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'senang_pay',
                    'value' => json_encode([
                        'status' => 1,
                        'secret_key' => '',
                        'merchant_id' => '',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'senang_pay'])->update([
                    'key' => 'senang_pay',
                    'value' => json_encode([
                        'status' => $request['status'],
                        'secret_key' => $request['secret_key'],
                        'merchant_id' => $request['merchant_id'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($name == 'paystack') {
            $payment = $this->businessSetting->where('key', 'paystack')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'paystack',
                    'value' => json_encode([
                        'status' => 1,
                        'publicKey' => '',
                        'secretKey' => '',
                        'paymentUrl' => '',
                        'merchantEmail' => '',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'paystack'])->update([
                    'key' => 'paystack',
                    'value' => json_encode([
                        'status' => $request['status'],
                        'publicKey' => $request['publicKey'],
                        'secretKey' => $request['secretKey'],
                        'paymentUrl' => $request['paymentUrl'],
                        'merchantEmail' => $request['merchantEmail'],
                    ]),
                    'updated_at' => now()
                ]);
            }
        } else if ($name == 'internal_point') {
            $payment = $this->businessSetting->where('key', 'internal_point')->first();
            if (!isset($payment)) {
                DB::table('business_settings')->insert([
                    'key' => 'internal_point',
                    'value' => json_encode([
                        'status' => $request['status'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('business_settings')->where(['key' => 'internal_point'])->update([
                    'key' => 'internal_point',
                    'value' => json_encode([
                        'status' => $request['status'],
                    ]),
                    'updated_at' => now(),
                ]);
            }
        } else if ($name == 'bkash') {
            DB::table('business_settings')->updateOrInsert(['key' => 'bkash'], [
                'value' => json_encode([
                    'status' => $request['status'],
                    'api_key' => $request['api_key'],
                    'api_secret' => $request['api_secret'],
                    'username' => $request['username'],
                    'password' => $request['password'],
                ])
            ]);
        } else if ($name == 'paymob') {
            DB::table('business_settings')->updateOrInsert(['key' => 'paymob'], [
                'value' => json_encode([
                    'status' => $request['status'],
                    'api_key' => $request['api_key'],
                    'iframe_id' => $request['iframe_id'],
                    'integration_id' => $request['integration_id'],
                    'hmac' => $request['hmac']
                ])
            ]);
        } else if ($name == 'flutterwave') {
            DB::table('business_settings')->updateOrInsert(['key' => 'flutterwave'], [
                'value' => json_encode([
                    'status' => $request['status'],
                    'public_key' => $request['public_key'],
                    'secret_key' => $request['secret_key'],
                    'hash' => $request['hash']
                ])
            ]);
        } else if ($name == 'mercadopago') {
            DB::table('business_settings')->updateOrInsert(['key' => 'mercadopago'], [
                'value' => json_encode([
                    'status' => $request['status'],
                    'public_key' => $request['public_key'],
                    'access_token' => $request['access_token']
                ])
            ]);
        }

        Toastr::success(translate('payment settings updated!'));
        return back();
    }

    public function paymentConfigUpdate(Request $request): RedirectResponse
    {
        collect(['status'])->each(fn($item, $key) => $request[$item] = $request->has($item) ? (int)$request[$item] : 0);

        $validation = [
            'gateway' => 'required|in:ssl_commerz,paypal,stripe,razor_pay,senang_pay,paystack,paymob_accept,flutterwave,bkash,mercadopago,instamojo,phonepe,cashfree',
            'mode' => 'required|in:live,test'
        ];

        $additionalData = [];

        if ($request['gateway'] == 'ssl_commerz') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'store_id' => 'required_if:status,1',
                'store_password' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'paypal') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'client_id' => 'required_if:status,1',
                'client_secret' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'stripe') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'published_key' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'razor_pay') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'api_secret' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'senang_pay') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'callback_url' => 'required_if:status,1',
                'secret_key' => 'required_if:status,1',
                'merchant_id' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'paystack') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'public_key' => 'required_if:status,1',
                'secret_key' => 'required_if:status,1',
                'merchant_email' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'paymob_accept') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'callback_url' => 'required_if:status,1',
                'api_key' => 'required_if:status,1',
                'iframe_id' => 'required_if:status,1',
                'integration_id' => 'required_if:status,1',
                'hmac' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'mercadopago') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'access_token' => 'required_if:status,1',
                'public_key' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'flutterwave') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'secret_key' => 'required_if:status,1',
                'public_key' => 'required_if:status,1',
                'hash' => 'required_if:status,1'
            ];
        } elseif ($request['gateway'] == 'bkash') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'app_key' => 'required_if:status,1',
                'app_secret' => 'required_if:status,1',
                'username' => 'required_if:status,1',
                'password' => 'required_if:status,1',
            ];
        }
        elseif ($request['gateway'] == 'instamojo') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'client_id' => 'required_if:status,1',
                'client_secret' => 'required_if:status,1',
            ];
        }elseif ($request['gateway'] == 'phonepe') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'merchant_id' => 'required_if:status,1',
                'salt_Key' => 'required_if:status,1',
                'salt_index' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'cashfree') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'client_id' => 'required_if:status,1',
                'client_secret' => 'required_if:status,1',
            ];
        }

        $request->validate(array_merge($validation, $additionalData));

        $settings = Setting::where('key_name', $request['gateway'])->where('settings_type', 'payment_config')->first();

        $additionalDataImage = $settings['additional_data'] != null ? json_decode($settings['additional_data']) : null;

        if ($request->has('gateway_image')) {
            $gatewayImage = Helpers::file_uploader('payment_modules/gateway_image/', 'png', $request['gateway_image'], $additionalDataImage != null ? $additionalDataImage->gateway_image : '');
        } else {
            $gatewayImage = $additionalDataImage != null ? $additionalDataImage->gateway_image : '';
        }

        $paymentAdditionalData = [
            'gateway_title' => $request['gateway_title'],
            'gateway_image' => $gatewayImage,
        ];

        $validator = Validator::make($request->all(), array_merge($validation, $additionalData));

        Setting::updateOrCreate(['key_name' => $request['gateway'], 'settings_type' => 'payment_config'], [
            'key_name' => $request['gateway'],
            'live_values' => $validator->validate(),
            'test_values' => $validator->validate(),
            'settings_type' => 'payment_config',
            'mode' => $request['mode'],
            'is_active' => $request['status'],
            'additional_data' => json_encode($paymentAdditionalData),
        ]);

        Toastr::success(GATEWAYS_DEFAULT_UPDATE_200['message']);
        return back();
    }

    public function fcmIndex(): View
    {
        return view('admin-views.business-settings.fcm-index');
    }

    public function updateFcm(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'push_notification_key'], [
            'value' => $request['push_notification_key']
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'push_notification_service_file_content'], [
            'value' => $request['push_notification_service_file_content'],
        ]);

        Toastr::success(translate('settings_updated'));
        return back();
    }

    public function updateFcmMessages(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'money_transfer_message'], [
            'value' => json_encode([
                'status' => $request['money_transfer_status'] == 1 ? 1 : 0,
                'message' => $request['money_transfer_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => CASH_IN], [
            'value' => json_encode([
                'status' => $request['cash_in_status'] == 1 ? 1 : 0,
                'message' => $request['cash_in_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => CASH_OUT], [
            'value' => json_encode([
                'status' => $request['cash_out_status'] == 1 ? 1 : 0,
                'message' => $request['cash_out_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => SEND_MONEY], [
            'value' => json_encode([
                'status' => $request['send_money_status'] == 1 ? 1 : 0,
                'message' => $request['send_money_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'request_money'], [
            'value' => json_encode([
                'status' => $request['request_money_status'] == 1 ? 1 : 0,
                'message' => $request['request_money_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'denied_money'], [
            'value' => json_encode([
                'status' => $request['denied_money_status'] == 1 ? 1 : 0,
                'message' => $request['denied_money_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'approved_money'], [
            'value' => json_encode([
                'status' => $request['approved_money_status'] == 1 ? 1 : 0,
                'message' => $request['approved_money_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => ADD_MONEY], [
            'value' => json_encode([
                'status' => $request['add_money_status'] == 1 ? 1 : 0,
                'message' => $request['add_money_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => ADD_MONEY_BONUS], [
            'value' => json_encode([
                'status' => $request['add_money_bonus_status'] == 1 ? 1 : 0,
                'message' => $request['add_money_bonus_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => RECEIVED_MONEY], [
            'value' => json_encode([
                'status' => $request['received_money_status'] == 1 ? 1 : 0,
                'message' => $request['received_money_message']
            ])
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => PAYMENT], [
            'value' => json_encode([
                'status' => $request['payment_money_status'] == 1 ? 1 : 0,
                'message' => $request['payment_money_message']
            ])
        ]);

        Toastr::success(translate('message_updated'));
        return back();
    }

    public function linkedWebsite(): View
    {
        $linkedWebsites = $this->linkedWebsite->latest()->paginate(Helpers::pagination_limit());
        return view('admin-views.linked-website.index', compact('linkedWebsites'));
    }

    public function linkedWebsiteAdd(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'name' => 'required',
            'url' => 'required',
            'image' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        $linkedWebsite = $this->linkedWebsite;
        $linkedWebsite->name = $request->name;
        $linkedWebsite->url = $request->url;
        $linkedWebsite->status = 1;
        $linkedWebsite->image = Helpers::upload('website/', APPLICATION_IMAGE_FORMAT, $request->file('image'));
        $linkedWebsite->save();

        Toastr::success(translate('Added Successfully!'));
        return back();
    }

    public function linkedWebsiteEdit(int $id): View
    {
        $linkedWebsite = $this->linkedWebsite->find($id);
        return view('admin-views.linked-website.edit', compact('linkedWebsite'));
    }

    public function linkedWebsiteUpdate(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'name' => 'required',
            'url' => 'required',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        $linkedWebsite = $this->linkedWebsite->find($request->id);
        $linkedWebsite->name = $request->name;
        $linkedWebsite->url = $request->url;
        $linkedWebsite->status = 1;
        $linkedWebsite->image = $request->has('image') ? Helpers::upload('website/', APPLICATION_IMAGE_FORMAT, $request->file('image')) : $linkedWebsite->image;
        $linkedWebsite->save();

        Toastr::success(translate('Updated Successfully!'));
        return back();
    }

    public function linkedWebsiteStatus(int $id): RedirectResponse
    {
        $linkedWebsite = $this->linkedWebsite->find($id);
        $linkedWebsite->status = !$linkedWebsite->status;
        $linkedWebsite->save();

        Toastr::success(translate('Status Updated Successfully!'));
        return back();
    }

    public function linkedWebsiteDelete(Request $request): RedirectResponse
    {
        $linkedWebsite = $this->linkedWebsite->find($request->id);
        if (Storage::disk('public')->exists('banner/' . $linkedWebsite['image'])) {
            Storage::disk('public')->delete('banner/' . $linkedWebsite['image']);
        }
        $linkedWebsite->delete();

        Toastr::success(translate('Website removed!'));
        return back();
    }

    public function recaptchaIndex(): View
    {
        return view('admin-views.business-settings.recaptcha-index');
    }

    public function recaptchaUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'recaptcha'], [
            'key' => 'recaptcha',
            'value' => json_encode([
                'status' => $request['status'],
                'site_key' => $request['site_key'],
                'secret_key' => $request['secret_key']
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        Toastr::success(translate('Updated Successfully'));
        return back();
    }

    public function appSettings(Request $request): View
    {
        return view('admin-views.business-settings.app-setting-index');
    }

    public function appSettingUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'app_theme'], [
            'value' => $request['theme']
        ]);

        Toastr::success(translate('App theme Updated Successfully'));
        return back();
    }

    public function merchantConfigIndex(Request $request): View
    {
        return view('admin-views.business-settings.merchant-config-index');
    }

    public function merchantPaymentOtpUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'payment_otp_verification'], [
            'value' => $request['payment_otp_verification']
        ]);

        Toastr::success(translate('Updated Successfully'));
        return back();
    }

    public function merchantSettingUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'merchant_commission_percent'], [
            'value' => $request['merchant_commission_percent']
        ]);

        Toastr::success('Settings updated');
        return back();
    }

    public function otpSetup(): View
    {
        return view('admin-views.business-settings.otp-setup');
    }

    public function otpSetupUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'maximum_otp_hit'], [
            'value' => $request['maximum_otp_hit'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'otp_resend_time'], [
            'value' => $request['otp_resend_time'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'temporary_block_time'], [
            'value' => $request['temporary_block_time'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'maximum_login_hit'], [
            'value' => $request['maximum_login_hit'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'temporary_login_block_time'], [
            'value' => $request['temporary_login_block_time'],
        ]);

        Toastr::success(translate('Settings updated!'));
        return back();
    }

    public function systemFeature(): View
    {
        return view('admin-views.business-settings.system-feature');
    }

    public function systemFeatureUpdate(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'add_money_status'], [
            'value' => $request['add_money_status'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'send_money_status'], [
            'value' => $request['send_money_status'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'cash_out_status'], [
            'value' => $request['cash_out_status'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'send_money_request_status'], [
            'value' => $request['send_money_request_status'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'withdraw_request_status'], [
            'value' => $request['withdraw_request_status'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'linked_website_status'], [
            'value' => $request['linked_website_status'],
        ]);
        DB::table('business_settings')->updateOrInsert(['key' => 'banner_status'], [
            'value' => $request['banner_status'],
        ]);

        Toastr::success(translate('Settings updated!'));
        return back();
    }

    public function customerTransactionLimitsIndex(): View
    {
        return view('admin-views.business-settings.customer-transaction-limits-index');
    }

    public function agentTransactionLimitsIndex(): View
    {
        return view('admin-views.business-settings.agent-transaction-limits-index');
    }

    public function transactionLimitsUpdate(Request $request, string $name): RedirectResponse
    {
        $transactionLimitPerDay = (int)$request['transaction_limit_per_day'];
        $maximiumAmountPerTransaction = (float)$request['max_amount_per_transaction'];
        $totalTransactionAmountPerDay = (float)$request['total_transaction_amount_per_day'];
        $transactionLimitPerMonth = (int)$request['transaction_limit_per_month'];
        $totalTransactionAmountPerMonth = (float)$request['total_transaction_amount_per_month'];

        if ($transactionLimitPerDay > $transactionLimitPerMonth) {
            Toastr::error(translate('Transaction limit per day cannot be greater than the transaction limit per month.'));
            return back();
        }

        if ($maximiumAmountPerTransaction > $totalTransactionAmountPerDay) {
            Toastr::error(translate('Maximum amount per transaction cannot be greater than the total transaction amount per day.'));
            return back();
        }

        if ($totalTransactionAmountPerDay > $totalTransactionAmountPerMonth) {
            Toastr::error(translate('Total transaction amount per day cannot be greater than the total transaction amount per month.'));
            return back();
        }

        if ($name == 'customer_add_money_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_add_money_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);

        } elseif ($name == 'customer_send_money_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_send_money_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        } elseif ($name == 'customer_cash_out_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_cash_out_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        } elseif ($name == 'customer_send_money_request_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_send_money_request_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        } elseif ($name == 'customer_withdraw_request_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'customer_withdraw_request_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        } elseif ($name == 'agent_add_money_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_add_money_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);

        } elseif ($name == 'agent_send_money_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_send_money_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        } elseif ($name == 'agent_send_money_request_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_send_money_request_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        } elseif ($name == 'agent_withdraw_request_limit') {
            DB::table('business_settings')->updateOrInsert(['key' => 'agent_withdraw_request_limit'], [
                'value' => json_encode([
                    'status' => (int)$request['status'],
                    'transaction_limit_per_day' => (int)$request['transaction_limit_per_day'],
                    'max_amount_per_transaction' => (float)$request['max_amount_per_transaction'],
                    'total_transaction_amount_per_day' => (float)$request['total_transaction_amount_per_day'],
                    'transaction_limit_per_month' => (int)$request['transaction_limit_per_month'],
                    'total_transaction_amount_per_month' => (float)$request['total_transaction_amount_per_month']
                ])
            ]);
        }

        Toastr::success(translate('Settings updated!'));
        return back();
    }

    public function mailConfigStatus(Request $request): RedirectResponse
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('update_option_is_disable_for_demo'));
            return back();
        }
        $config = BusinessSetting::where(['key' => 'mail_config'])->first();

        $data = $config ? json_decode($config['value'], true) : null;

        BusinessSetting::updateOrInsert(
            ['key' => 'mail_config'],
            [
                'value' => json_encode([
                    "status" => $request['status'] ?? 0,
                    "name" => $data['name'] ?? '',
                    "host" => $data['host'] ?? '',
                    "driver" => $data['driver'] ?? '',
                    "port" => $data['port'] ?? '',
                    "username" => $data['username'] ?? '',
                    "email_id" => $data['email_id'] ?? '',
                    "encryption" => $data['encryption'] ?? '',
                    "password" => $data['password'] ?? ''
                ]),
                'updated_at' => now()
            ]
        );
        Toastr::success(translate('configuration_updated_successfully'));
        return back();
    }

    public function mailConfigUpdate(Request $request): RedirectResponse
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('update_option_is_disable_for_demo'));
            return back();
        }
        BusinessSetting::updateOrInsert(
            ['key' => 'mail_config'],
            [
                'value' => json_encode([
                    "status" => $request['status'] ?? 0,
                    "name" => $request['name'],
                    "host" => $request['host'],
                    "driver" => $request['driver'],
                    "port" => $request['port'],
                    "username" => $request['username'],
                    "email_id" => $request['email'],
                    "encryption" => $request['encryption'],
                    "password" => $request['password']
                ]),
                'updated_at' => now()
            ]
        );
        Toastr::success(translate('configuration_updated_successfully'));
        return back();
    }

    public function sendMail(Request $request): RedirectResponse|JsonResponse
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('update_option_is_disable_for_demo'));
            return back();
        }
        $responseFlag = 0;
        try {
            Mail::to($request->email)->send(new TestEmailSender());
            $responseFlag = 1;
        } catch (\Exception $exception) {
            info($exception->getMessage());
            $responseFlag = 2;
        }

        return response()->json(['success' => $responseFlag]);
    }

    public function updateBusinessSettingStatus(Request $request): JsonResponse
    {
        BusinessSetting::updateOrInsert(['key' => $request['name']], [
            'value' => $request['value']
        ]);

        return response()->json();
    }

    public function updateBusinessSettingData(Request $request): RedirectResponse
    {
        foreach ($request->except('_token') as $key => $value) {
            BusinessSetting::updateOrInsert(
                ['key' => $key],
                ['value' => $value]
            );
        }

        Toastr::success(translate('Settings updated successfully'));
        return back();
    }
}
