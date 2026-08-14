<?php

use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\App;

if(!function_exists('translate')) {
    function translate(string $key): string
    {
        // ══════════════════════════════════════════════════════════════
        //  AMIAL-I18N-001 — **الافتراضُ عربيّ، لا إنجليزيّ.**
        //
        //  كان `: 'en'`. فأيُّ مديرٍ يفتح اللوحة بلا أن يبدّل اللغة يقرأ
        //  إنجليزيّاً — وهو حالُ كلّ زيارةٍ أولى وكلّ جلسةٍ جديدة. ولذلك
        //  ظهرت «System Audit Log» و«Executive Dashboard» في لوحةٍ عربيّة.
        //
        //  **وهذه النسخةُ هي الفائزة** عبر `function_exists`: في المشروع
        //  تعريفان لـ`translate()`، وهذا الملفُّ يُحمَّل قبل `Helpers.php`
        //  في `composer.json`. فمن أصلح الآخرَ وحده لم يُصلح شيئاً —
        //  **وقد وقع هذا فعلاً في هذه الجولة.**
        $local = session()->has('local') ? session('local') : 'ar';
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
