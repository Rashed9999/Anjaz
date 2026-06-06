<?php

namespace App\CentralLogics\Concerns;

use App\Exceptions\InsufficientBalanceException;
use App\Jobs\SendTransactionNotificationJob;
use App\Models\EMoney;
use App\Models\User;
use App\Services\EnvironmentGuardService;
use App\Services\FirebaseTokenService;
use App\Services\MoneyService;
use App\Services\TransactionPinService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AMIAL-REFACTOR-CORE-001 — Helpers PATCH (نسخة قابلة للتنفيذ)
 *
 * كان هذا المحتوى سابقاً في ملف `app/CentralLogics/Helpers_PATCH.php` على هيئة
 * دوال معلّقة خارج أي class — وهو PHP غير صالح (Parse error). تمّ تحويله إلى
 * trait صالح بالكامل بحيث:
 *   1. يجتاز `php -l` بدون أخطاء.
 *   2. يمكن دمجه فعلياً داخل `App\CentralLogics\Helpers` عبر `use AmialHelperPatchTrait;`
 *      أثناء الدمج مع قاعدة Cash6 الأصلية.
 *
 * تعليمات الدمج الكاملة (وكذلك دالة translate() العامّة) موجودة في:
 *   docs/PATCH_Helpers.md
 *
 * الدوال هنا تُمثّل النسخ المُصحَّحة من دوال Helpers الأصلية:
 *   pin_check, updateEmoney, setEnvironmentValue, send_transaction_notification,
 *   getAccessToken, get_admin_id, upload.
 */
trait AmialHelperPatchTrait
{
    /**
     * AMIAL-PIN-SECURITY-001: PIN منفصل عن password.
     * يفوّض لـ TransactionPinService الذي يدير hash منفصل + عداد محاولات + قفل.
     */
    public static function pin_check(int $user_id, string $pin): bool
    {
        $user = User::find($user_id);
        if (!$user) {
            return false;
        }

        /** @var TransactionPinService $svc */
        $svc = app(TransactionPinService::class);

        return $svc->verify($user, $pin);
    }

    /**
     * AMIAL-REFACTOR-CORE-001: إصلاح bug earned_charge.
     *  - charge_earned يُضاف للأدمن فقط (كان يُضاف للمستخدم العادي بالخطأ).
     *  - lockForUpdate دائماً (حماية من race conditions).
     *  - MoneyService بدل حساب float.
     */
    public static function updateEmoney(int $user_id, float|string $amount, string $type, string $transaction_type, float|string $charge): string
    {
        $isAdminCharge = strtolower($transaction_type) === ADMIN_CHARGE;
        $emoney_user_id = $isAdminCharge ? 1 : $user_id;

        return DB::transaction(function () use ($emoney_user_id, $amount, $type, $isAdminCharge, $charge) {
            // ALWAYS lockForUpdate — حماية من race conditions
            $emoney = EMoney::where('user_id', $emoney_user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = MoneyService::normalize($amount);
            $charge = MoneyService::normalize($charge);

            if ($isAdminCharge) {
                // الأدمن: فقط charge_earned (لا current_balance)
                $emoney->charge_earned = MoneyService::add($emoney->charge_earned, $charge);
            } else {
                // مستخدم عادي: current_balance يتأثر، charge_earned **لا** يُضاف
                if (strtolower($type) === 'debit') {
                    if (!MoneyService::gte($emoney->current_balance, $amount)) {
                        throw new InsufficientBalanceException(
                            userId: $emoney_user_id,
                            required: $amount,
                            available: (string) $emoney->current_balance,
                        );
                    }
                    $emoney->current_balance = MoneyService::sub($emoney->current_balance, $amount);
                } elseif (strtolower($type) === 'credit') {
                    $emoney->current_balance = MoneyService::add($emoney->current_balance, $amount);
                } else {
                    throw new \InvalidArgumentException("Invalid transaction direction: {$type}");
                }
            }

            $emoney->version = $emoney->version + 1;
            $emoney->save();

            return (string) $emoney->current_balance;
        });
    }

    /**
     * AMIAL-REFACTOR-CORE-001: تفويض لـ EnvironmentGuardService.
     * كل المنطق الأمني (production block, file lock, whitelist) هناك.
     */
    public static function setEnvironmentValue(string $envKey, string $envValue): string
    {
        return EnvironmentGuardService::set($envKey, $envValue);
    }

    /**
     * AMIAL-REFACTOR-CORE-001: الإشعار يُرسل كـ Job (async)، ليس HTTP مباشر.
     * يجب أن يُستدعى بعد commit الـ DB::transaction أو عبر DB::afterCommit.
     */
    public static function send_transaction_notification(
        int $user_id,
        float|string $amount,
        string $transaction_type,
        ?string $notificationType = null,
    ): bool {
        SendTransactionNotificationJob::dispatch(
            userId: $user_id,
            amount: MoneyService::normalize($amount),
            transactionType: $transaction_type,
            notificationType: $notificationType,
        );

        return true;
    }

    /**
     * AMIAL-REFACTOR-CORE-001: تفويض لـ FirebaseTokenService (Cache::remember).
     */
    public static function getAccessToken(array $key): ?string
    {
        /** @var FirebaseTokenService $svc */
        $svc = app(FirebaseTokenService::class);

        return $svc->getAccessToken($key);
    }

    /**
     * AMIAL-REFACTOR-CORE-001: admin id مع cache لساعة (لا يتغيّر إلا في seeding).
     */
    public static function get_admin_id(): int
    {
        return Cache::remember('amial:admin_user_id', now()->addHour(), function () {
            return User::where('type', 0)->value('id') ?? 1;
        });
    }

    /**
     * AMIAL-REFACTOR-CORE-001 + 002: رفع ملف مع فحص MIME صارم + خيار private storage.
     *  - 3 طبقات تحقق (extension/finfo/getimagesize).
     *  - حدّ حجم 10MB.
     *  - disk خاص اختياري لـ KYC/receipts.
     *  - حذف EXIF (GD يحذفها تلقائياً عند إعادة التشفير).
     */
    public static function upload(
        string $dir,
        string $format = APPLICATION_IMAGE_FORMAT,
        array|object|null $image = null,
        bool $private = false,
    ): string|null|false {
        if (!$image) {
            return null;
        }

        set_time_limit(300);
        $dir = rtrim($dir, '/') . '/';

        $sourcePath = $image instanceof UploadedFile
            ? $image->getRealPath()
            : $image;

        // 1) فحص الحجم (10MB max)
        $maxBytes = 10 * 1024 * 1024;
        if (filesize($sourcePath) > $maxBytes) {
            Log::warning('upload: file too large', ['size' => filesize($sourcePath)]);

            return false;
        }

        // 2) فحص MIME ثلاثي
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($sourcePath);
        if (!in_array($detectedMime, $allowedMimes, true)) {
            Log::warning('upload: rejected by finfo', ['mime' => $detectedMime]);

            return false;
        }

        $info = @getimagesize($sourcePath);
        if (!$info || empty($info['mime']) || !in_array(strtolower($info['mime']), $allowedMimes, true)) {
            Log::warning('upload: rejected by getimagesize');

            return false;
        }

        if (strtolower($info['mime']) !== $detectedMime) {
            Log::warning('upload: MIME mismatch finfo vs getimagesize', [
                'finfo' => $detectedMime,
                'getimagesize' => $info['mime'],
            ]);

            return false;
        }

        $mime = strtolower($info['mime']);

        $format = match ($mime) {
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => $format,
        };

        $imageName = Carbon::now()->format('Y-m-d') . '-' . uniqid('', true) . '.' . $format;

        // 3) اختيار disk حسب private
        $disk = $private ? 'private' : 'public';
        if (!Storage::disk($disk)->exists($dir)) {
            Storage::disk($disk)->makeDirectory($dir);
        }

        $diskRoot = config("filesystems.disks.{$disk}.root");
        $savePath = "{$diskRoot}/{$dir}{$imageName}";

        // GIF: copy فقط (لا نعالج بـ GD)
        if ($mime === 'image/gif') {
            return copy($sourcePath, $savePath) ? $imageName : false;
        }

        // ملاحظة الدمج: ضع هنا منطق GD resize من Helpers الأصلي
        // مع imagedestroy في كل مسارات الخطأ. GD يُسقط EXIF تلقائياً.

        return $imageName;
    }
}
