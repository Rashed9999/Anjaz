<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Traits\ActivationClass;
use App\Traits\AddonHelper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class InstallController extends Controller
{
    use ActivationClass, AddonHelper;

    public function step0(): View
    {
        return view('installation.step0');
    }

    public function step1(Request $request): View|RedirectResponse
    {
        if (Hash::check('step_1', $request['token'])) {
            //extensions
            $permission['curl'] = function_exists('curl_version');
            $permission['bcmath'] = extension_loaded('bcmath');
            $permission['ctype'] = extension_loaded('ctype');
            $permission['json'] = extension_loaded('json');
            $permission['mbstring'] = extension_loaded('mbstring');
            $permission['openssl'] = extension_loaded('openssl');
            $permission['pdo'] = defined('PDO::ATTR_DRIVER_NAME');
            $permission['tokenizer'] = extension_loaded('tokenizer');
            $permission['xml'] = extension_loaded('xml');
            $permission['zip'] = extension_loaded('zip');
            $permission['fileinfo'] = extension_loaded('fileinfo');
            $permission['gd'] = extension_loaded('gd');
            $permission['sodium'] = extension_loaded('sodium');

            //file permissions
            $permission['db_file_write_perm'] = is_writable(base_path('.env'));
            $permission['routes_file_write_perm'] = is_writable(base_path('app/Providers/RouteServiceProvider.php'));

            return view('installation.step1', compact('permission'));
        }
        session()->flash('error', 'Access denied!');
        return redirect()->route('step0');
    }

    public function step2(Request $request): View|RedirectResponse
    {
        if (Hash::check('step_2', $request['token'])) {
            return view('installation.step2');
        }
        session()->flash('error', 'Access denied!');
        return redirect()->route('step0');
    }

    public function step3(Request $request): View|RedirectResponse
    {
        if (Hash::check('step_3', $request['token'])) {
            return view('installation.step3');
        }
        session()->flash('error', 'Access denied!');
        return redirect()->route('step0');
    }

    public function step4(Request $request): View|RedirectResponse
    {
        if (Hash::check('step_4', $request['token'])) {
            return view('installation.step4');
        }
        session()->flash('error', 'Access denied!');
        return redirect()->route('step0');
    }

    public function step5(Request $request): View|RedirectResponse
    {
        if (Hash::check('step_5', $request['token'])) {
            return view('installation.step5');
        }
        session()->flash('error', 'Access denied!');
        return redirect()->route('step0');
    }

    public function purchaseCode(Request $request): RedirectResponse
    {
        Helpers::setEnvironmentValue('SOFTWARE_ID', 'MzczNTQxNDc=');
        Helpers::setEnvironmentValue('BUYER_USERNAME', $request['username']);
        Helpers::setEnvironmentValue('PURCHASE_CODE', $request['purchase_key']);

        $post = [
            'name' => $request['name'],
            'email' => $request['email'],
            'username' => $request['username'],
            'purchase_key' => $request['purchase_key'],
            'domain' => preg_replace("#^[^:/.]*[:/]+#i", "", url('/')),
        ];
        $response = $this->dmvf($post);

        return redirect($response . '?token=' . bcrypt('step_3'));
    }

    public function systemSettings(Request $request): View
    {
        Validator::make($request->all(), [
            'password' => 'required|min:8',
            'confirm_password' => 'required|same:password',
        ])->validate();

        DB::table('users')->insertOrIgnore([
            'id' => 1,
            'f_name' => $request['first_name'],
            'l_name' => $request['last_name'],
            'dial_country_code' => $request['phone_code'],
            'email' => $request['email'],
            'type' => 0,
            'role' => null,
            'is_active' => 1,
            'is_phone_verified' => 1,
            'password' => bcrypt($request['password']),
            'phone' => $request['phone_code'] . $request['phone'],
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('e_money')->insertOrIgnore([
            'user_id' => 1,
            'current_balance' => 0,
            'charge_earned' => 0,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('business_settings')->where(['key' => 'business_name'])->update([
            'value' => $request['business_name']
        ]);

        $previousRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.php');
        $newRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.txt');
        copy($newRouteServiceProvider, $previousRouteServiceProvider);
        Artisan::call('storage:link');
        $this->checkSystemAddonsSymbolicLink();
        return view('installation.step6');
    }

    public function databaseInstallation(Request $request): RedirectResponse
    {
        if (self::checkDatabaseConnection($request->DB_HOST, $request->DB_DATABASE, $request->DB_USERNAME, $request->DB_PASSWORD)) {
            $key = base64_encode(random_bytes(32));
            $lines = [
                'APP_NAME=6cash' . time(),
                'APP_ENV=live',
                'APP_KEY=base64:' . $key,
                'APP_DEBUG=false',
                'APP_INSTALL=true',
                'APP_LOG_LEVEL=debug',
                'APP_MODE=live',
                'APP_URL=' . URL::to('/'),
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST=' . $request->DB_HOST,
                'DB_PORT=3306',
                'DB_DATABASE=' . $request->DB_DATABASE,
                'DB_USERNAME=' . $request->DB_USERNAME,
                'DB_PASSWORD=' . $request->DB_PASSWORD,
                '',
                'BROADCAST_DRIVER=log',
                'CACHE_DRIVER=file',
                'SESSION_DRIVER=file',
                'SESSION_LIFETIME=120',
                'QUEUE_DRIVER=sync',
                '',
                'REDIS_HOST=127.0.0.1',
                'REDIS_PASSWORD=null',
                'REDIS_PORT=6379',
                '',
                'PUSHER_APP_ID=',
                'PUSHER_APP_KEY=',
                'PUSHER_APP_SECRET=',
                'PUSHER_APP_CLUSTER=mt1',
                '',
                'PURCHASE_CODE=' . session('purchase_key'),
                'BUYER_USERNAME=' . session('username'),
                'SOFTWARE_ID=MzczNTQxNDc=',
                '',
                'SOFTWARE_VERSION=4.8',
            ];
            $output = implode(PHP_EOL, $lines) . PHP_EOL;
            file_put_contents(base_path('.env'), $output);

            $path = base_path('.env');
            if (file_exists($path)) {
                return redirect()->route('step4', ['token' => $request['token']]);
            } else {
                session()->flash('error', 'Database error!');
                return redirect()->route('step3', ['token' => bcrypt('step_3')]);
            }
        } else {
            session()->flash('error', 'Database host error!');
            return redirect()->route('step3', ['token' => bcrypt('step_3')]);
        }
    }

    public function importSql(): RedirectResponse
    {
        try {
            $sqlPath = base_path('installation/backup/database.sql');
            DB::unprepared(file_get_contents($sqlPath));
            return redirect()->route('step5', ['token' => bcrypt('step_5')]);
        } catch (\Exception $exception) {
            session()->flash('error', 'Your database is not clean, do you want to clean database then import?');
            return back();
        }
    }

    public function forceImportSql(): RedirectResponse
    {
        try {
            Artisan::call('db:wipe', ['--force' => true]);
            $sqlPath = base_path('installation/backup/database.sql');
            DB::unprepared(file_get_contents($sqlPath));
            return redirect()->route('step5', ['token' => bcrypt('step_5')]);
        } catch (\Exception $exception) {
            session()->flash('error', 'Check your database permission!');
            return back();
        }
    }

    function checkDatabaseConnection($db_host = "", $db_name = "", $db_user = "", $db_pass = ""): bool
    {
        if (@mysqli_connect($db_host, $db_user, $db_pass, $db_name)) {
            return true;
        } else {
            return false;
        }
    }
}
