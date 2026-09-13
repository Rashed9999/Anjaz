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
 *
 * AMIAL-PROD-READINESS-005 — الإنذار الخارجي ليس مجرد «وقع عطل».
 * الرسالة تحمل سياقاً تشغيلياً آمناً يكفي لاتخاذ أول قرار: مستوى الخطورة،
 * المكوّن، وقت الاكتشاف، المرجع، البيئة، التفاصيل الآمنة، الإجراء المقترح
 * ورابط مركز الصحة. ولا تُرسل بيانات عميل أو أسرار أو رموز تحقق.
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

        $payload = $this->buildExternalPayload($key, $title, $detail);
        $sent = false;

        // واتساب قناة مستقلة؛ نتيجة المزود تُقرأ ولا يكفي غياب الاستثناء.
        foreach ($numbers as $number) {
            try {
                $result = WhatsappModule::sendText(
                    (string) $number,
                    $this->whatsappText($payload)
                );
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
                if ($this->sendEmail((string) $address, $payload)) {
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
    private function sendEmail(string $address, array $payload): bool
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

            $text = view('emails.ops-alert', $payload)->render();
            $html = view('emails.ops-alert-html', $payload)->render();

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(12)
                ->retry(2, 250, throw: false)
                ->post($apiUrl, [
                    'from' => $fromName . ' <' . $fromAddress . '>',
                    'to' => [$address],
                    'subject' => $payload['subject'],
                    'text' => $text,
                    'html' => $html,
                    'headers' => [
                        'X-Amial-Category' => 'ops-alert',
                        'X-Amial-Alert-Key' => $payload['alertKey'],
                        'X-Amial-Alert-Reference' => $payload['reference'],
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

        Mail::to($address)->send(new \App\Mail\OpsAlertMail($payload));
        return true;
    }

    /**
     * يبني سياقاً خارجياً آمناً. التفاصيل نفسها تأتي من نقاط الإنذار
     * التشغيلية التي لا تمرّر PII؛ ونضيف هنا بيانات النظام فقط.
     *
     * @return array<string,string>
     */
    private function buildExternalPayload(string $key, string $title, string $detail): array
    {
        $now = now();
        [$severityCode, $severityLabel] = $this->severity($key);
        $dashboardUrl = rtrim((string) config('app.url', 'https://amialpay.com'), '/')
            . '/admin/amial/system/health';
        $reference = 'OPS-' . strtoupper(substr(hash(
            'sha256',
            $key . '|' . $now->format('YmdHi')
        ), 0, 10));

        return [
            'subject' => '[' . $severityLabel . '] أميال باي — ' . $title,
            'alertKey' => $key,
            'alertTitle' => $title,
            'detail' => trim($detail),
            'severityCode' => $severityCode,
            'severityLabel' => $severityLabel,
            'component' => $this->componentLabel($key),
            'detectedAt' => $now->format('Y-m-d H:i:s P'),
            'environment' => (string) config('app.env', 'production'),
            'reference' => $reference,
            'recommendedAction' => $this->recommendedAction($key),
            'dashboardUrl' => $dashboardUrl,
            'logoUrl' => (string) config(
                'amial_otp.resend.brand_logo_url',
                rtrim((string) config('app.url', 'https://amialpay.com'), '/') . '/branding/logo.png'
            ),
        ];
    }

    /** @return array{0:string,1:string} */
    private function severity(string $key): array
    {
        if (str_contains($key, 'selftest')) {
            return ['test', 'اختبار'];
        }

        if (str_starts_with($key, 'health.down.')
            || str_contains($key, 'diverged')
            || str_contains($key, 'integrity')
            || str_contains($key, 'corrupt')) {
            return ['critical', 'حرج'];
        }

        if (str_starts_with($key, 'backup.')
            || str_contains($key, 'failed')
            || str_contains($key, 'stale')) {
            return ['high', 'عالٍ'];
        }

        return ['warning', 'تحذير'];
    }

    private function componentLabel(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'recon.') => 'المصالحة المالية والدفتر',
            str_starts_with($key, 'health.') => 'صحة النظام',
            str_starts_with($key, 'backup.') => 'النسخ الاحتياطي',
            str_starts_with($key, 'recovery.') => 'استعادة الحسابات',
            str_starts_with($key, 'security.') => 'الأمن',
            str_starts_with($key, 'ops.') => 'التشغيل والمراقبة',
            default => 'منصة أميال باي',
        };
    }

    private function recommendedAction(string $key): string
    {
        if (str_contains($key, 'selftest')) {
            return 'لا إجراء مطلوب. هذه رسالة اختبار لإثبات أن قناة الإنذار تصل فعلياً.';
        }

        if (str_starts_with($key, 'recon.')) {
            return 'افتح صحة النظام ثم راجع المصالحة والدفتر قبل تنفيذ أي تسوية أو حركة تصحيحية.';
        }

        if (str_starts_with($key, 'health.')) {
            return 'افتح صحة النظام وحدد المكوّن المتعثر وراجع آخر ظهور له قبل إعادة التشغيل أو التدخل اليدوي.';
        }

        if (str_starts_with($key, 'backup.')) {
            return 'راجع آخر نسخة احتياطية صالحة ووجهتها الخارجية، ولا تعتبر النسخ سليماً قبل إثبات قابلية الاستعادة.';
        }

        return 'افتح مركز صحة النظام ومركز الأعطال، راجع المرجع والتفاصيل، ثم وثّق الإجراء المتخذ.';
    }

    /** @param array<string,string> $payload */
    private function whatsappText(array $payload): string
    {
        return '🚨 أميال باي — ' . $payload['severityLabel'] . "\n"
            . $payload['alertTitle'] . "\n"
            . 'المكوّن: ' . $payload['component'] . "\n"
            . 'الوقت: ' . $payload['detectedAt'] . "\n"
            . 'المرجع: ' . $payload['reference'] . "\n\n"
            . $payload['detail'] . "\n\n"
            . 'الإجراء: ' . $payload['recommendedAction'] . "\n"
            . $payload['dashboardUrl'];
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
