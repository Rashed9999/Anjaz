<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use App\Services\Messaging\AbstractProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Illuminate\Support\Facades\Http;

/**
 * AMIAL-MESSAGING-002 — GREEN-API: نصٌّ حرٌّ بلا نافذةِ أربعٍ وعشرين ساعة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لمَ أُضيف:** إنذاراتُ التشغيل — فرقُ المصالحة الليليّة ٠٢:٠٠، وسقوطُ
 * قطعةٍ فجراً — **لا تصل عبر Meta Cloud ولا Twilio**: كلاهما يمنع النصَّ
 * الحرَّ خارج نافذة الأربع والعشرين ساعة من آخر رسالةٍ واردة. وفي الثانية
 * فجراً لا نافذةَ مفتوحة.
 *
 * فالمزوّدُ غيرُ الرسميّ (UltraMsg · Wati · هذا) هو الصالحُ للإنذارات:
 * يرسل متى شاء ما دام الهاتفُ مربوطاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **حدُّ ما تحقّقتُ منه — يُقال ولا يُدَّعى:**
 *
 * وسيطُ بيئة التطوير يحجب `green-api.com`، فلم أقرأ العقدَ من مصدره.
 * والشكلُ أدناه مبنيٌّ على أسماء الحقول التي تعرضها لوحةُ GREEN-API نفسُها
 * (`apiUrl` · `idInstance` · `apiTokenInstance`).
 *
 * **والإثباتُ بالتشغيل لا بالوثيقة:**
 *
 *     php artisan amial:alert-test
 *
 * وهو يقول «لم يصل» صراحةً إن كان الشكلُ خاطئاً — لأنّ `OpsAlertService`
 * صار يقرأ نتيجةَ `ProviderRegistry::sendText` ولا يكتفي بغياب الاستثناء.
 * فإن سقط، فسببُه في السجلّ ويُصحَّح الشكلُ هنا وحدَه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * يظهر في : لوحة الإدارة ← الإشعارات ← واتساب (عبر
 *           `GET/POST /api/v1/amial/admin/whatsapp/{config,provider}`)
 * ويُوصل إليه من : الاسمُ `green_api` في `WhatsappAdminController::PROVIDERS`
 *           — **وبدونه يُرفض الحفظُ ولا يُفعَّل الصنفُ أبداً**، وهو نمطُ
 *           «مبنيٌّ ولا يُوصَل إليه». الحارس: `MessagingProviderReachabilityTest`.
 */
class GreenApiProvider extends AbstractProvider implements SupportsFreeText
{
    public function key(): string { return 'green_api'; }

    public function channel(): string { return 'whatsapp'; }

    /**
     * أعلى من UltraMsg (٥٠) رقماً — أي **بعده** في الترتيب.
     *
     * فمن يشغّل الاثنين يبقى الأرسخُ أوّلاً، وهذا احتياطٌ خلفه.
     */
    public function priority(): int { return 55; }

    protected function requiredKeys(): array
    {
        return ['api_url', 'instance_id', 'token'];
    }

    public function sendOtp(string $to, string $otp): bool
    {
        return $this->chat($to, $this->template($this->config(), $otp));
    }

    public function sendText(string $to, string $message): bool
    {
        return $this->chat($to, $message);
    }

    private function chat(string $to, string $body): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $body) {
            $base = rtrim((string) $c['api_url'], '/');

            $url = sprintf('%s/waInstance%s/sendMessage/%s',
                $base, $c['instance_id'], $c['token']);

            $r = Http::asJson()->timeout(self::HTTP_TIMEOUT)->post($url, [
                // **الصيغةُ أرقامٌ مجرّدةٌ + `@c.us`** — لا `+` ولا فراغات.
                'chatId' => $this->normalize($to, false) . '@c.us',
                'message' => $body,
            ]);

            // **النجاحُ وجودُ معرّف الرسالة لا رمزُ ٢٠٠ وحدَه.** فبعض
            // الأخطاء تعود برمزٍ ناجحٍ وجسدٍ يحمل الرفض — وهو ما يجعل
            // «أُرسلت» كذبةً تُنهي البحث.
            return $r->successful() && ! empty($r->json()['idMessage'] ?? null);
        });
    }
}
