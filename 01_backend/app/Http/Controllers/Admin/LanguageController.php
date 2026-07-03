<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use Brian2694\Toastr\Facades\Toastr;
use FilesystemIterator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class LanguageController extends Controller
{
    public function __construct(
        private BusinessSetting $business_setting,
    ){}

    public function index(): View
    {
        return view('admin-views.business-settings.language.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $language = Helpers::get_business_settings('language');

        if(!isset($language)) {
            DB::table('business_settings')->updateOrInsert(['key' => 'language'], [
                'value' => '[{"id":"1","name":"english","direction":"ltr","code":"en","status":1,"default":true}]'
            ]);
            $language = Helpers::get_business_settings('language');
        }
        $langArray = [];
        $codes = [];
        foreach ($language as $key => $data) {
            if ($data['code'] != $request['code']) {
                if (!array_key_exists('default', $data)) {
                    $default = array('default' => ($data['code'] == 'en') ? true : false);
                    $data = array_merge($data, $default);
                }
                $langArray[] = $data;
                $codes[] = $data['code'];
            }
        }
        $codes[] = $request['code'];

        if (!file_exists(base_path('resources/lang/' . $request['code']))) {
            mkdir(base_path('resources/lang/' . $request['code']), 0777, true);
        }

        $langFile = fopen(base_path('resources/lang/' . $request['code'] . '/' . 'messages.php'), "w") or die("Unable to open file!");
        $read = file_get_contents(base_path('resources/lang/en/messages.php'));
        fwrite($langFile, $read);

        $langArray[] = [
            'id' => count($language) + 1,
            'name' => $request['name'],
            'code' => $request['code'],
            'direction' => 'ltr',   //since no rtl in version 1.0
            'status' => 0,
            'default' => false,
        ];

        $this->business_setting->updateOrInsert(['key' => 'language'], [
            'value' => $langArray
        ]);

        Toastr::success('Language Added!');
        return back();
    }

    public function updateStatus(Request $request): int
    {
        $language = Helpers::get_business_settings('language');
        $langArray = [];
        foreach ($language as $key => $data) {
            if ($data['code'] == $request['code']) {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'] == 1 ? 0 : 1,
                    'default' => (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
            } else {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'],
                    'default' => (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
            }
            $langArray[] = $lang;
        }
        $businessSetting = $this->business_setting->where('key', 'language')->update([
            'value' => $langArray
        ]);

        return $businessSetting;
    }

    public function updateDefaultStatus(Request $request): RedirectResponse
    {
        $language = Helpers::get_business_settings('language');
        $langArray = [];
        foreach ($language as $key => $data) {
            if ($data['code'] == $request['code']) {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => 1,
                    'default' => true,
                ];
            } else {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'],
                    'default' => false,
                ];
            }
            $langArray[] = $lang;
        }
        $this->business_setting->where('key', 'language')->update([
            'value' => $langArray
        ]);

        Toastr::success('Default Language Changed!');
        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        $language = Helpers::get_business_settings('language');
        $langArray = [];
        foreach ($language as $key => $data) {
            if ($data['code'] == $request['code']) {
                $lang = [
                    'id' => $data['id'],
                    'name' => $request['name'],
                    'direction' => $request['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'] ?? 0,
                    'default' => (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
            } else {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'],
                    'default' => (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
            }
            $langArray[] = $lang;
        }
        $this->business_setting->where('key', 'language')->update([
            'value' => $langArray
        ]);
        Toastr::success('Language updated!');
        return back();
    }

    public function translate(string $lang): View
    {
        $fullData = include(base_path('resources/lang/' . $lang . '/messages.php'));
        $langData = [];
        ksort($fullData);
        foreach ($fullData as $key => $data) {
            $langData[] = ['key' => $key, 'value' => $data];
        }
        return view('admin-views.business-settings.language.translate', compact('lang', 'langData'));
    }

    public function translateKeyRemove(Request $request, string $lang): void
    {
        $fullData = include(base_path('resources/lang/' . $lang . '/messages.php'));
        unset($fullData[$request['key']]);
        $str = "<?php return " . var_export($fullData, true) . ";";
        file_put_contents(base_path('resources/lang/' . $lang . '/messages.php'), $str);
    }

    public function translateSubmit(Request $request, $lang): void
    {
        $fullData = include(base_path('resources/lang/' . $lang . '/messages.php'));
        $fullData[urldecode($request['key'])] = $request['value'];
        $str = "<?php return " . var_export($fullData, true) . ";";
        file_put_contents(base_path('resources/lang/' . $lang . '/messages.php'), $str);
    }

    public function delete(string $lang): RedirectResponse
    {
        $language = Helpers::get_business_settings('language');

        $delDefault = false;
        foreach ($language as $key => $data) {
            if ($data['code'] == $lang && array_key_exists('default', $data) && $data['default'] == true) {
                $delDefault = true;
            }
        }

        $langArray = [];
        foreach ($language as $key => $data) {
            if ($data['code'] != $lang) {
                $langData = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => ($delDefault == true && $data['code'] == 'en') ? 1 : $data['status'],
                    'default' => ($delDefault == true && $data['code'] == 'en') ? true : (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
                $langArray[] = $langData;
            }
        }

        $this->business_setting->where('key', 'language')->update([
            'value' => $langArray
        ]);

        $dir = base_path('resources/lang/' . $lang);
        if (file_exists($dir)) {
            $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($dir);
        }


        Toastr::success('Removed Successfully!');
        return back();
    }

    public function lang(string $local): RedirectResponse
    {
        $direction = 'ltr';
        $language = Helpers::get_business_settings('language');
        foreach ($language as $key => $data) {
            if ($data['code'] == $local) {
                $direction = $data['direction'] ?? 'ltr';
            }
        }
        session()->forget('language_settings');
        Helpers::language_load();
        session()->put('local', $local);
        Session::put('direction', $direction);
        return redirect()->back();
    }
}
