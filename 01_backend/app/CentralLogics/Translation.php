<?php

use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\App;

if(!function_exists('translate')) {
    function translate(string $key): string
    {
        $local = session()->has('local') ? session('local') : 'en';
        App::setLocale($local);

        $path = base_path('resources/lang/' . $local . '/messages.php');
        $lang_array = file_exists($path) ? include($path) : [];
        if (!is_array($lang_array)) {
            $lang_array = [];
        }
        $processed_key = ucfirst(str_replace('_', ' ', Helpers::remove_invalid_charcaters($key)));

        if (!array_key_exists($key, $lang_array)) {
            // AMIAL-FIX: ملفات اللغة للقراءة فقط داخل حاوية الإنتاج — محاولة
            // كتابة مفتاح مفقود كانت تُسقط الطلب بـ "Permission denied"
            // (هذه النسخة الثانية من translate هي الفائزة عبر function_exists،
            //  فأصابها نفس علاج نسخة Helpers.php). نكتب فقط إن أمكن.
            try {
                if (@is_writable($path)) {
                    $lang_array[$key] = $processed_key;
                    $str = "<?php return " . var_export($lang_array, true) . ";";
                    @file_put_contents($path, $str);
                }
            } catch (\Throwable $e) {
                // الحفظ اختياري — لا نُسقط العملية لأجل ترجمة
            }
            $result = $processed_key;
        } else {
            $result = __('messages.' . $key);
        }
        return $result;
    }
}
