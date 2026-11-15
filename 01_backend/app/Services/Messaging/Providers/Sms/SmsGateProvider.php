<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-MESSAGING-003 — SMSGate (sms-gate.app): بوّابةُ رسائلٍ على هاتف أندرويد.
 *
 * ═══════════════════════════════════════════════════════
 * **ما هو، ولماذا يختلف عن بقيّة مزوّدي الرسائل:**
 *
 * الرسالةُ لا تخرج من خادمِ مزوّدٍ بل من **شريحةٍ في هاتفٍ تملكه أميال**.
 * فالخادمُ السحابيّ (`api.sms-gate.app`) يستلم الطلب ويضعه في طابور
 * الهاتف، والهاتفُ يُرسله. والأثرُ العمليّ ثلاثة:
 *
 *   ① **«قُبل» ليس «وصل».** الردُّ `202 Pending` يعني أنّ الطلب دخل
 *      طابور الجهاز — لا أنّ الشريحة أرسلت. فهذا المزوّدُ يُرجع `true`
 *      عند القبول، **ويُسجَّل معرّفُ الرسالة** ليُستعلم عنه (`messageState`)
 *      أو يُتتبَّع بخطّاف التسليم لاحقاً. (راجع المهمة في `plan.md`.)
 *   ② **الهاتفُ نقطةُ فشلٍ مادّيّة:** بطّاريّة، شبكة، رصيدُ الشريحة، حدُّ
 *      الرسائل في الساعة. لذا هو في الأولويّة الأولى **ويُجرَّب بعده
 *      غيرُه** (السجلُّ يُجرّب التالي عند الفشل) — لا يُقفل التسجيل عليه.
 *   ③ **مدّةُ الصلاحيّة (`ttl`) جزءٌ من الأمان:** رمزٌ ينتظر في طابورِ
 *      هاتفٍ مطفأ ساعةً ثمّ يصل بعد أن انتهى OTP الخادم يربك المستعمل.
 *      فالافتراضيُّ ٣٠٠ ثانية، ويُسقطه الجهازُ بعدها.
 *
 * ═══════════════════════════════════════════════════════
 * **المصادقة:** Basic (اسم مستخدم + كلمة مرور) كما في `ApiAuth` بالعقد.
 * وكلمةُ المرور تُقنَّع في اللوحة (`AdminSettingsController::SECRET_KEYS`)
 * ولا تُسجَّل ولا يظهر في السجلّ رمزٌ ولا رقمٌ كاملان (`AbstractProvider`).
 *
 * **والعنوانُ يُقبل `https` وحده:** حقلٌ يكتبه مدير اللوحة ويطلبه الخادم —
 * فقبولُ `http://` أو عنوانٍ داخليٍّ يفتح بابَ طلباتٍ من الخادم إلى
 * شبكته (SSRF). الخادمُ المحلّيّ على الهاتف `http://<ip>:8080` غيرُ
 * مدعومٍ عمداً: لا يُوصَل إليه من خادمٍ في مركز بيانات أصلاً.
 *
 * العقد: `capcom6/android-sms-gateway` — `app/src/main/assets/api/swagger.json`
 * (`POST /messages` ← `smsgateway.Message` · `202 GetMessageResponse`).
 */
class SmsGateProvider extends AbstractProvider
{
    public const DEFAULT_BASE_URL = 'https://api.sms-gate.app/3rdparty/v1';

    /** صلاحيّةُ الرسالة في طابور الجهاز بالثواني — الحدُّ الأدنى في العقد ٥. */
    public const DEFAULT_TTL = 300;

    private const MIN_TTL = 30;
    private const MAX_TTL = 3600;

    public function key(): string { return 'sms_gate'; }

    public function channel(): string { return 'sms'; }

    /** أوّلاً: شريحةٌ محلّيّةٌ أرخصُ من مزوّدٍ عالميّ. وعند فشله يُجرَّب التالي. */
    public function priority(): int { return 10; }

    protected function requiredKeys(): array { return ['username', 'password']; }

    public function isEnabled(): bool
    {
        if (!parent::isEnabled()) {
            return false;
        }

        if ($this->baseUrl($this->config() ?? []) === null) {
            Log::warning('messaging.sms_gate: مُفعَّلٌ وعنوانُه غيرُ مقبول (https وحده، بلا بيانات اعتماد داخله)');

            return false;
        }

        return true;
    }

    public function sendOtp(string $to, string $otp): bool
    {
        $this->lastError = '';
        $c = $this->config() ?? [];
        $base = $this->baseUrl($c);

        if ($base === null) {
            return $this->fail($to, 'عنوانُ الخادم غيرُ مقبول');
        }

        return $this->attempt($to, function () use ($c, $base, $to, $otp) {
            $r = $this->client($c)->post($base . '/messages', $this->payload($c, $to, $otp));

            if (!$r->successful()) {
                // الجسمُ قد يحمل الرقمَ — يُسجَّل الرمزُ وحده (`fail()` يقصّ الطول).
                $this->lastError = 'HTTP ' . $r->status();

                return false;
            }

            $id = (string) ($r->json('id') ?? '');
            $state = (string) ($r->json('state') ?? '');

            // ردٌّ ناجحٌ بلا معرّفٍ أو بحالةٍ فاشلةٍ ليس قبولاً.
            if ($id === '' || strcasecmp($state, 'Failed') === 0) {
                $this->lastError = 'ردٌّ بلا معرّف أو بحالة ' . ($state ?: 'مجهولة');

                return false;
            }

            Log::info('messaging.sms_gate: دخلت طابورَ الجهاز', [
                'message_id' => $id,
                'state' => $state,
                'receiver_masked' => $this->mask($to),
            ]);

            return true;
        });
    }

    /**
     * حالةُ رسالةٍ أُرسلت — `Pending` · `Processed` · `Sent` · `Delivered` · `Failed`.
     *
     * @return array{id:string,state:string,recipients:array}|null `null` إن تعذّر الاستعلام.
     */
    public function messageState(string $id): ?array
    {
        $c = $this->config() ?? [];
        $base = $this->baseUrl($c);

        if ($base === null || $id === '') {
            return null;
        }

        try {
            $r = $this->client($c)->get($base . '/messages/' . rawurlencode($id));

            if (!$r->successful()) {
                return null;
            }

            return [
                'id' => (string) $r->json('id'),
                'state' => (string) $r->json('state'),
                'recipients' => (array) $r->json('recipients', []),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * فحصٌ للاتّصال والمصادقة **دون إرسال رسالة**: يسرد الأجهزة المسجَّلة.
     *
     * @return array{ok:bool,status:int|null,devices:int|null,error:string|null}
     */
    public function probe(): array
    {
        $c = $this->config() ?? [];
        $base = $this->baseUrl($c);

        if ($base === null) {
            return ['ok' => false, 'status' => null, 'devices' => null, 'error' => 'عنوانٌ غيرُ مقبول أو غيرُ مضبوط'];
        }

        try {
            $r = $this->client($c)->get($base . '/devices');

            return [
                'ok' => $r->successful(),
                'status' => $r->status(),
                'devices' => $r->successful() ? count((array) $r->json()) : null,
                'error' => $r->successful() ? null : 'HTTP ' . $r->status(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'devices' => null, 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /** نصُّ آخر فشل — يُستعمل في `fail()` ولا يحمل سرّاً. */
    private string $lastError = '';

    protected function fail(string $to, string $error): bool
    {
        return parent::fail($to, $this->lastError !== '' ? $this->lastError : $error);
    }

    private function client(array $c): PendingRequest
    {
        return Http::withBasicAuth((string) $c['username'], (string) $c['password'])
            ->acceptJson()
            ->asJson()
            ->timeout(self::HTTP_TIMEOUT);
    }

    /** جسمُ `POST /messages` وفق `smsgateway.Message`. */
    private function payload(array $c, string $to, string $otp): array
    {
        $body = [
            'textMessage' => ['text' => $this->template($c, $otp)],
            'phoneNumbers' => [$this->normalize($to, true)],
            'ttl' => $this->ttl($c),
            'withDeliveryReport' => true,
        ];

        $device = trim((string) ($c['device_id'] ?? ''));
        if ($device !== '') {
            $body['deviceId'] = $device;
        }

        $sim = (int) ($c['sim_number'] ?? 0);
        if ($sim >= 1 && $sim <= 3) {
            $body['simNumber'] = $sim;
        }

        return $body;
    }

    private function ttl(array $c): int
    {
        $t = (int) ($c['ttl'] ?? self::DEFAULT_TTL);

        return max(self::MIN_TTL, min(self::MAX_TTL, $t > 0 ? $t : self::DEFAULT_TTL));
    }

    /** العنوانُ المقبول بلا شرطةٍ ختاميّة، أو `null`. */
    private function baseUrl(array $c): ?string
    {
        $url = trim((string) ($c['base_url'] ?? '')) ?: self::DEFAULT_BASE_URL;
        $p = parse_url($url);

        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }

        // عنوانُ IP داخليٌّ أو محجوزٌ أو localhost ⇒ مرفوض (SSRF). والاسمُ
        // الذي يُحلَّ إلى داخليٍّ لا يُمسك هنا — يُمسكه جدارُ الشبكة.
        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP)
                && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }

        return rtrim($url, '/');
    }
}
