<?php

namespace App\Providers;

use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        try {
            $emailServices = Helpers::get_business_settings('mail_config');
            if ($emailServices) {
                $config = array(
                    'status' => (Boolean)(isset($emailServices['status'])?$emailServices['status']:1),
                    'driver' => $emailServices['driver'],
                    'host' => $emailServices['host'],
                    'port' => $emailServices['port'],
                    'username' => $emailServices['username'],
                    'password' => $emailServices['password'],
                    'encryption' => $emailServices['encryption'],
                    'from' => array('address' => $emailServices['email_id'], 'name' => $emailServices['name']),
                    'sendmail' => '/usr/sbin/sendmail -bs',
                    'pretend' => false,
                );
                Config::set('mail', $config);
            }

            // AMIAL-CLEANUP: أُزيلت تهيئة بوّابات الدفع الأجنبية (sslcommerz/paypal/
            // razorpay/paystack/flutterwave) — غير مستخدمة في أميال باي (نظام محفظة
            // داخلي + شبكة وكلاء). كانت آثاراً ميتة من قاعدة 6cash.

            //timezone setup
            $time_zone = Helpers::get_business_settings('timezone');
            if ($time_zone) {
                Config::set('timezone', $time_zone);
                date_default_timezone_set($time_zone);
            }
        } catch (\Exception $ex) {

        }
    }
}
