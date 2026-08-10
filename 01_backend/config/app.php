<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | وضعُ التشغيل — AMIAL-OTP-ENV-001
    |--------------------------------------------------------------------------
    |
    | كانت خمسةَ عشرَ موضعاً في `app/` تقرأ `env('APP_MODE')` مباشرة.
    | و`docker/entrypoint.prod.sh` يشغّل `php artisan config:cache`، ولارافيل
    | بعدها **لا يحمّل `.env` إطلاقاً** — فتُرجع `env()` قيمةَ `null` في
    | الإنتاج وحدَه.
    |
    | وأثرُ ذلك لم يكن تجميليّاً: ثمانيةُ مسارات كانت تُصدر رمزَ تحقّقٍ
    | ثابتاً `1234` على الخادم الحيّ — منها استعادةُ كلمة مرور العميل
    | والوكيل. ولا خطأَ في أيّ سجلّ، ولا اختبارٌ يسقط (بيئةُ الاختبار بلا
    | إعدادٍ مخزَّن فتقرأ القيمة الحقيقيّة **فتُخفي العطل**).
    |
    | والافتراضُ `live` عمداً: **الغيابُ يُقرأ إنتاجاً لا تطويراً.** فلو
    | سقط المتغيّرُ يوماً لصارت البيئةُ أشدَّ لا أسهل.
    |
    */

    'mode' => env('APP_MODE', 'live'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    // AMIAL-APP-URL-SANITIZE-001 — يُنظَّف ويُتحقَّق منه قبل أن يُقرأ.
    //
    // بناءُ Coolify كاملاً (٦ دقائق) سقط بـ«Invalid URI: Scheme is
    // malformed» — رسالةٌ لا تذكر `APP_URL` ولا القيمة التي أفسدته.
    // التفصيل كلّه ومصفوفة القياس في `app/Support/AppUrl.php`.
    'url' => \App\Support\AppUrl::resolve(env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    // AMIAL-LANG-001: العربية لغة أساسية والإنجليزية ثانوية اختيارية.
    'locale' => env('APP_LOCALE', 'ar'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    // AMIAL-DEMO-OTP: رمز تحقّق ثابت يُقبل في التسجيل ويُعبَّأ تلقائياً في
    // التطبيق، ما دامت بوابة SMS غير مضبوطة.
    //
    // كان بلا قيمة افتراضية: env('AMIAL_DEMO_OTP') وحده. والمتغيّر غير مضبوط
    // على الخادم، فيعيد null، فيموت مسار الالتفاف المكتوب في RegisterController
    // وcheckPhone. ولا بوابة SMS ترسل رمزاً حقيقياً — فيقف التسجيل عند الخطوة
    // الأخيرة بلا مخرج. الافتراضي الآن مضبوط ليعمل بلا إعداد بيئة.
    //
    // ⚠️ للإنتاج الحقيقي: اضبط بوابة SMS ثم ضع AMIAL_DEMO_OTP= فارغاً في
    // متغيّرات البيئة لتعطيله. ما دام مضبوطاً، يستطيع أي أحد إتمام التسجيل
    // بهذا الرمز دون امتلاك الرقم.
    'amial_demo_otp' => env('AMIAL_DEMO_OTP', '123456'),

    // AMIAL-TRANSFER-COOLDOWN: نافذة التراجع عن التحويل بالثواني.
    // 60 افتراضياً؛ اضبط AMIAL_TRANSFER_COOLDOWN=15 مثلاً لتجربة أسرع.
    'amial_transfer_cooldown' => (int) env('AMIAL_TRANSFER_COOLDOWN', 60),

];
