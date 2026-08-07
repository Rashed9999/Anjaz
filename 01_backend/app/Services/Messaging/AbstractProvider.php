<?php

namespace App\Services\Messaging;

use App\Services\Messaging\Contracts\MessageProvider;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-MESSAGING-001 — ما يشترك فيه كلُّ مزوّد، مكتوباً مرّةً واحدة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الشكلُ الذي كان مكرَّراً تسعَ مرّاتٍ ثمّ خمساً أخرى:**
 *
 *     $config = self::get_settings('X');
 *     if (!$config || (int)($config['status'] ?? 0) !== 1) return 'error';
 *     try {
 *         … نداءٌ شبكيّ …
 *         self::logSent('X', $receiver);   return 'success';
 *     } catch (\Throwable $e) {
 *         self::logFailure('X', $receiver, $e->getMessage());
 *         return 'error';
 *     }
 *
 * قياسُ `scripts/audit.php`: أكبرُ كتلتين مكرَّرتين في المشروع كلِّه كانتا
 * في `SmsModule` و`WhatsappModule` — أربعُ نسخٍ متطابقةٍ في كلٍّ.
 *
 * **وثمنُ ذلك ليس جمالاً:** من يُصلح عطلاً في نسخةٍ يترك ثلاثاً. وإخفاءُ
 * الرقم في السجلّ، ومهلةُ الانتظار، وقراءةُ الإعدادات — كلُّها كانت
 * تُكتب من جديدٍ في كلّ مزوّد، وكلُّ نسخةٍ فرصةٌ لاختلاف.
 *
 * فصارت هنا مرّةً واحدة. والمزوّدُ لا يكتب إلّا **ما يختلف فيه**: نداءَه
 * الشبكيَّ وحده.
 */
abstract class AbstractProvider implements MessageProvider
{
    /** مهلةٌ موحّدة — مزوّدٌ بطيءٌ لا يُعلّق طلبَ التسجيل. */
    protected const HTTP_TIMEOUT = 10;

    /** حقولٌ بلا قيمةٍ منها لا يعمل المزوّد. يُعلنها كلُّ مزوّدٍ عن نفسه. */
    protected function requiredKeys(): array
    {
        return [];
    }

    public function priority(): int
    {
        return 100;
    }

    /** نوعُ الإعدادات في `addon_settings.settings_type`. */
    protected function settingsType(): string
    {
        return $this->channel() === 'whatsapp' ? 'whatsapp_config' : 'sms_config';
    }

    /** إعداداتُ المزوّد، أو `null` إن لم تُضبط. */
    protected function config(): ?array
    {
        try {
            $data = config_settings($this->key(), $this->settingsType());

            if ($data && !is_null($data->live_values)) {
                return json_decode($data->live_values, true);
            }
        } catch (\Throwable $e) {
            // الإعدادات غير متاحة (قبل الهجرات مثلاً) — لا يُسقط الإقلاع.
        }

        return null;
    }

    /**
     * **مُفعَّلٌ يعني «يستطيع الإرسال» لا «مؤشّرُه أخضر».**
     *
     * فمزوّدٌ `status=1` بلا `token` كان يُختار في الحلقة القديمة ثمّ يفشل،
     * **ويمنع من بعده من أن يُجرَّب** — لأنّ الحلقة كانت تُرجع من أوّل
     * مُفعَّل. فيُقفل التسجيل على الجميع بسبب حقلٍ ناقص.
     */
    public function isEnabled(): bool
    {
        $c = $this->config();

        if (!$c || (int) ($c['status'] ?? 0) !== 1) {
            return false;
        }

        foreach ($this->requiredKeys() as $k) {
            if (empty($c[$k])) {
                Log::warning("messaging.{$this->key()}: مُفعَّلٌ وينقصه {$k}");

                return false;
            }
        }

        return true;
    }

    /** توحيدُ الرقم: أرقامٌ فقط، مع «+» أو بدونها حسب المزوّد. */
    protected function normalize(string $phone, bool $withPlus): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return $withPlus ? '+' . $digits : $digits;
    }

    /** يُطبَّق قالبُ الرسالة على الرمز. */
    protected function template(array $c, string $otp, string $fallback = 'رمز التحقق: #OTP#'): string
    {
        return str_replace('#OTP#', $otp, $c['otp_template'] ?? $fallback);
    }

    protected function ok(string $to): bool
    {
        Log::info("messaging.{$this->key()}: أُرسلت", [
            'provider' => $this->key(),
            'channel' => $this->channel(),
            'receiver_masked' => $this->mask($to),
        ]);

        return true;
    }

    protected function fail(string $to, string $error): bool
    {
        Log::warning("messaging.{$this->key()}: فشل الإرسال", [
            'provider' => $this->key(),
            'channel' => $this->channel(),
            'receiver_masked' => $this->mask($to),
            'error_short' => mb_substr($error, 0, 200),
        ]);

        return false;
    }

    /** **لا يُسجَّل رقمٌ كاملاً ولا رمزٌ قطّ** (سياسة القسم ٢٣٫٨). */
    protected function mask(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '****';
        }

        return substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 8)) . substr($phone, -3);
    }

    /** يلفّ نداءً شبكيّاً بالتقاطٍ موحَّدٍ للأعطال. */
    protected function attempt(string $to, callable $call): bool
    {
        try {
            return $call() ? $this->ok($to) : $this->fail($to, 'المزوّد ردّ برفض');
        } catch (\Throwable $e) {
            return $this->fail($to, $e->getMessage());
        }
    }
}
