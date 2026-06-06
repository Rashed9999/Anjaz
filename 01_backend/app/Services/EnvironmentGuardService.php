<?php

namespace App\Services;

use App\Exceptions\EnvironmentMutationBlockedException;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * EnvironmentGuardService — يحكم متى يُسمح بتعديل .env في runtime.
 *
 * المشكلة: Cash6 يستدعي setEnvironmentValue من InstallController و UpdateController
 * في production runtime — راجع AUDIT_v0.6.md 1.8.
 *
 * السياسة:
 *   1. environment ∈ {local, testing} → مسموح
 *   2. environment = production و APP_ALLOW_ENV_MUTATION=true → مسموح، مع تحذير
 *   3. غير ذلك → exception
 *
 * APP_ALLOW_ENV_MUTATION ينبغي أن يكون false دائماً في production.
 * نتركه true فقط ساعات قليلة للنشر الأول، ثم false.
 *
 * بالإضافة: حتى عند السماح، نستخدم file lock (LOCK_EX) لمنع race condition.
 */
class EnvironmentGuardService
{
    /**
     * يكتب قيمة env بأمان. يرمي exception لو غير مسموح.
     */
    public function set(string $key, string $value): void
    {
        $this->ensureAllowed($key);
        $this->writeWithLock($key, $value);

        Log::warning("Environment value mutated", [
            'key' => $key,
            // لا نلوغ القيمة — قد تحتوي API_KEY/DB_PASSWORD
            'environment' => app()->environment(),
        ]);
    }

    /**
     * يفحص شرط السماح.
     *
     * @throws EnvironmentMutationBlockedException
     */
    private function ensureAllowed(string $key): void
    {
        $env = app()->environment();

        if (in_array($env, ['local', 'testing'], true)) {
            return;
        }

        // production: يجب APP_ALLOW_ENV_MUTATION=true
        $explicitlyAllowed = filter_var(env('APP_ALLOW_ENV_MUTATION', false), FILTER_VALIDATE_BOOLEAN);
        if (!$explicitlyAllowed) {
            throw new EnvironmentMutationBlockedException(
                attemptedKey: $key,
                currentEnvironment: $env,
                message: "Refused to mutate .env in '{$env}' environment. " .
                         "Set APP_ALLOW_ENV_MUTATION=true temporarily to allow."
            );
        }
    }

    /**
     * يكتب الـ value مع flock لمنع race conditions.
     * يستخدم نفس parsing logic الأصلي لكن atomic.
     */
    private function writeWithLock(string $key, string $value): void
    {
        $envFile = app()->environmentFilePath();

        if (!is_writable($envFile)) {
            throw new \RuntimeException(".env file is not writable: {$envFile}");
        }

        $fp = fopen($envFile, 'c+');
        if (!$fp) {
            throw new \RuntimeException("Could not open .env: {$envFile}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Could not acquire lock on .env");
            }

            // قراءة المحتوى الحالي
            fseek($fp, 0);
            $content = stream_get_contents($fp) ?: '';

            // escape القيمة (نضع quotes لو فيها space/special chars)
            $escapedValue = $this->escapeEnvValue($value);

            // regex بديل آمن من str_replace (الذي يفشل لو القيمة القديمة فارغة)
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$escapedValue}", $content);
            } else {
                // إضافة في النهاية
                if (!str_ends_with($content, "\n")) {
                    $content .= "\n";
                }
                $content .= "{$key}={$escapedValue}\n";
            }

            // إعادة الكتابة من الصفر
            ftruncate($fp, 0);
            fseek($fp, 0);
            fwrite($fp, $content);
            fflush($fp);

        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * يضع quotes حول القيمة لو فيها space أو # أو quotes.
     */
    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-\.\/@]+$/', $value)) {
            return $value;
        }
        // escape double quotes داخل القيمة
        $escaped = str_replace('"', '\\"', $value);
        return '"' . $escaped . '"';
    }
}
