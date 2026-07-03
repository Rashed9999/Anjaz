<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SMSModuleController extends Controller
{
    public function smsIndex(): View
    {
        $publishedStatus = addon_published_status('Gateways');
        $routes = config('addon_admin_routes');
        $desiredName = 'sms_setup';
        $paymentUrl = '';

        foreach ($routes as $routeArray) {
            foreach ($routeArray as $route) {
                if ($route['name'] === $desiredName) {
                    $paymentUrl = $route['url'];
                    break 2;
                }
            }
        }
        $dataValues = Setting::where('settings_type', 'sms_config')
        ->whereIn('key_name', ['twilio','nexmo','2factor','msg91'])
        ->get();

        return view('admin-views.business-settings.sms-index', compact('dataValues', 'publishedStatus', 'paymentUrl'));
    }

    public function smsConfigUpdate(Request $request): RedirectResponse
    {
        collect(['status'])->each(fn($item, $key) => $request[$item] = $request->has($item) ? (int)$request[$item] : 0);
        $validation = [
            'gateway' => 'required|in:twilio,nexmo,2factor,msg91',
            'mode' => 'required|in:live,test'
        ];
        $additionalData = [];
        if ($request['gateway'] == 'twilio') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'sid' => 'required',
                'messaging_service_sid' => 'required',
                'token' => 'required',
                'from' => 'required',
                'otp_template' => 'required'
            ];
        } elseif ($request['gateway'] == 'nexmo') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required',
                'api_secret' => 'required',
                'token' => 'required',
                'from' => 'required',
                'otp_template' => 'required'
            ];
        } elseif ($request['gateway'] == '2factor') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required'
            ];
        } elseif ($request['gateway'] == 'msg91') {
            $additionalData = [
                'status' => 'required|in:1,0',
                'template_id' => 'required',
                'auth_key' => 'required',
            ];
        }
        $validation = $request->validate(array_merge($validation, $additionalData));

        Setting::updateOrCreate(['key_name' => $request['gateway'], 'settings_type' => 'sms_config'], [
            'key_name' => $request['gateway'],
            'live_values' => $validation,
            'test_values' => $validation,
            'settings_type' => 'sms_config',
            'mode' => $request['mode'],
            'is_active' => $request['status'],
        ]);

        if ($request['status'] == 1) {
            foreach (['twilio', 'nexmo', '2factor', 'msg91'] as $gateway) {
                if ($request['gateway'] != $gateway) {
                    $keep = Setting::where(['key_name' => $gateway, 'settings_type' => 'sms_config'])->first();
                    if (isset($keep)) {
                        $hold = $keep->live_values;
                        $hold['status'] = 0;
                        Setting::where(['key_name' => $gateway, 'settings_type' => 'sms_config'])->update([
                            'live_values' => $hold,
                            'test_values' => $hold,
                            'is_active' => 0,
                        ]);
                    }
                }
            }
        }

        Toastr::success(DEFAULT_UPDATE_200['message']);
        return back();
    }
}
