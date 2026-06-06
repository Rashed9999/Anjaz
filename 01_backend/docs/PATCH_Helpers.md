# AMIAL-REFACTOR-CORE-001 — دمج تعديلات Helpers

> هذا الملف يستبدل الملف القديم `app/CentralLogics/Helpers_PATCH.php` الذي كان
> **PHP غير صالح** (دوال `public static function` خارج أي class → Parse error).
>
> النسخة القابلة للتنفيذ من الدوال أصبحت trait صالح في:
> `app/CentralLogics/Concerns/AmialHelperPatchTrait.php`

## لماذا patch وليس ملفاً كاملاً؟

`app/CentralLogics/Helpers.php` ملف ضخم (~900 سطر) يأتي من قاعدة Cash6 الأصلية
**وهو غير مُرفق في هذه الحزمة** (انظر `FIXES.md`). هذه الحزمة طبقة تعديلات (delta).
عند الدمج مع القاعدة الأصلية، طبّق ما يلي.

## خطوات الدمج

1. افتح `app/CentralLogics/Helpers.php` الأصلي.
2. أضف في أعلى الـ class:
   ```php
   use App\CentralLogics\Concerns\AmialHelperPatchTrait;

   class Helpers
   {
       use AmialHelperPatchTrait;   // ← يستبدل: pin_check, updateEmoney,
                                    //    setEnvironmentValue, send_transaction_notification,
                                    //    getAccessToken, get_admin_id, upload
       // ... بقية دوال Helpers الأصلية تبقى كما هي
   }
   ```
3. احذف من `Helpers.php` الأصلي الدوال السبع المذكورة أعلاه (لأن الـ trait يوفّرها).
   إن أردت الإبقاء على منطق GD resize داخل `upload()`، انقله إلى الموضع المُعلّم
   داخل الـ trait بدل حذفه.
4. تأكد من تعريف disk باسم `private` في `config/filesystems.php` (مطلوب لـ KYC/receipts):
   ```php
   'private' => [
       'driver' => 'local',
       'root'   => storage_path('app/private'),
       'visibility' => 'private',
       'throw'  => false,
   ],
   ```

## الدوال المُعدَّلة

| الدالة | التعديل |
|---|---|
| `pin_check` | PIN منفصل عن password عبر `TransactionPinService` |
| `updateEmoney` | إصلاح bug `charge_earned` + `lockForUpdate` + `MoneyService` |
| `setEnvironmentValue` | منع الكتابة في production عبر `EnvironmentGuardService` |
| `send_transaction_notification` | إرسال async عبر `SendTransactionNotificationJob` |
| `getAccessToken` | cache عبر `FirebaseTokenService` |
| `get_admin_id` | cache لساعة |
| `upload` | فحص MIME ثلاثي + حدّ حجم + disk خاص + حذف EXIF |
| `translate()` | (دالة عامّة — انظر الأسفل) |

## دالة translate() العامّة

`translate()` دالة global (خارج class). الأصلية كانت تكتب على disk (injection +
race condition + I/O ثقيل). ضع هذه النسخة في نهاية `Helpers.php` (خارج الـ class)
بدل الأصلية:

```php
function translate(string $key): string
{
    $local = session()->has('local') ? session('local') : 'en';
    \Illuminate\Support\Facades\App::setLocale($local);

    $cacheKey = 'amial:lang:' . $local;
    $lang_array = \Illuminate\Support\Facades\Cache::remember(
        $cacheKey,
        now()->addMinutes(30),
        function () use ($local) {
            $path = base_path('resources/lang/' . $local . '/messages.php');
            return file_exists($path) ? include($path) : [];
        }
    );

    if (!array_key_exists($key, $lang_array)) {
        \Illuminate\Support\Facades\Log::debug('translate: missing key', ['key' => $key, 'locale' => $local]);
        return ucfirst(str_replace('_', ' ', \App\CentralLogics\Helpers::remove_invalid_charcaters($key)));
    }

    return __('messages.' . $key);
}
```
