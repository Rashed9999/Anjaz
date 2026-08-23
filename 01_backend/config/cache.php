<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-CACHE-NAME-DRIFT-001 — **إعدادٌ مكتوبٌ ولا يُقرأ.**
    //
    // **ما قِيس:**
    //
    //     .env يقول        : CACHE_STORE=database
    //     الإعدادُ يقرأ     : CACHE_DRIVER  →  NULL
    //     فما يُحلُّ فعلاً  : **file**  (Illuminate\Cache\FileStore)
    //
    // `CACHE_DRIVER` اسمُ Laravel 10، و`CACHE_STORE` اسمُ الحادي عشر.
    // والمشروعُ على الثاني عشر، وملفُّ الإعداد بقي على الأوّل — أثرٌ من
    // قاعدة 6cash لم يُهاجَر. **فمن قرأ `.env` قرأ «database» ولم يكن.**
    //
    // **ولمَ يهمّ في تجربة ألفي مستخدم:** الذاكرةُ ليست تسريعاً هنا، هي
    // حاملُ الحالة:
    //
    //   · عدّاداتُ حدّ المعدّل — `throttle:api` ومحدِّداتُ الدخول
    //   · سلّمُ قفل الدخول — `auth_locked:` و`auth_lock_step:`
    //   · قفلُ رمز العمليّات ومهلُ OTP
    //
    // وملفَّاتُ الذاكرة **داخل الحاوية**، والحاويةُ تُزال في كلّ نشرة
    // (`rolling update is not supported`). فكلُّ قفلٍ وكلُّ عدّادٍ يُمحى
    // مع النشر — **ومهاجمٌ بلغ محاولتَه الرابعةَ يعود من الصفر**.
    //
    // ويُقرأ الاسمان معاً: الجديدُ أوّلاً، والقديمُ احتياطاً لمن بقيت
    // بيئتُه عليه — **فتغييرُ الافتراض وحدَه يكسر من ضبط القديمَ عمداً**.
    // ══════════════════════════════════════════════════════════════════
    'default' => env('CACHE_STORE', env('CACHE_DRIVER', 'database')),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "apc", "array", "database", "file",
    |         "memcached", "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'apc' => [
            'driver' => 'apc',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
            'lock_connection' => null,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as APC or Memcached, there might
    | be other applications utilizing the same cache. So, we'll specify a
    | value to get prefixed to all our keys so we can avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_cache'),

];
