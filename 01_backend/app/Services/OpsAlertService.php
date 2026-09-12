<?php

namespace App\Services;

use App\CentralLogics\WhatsappModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AMIAL-PROD-READINESS-001 — موضع واحد يرفع منه الإنذار التشغيلي.
 *
 * القاعدة: لا إنذار يسقط في الفراغ. الأثر يكتب أولاً في system_errors،
 * ثم تجرّب القنوات الخارجية المستقلة. غياب القناة نفسه يرفع كعطل ظاهر.
 *
 * AMIAL-PROD-READINESS-004 — البريد التشغيلي يعيد استخدام Resend المفعّل
 * أصلاً لرسائل OTP، ولا يحتاج SMTP إضافياً. إذا لم يكن Resend مهيأً يبقى
 * Laravel Mail/SMTP مساراً احتياطياً متوافقاً مع التركيبات القديمة.
 */
class OpsAlertService
{
    /** بصمة «لا قناة مضبوطة» — ثابتة فلا تتكرر صفوفها. */
    public const NO_CHANNEL_KEY = 'ops.alert_channel_missing';

    /**
     * يرفع إنذاراً تشغيلياً.
     *
     * @return bool هل خرج الإنذار من الخادم فعلاً عبر قناة خارجية؟
     */
    public function raise(string $key, string $title, string $detail): bool
    {
        // الأثر أولاً دائماً؛ سقوط الشبكة أو المزود لا يبتلع الحادثة.
        $this->trace($key, $title, $detail);

        $numbers = array_values(array_filter(
            (array) config('amial.reconciliation.alert_numbers', [])
        ));
        $emails = array_values(array_filter(
            (array) config('amial.reconciliation.alert_emails', [])
        ));

        if ($numbers === [] && $emails === []) {
            $this->trace(
                self::NO_CHANNEL_KEY,
                'لا قناةَ إنذارٍ خارجيّةٌ مضبوطة',
                'وقع إنذارٌ تشغيليٌّ ولا قناةَ تُوصِله. اضبط AMIAL_ALERT_EMAIL '
                . 'لاستخدام بريد Resend المفعّل في أميال (أو Laravel Mail/SMTP كاحتياط)، '
                . 'أو AMIAL_RECON_ALERT_TO لواتساب. وبعد الضبط أثبت الوصول '
                . 'بأمر php artisan amial:alert-test.',
            );

            Log::warning('ops-alert: لا قناة خارجية', [
                'key' => $key,
                'title' => $title,
            ]);

            return false;
        }

        $sent = false;

        // واتساب قناة مستقلة؛ نتيجة المزود تُقرأ ولا يكفي غياب الاستثناء.
        foreach ($numbers as $number) {
            try {
                $result = WhatsappModule::sendText((string) $number, $detail);
                if ($result === 'success') {
                    $sent = true;
                } else {
                    Log::warning('ops-alert: واتساب لم يصل', [
                        'key' => $key,
                        'result' => $result,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('ops-alert: تعذر إرسال واتساب', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // البريد قناة مستقلة وتُجرّب دائماً حتى لو نجح واتساب.
        foreach ($emails as $address) {
            try {
                if ($this->sendEmail((string) $address, $title, $detail)) {
                    $sent = true;
                }
            } catch (\Throwable $e) {
                Log::warning('ops-alert: تعذر إرسال البريد', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * يرسل البريد عبر Resend HTTP إذا كان مفتاحه موجوداً؛ وإلا يعود إلى
     * Laravel Mail حتى لا نكسر التركيبات القديمة التي تعتمد SMTP.
     */
    private function sendEmail(string $address, string $title, string $detail): bool
    {
        $address = mb_strtolower(trim($address));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            Log::warning('ops-alert: عنوان بريد الإنذار غير صالح');
            return false;
        }

        $apiKey = (string) config('amial_otp.resend.api_key', '');
        if ($apiKey !== '') {
            $fromAddress = (string) config('amial_otp.resend.from_address', 'verify@amialpay.com');
            $fromName = (string) config('amial_otp.resend.from_name', 'Amial Pay');
            $apiUrl = (string) config('amial_otp.resend.api_url', 'https://api.resend.com/emails');

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(12)
                ->retry(2, 250, throw: false)
                ->post($apiUrl, [
                    'from' => $fromName . ' <' . $fromAddress . '>',
                    'to' => [$address],
                    'subject' => 'أميال باي — ' . $title,
                    'text' => $detail
                        . "\n\nهذا تنبيه تشغيلي آلي. افتح لوحة الإدارة لمراجعة التفاصيل والأثر.",
                    'headers' => [
                        'X-Amial-Category' => 'ops-alert',
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('ops-alert: Resend رفض الرسالة', [
                    'status' => $response->status(),
                ]);
                return false;
            }

            // نجاح HTTP وحده غير كافٍ: Resend يعيد id عند قبول الرسالة.
            $providerId = (string) ($response->json('id') ?? '');
            if ($providerId === '') {
                Log::warning('ops-alert: Resend لم يعد message id');
                return false;
            }

            return true;
        }

        Mail::to($address)->send(new \App\Mail\OpsAlertMail($title, $detail));
        return true;
    }

    /** أثر بلا تنبيه خارجي. */
    public function note(string $key, string $title, string $detail): void
    {
        $this->trace($key, $title, $detail);
    }

    /**
     * يكتب الحادثة في system_errors. البصمة من المفتاح لا من النص حتى
     * تُعدّ التكرارات في صف واحد. والعطل الذي أُغلق ثم عاد يُفتح ثانية.
     */
    private function trace(string $key, string $title, string $detail): void
    {
        $fingerprint = hash('sha256', 'ops|' . $key);
        $now = now();

        try {
            $existing = DB::table('system_errors')
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing) {
                DB::table('system_errors')->where('id', $existing->id)->update([
                    'occurrences' => DB::raw('occurrences + 1'),
                    'last_seen_at' => $now,
                    'message' => mb_substr($title . ' — ' . $detail, 0, 2000),
                    'status_flag' => $existing->status_flag === 'resolved'
                        ? 'open'
                        : $existing->status_flag,
                    'updated_at' => $now,
                ]);

                return;
            }

            DB::table('system_errors')->insert([
                'fingerprint' => $fingerprint,
                'exception' => mb_substr($key, 0, 191),
                'message' => mb_substr($title . ' — ' . $detail, 0, 2000),
                'file' => null,
                'line' => null,
                'method' => null,
                'path' => null,
                'status' => null,
                'user_id' => null,
                'actor_type' => null,
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'status_flag' => 'open',
                'trace_head' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
            // أداة المراقبة لا تغيّر نتيجة التدفق الرئيسي إذا تعذر تسجيلها.
        }
    }

    /** هل توجد وجهة خارجية مضبوطة؟ الوصول نفسه يثبته amial:alert-test. */
    public static function hasExternalChannel(): bool
    {
        return array_filter((array) config('amial.reconciliation.alert_numbers', [])) !== []
            || array_filter((array) config('amial.reconciliation.alert_emails', [])) !== [];
    }
}
