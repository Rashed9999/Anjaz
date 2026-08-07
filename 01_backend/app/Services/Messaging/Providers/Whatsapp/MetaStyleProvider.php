<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use App\Services\Messaging\AbstractProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Illuminate\Http\Client\Response;

/**
 * AMIAL-MESSAGING-001 — ما يشترك فيه مزوّدو Meta Graph.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قِيس فبان:** كان جسدا `sendOtp` في `MetaCloudProvider` و
 * `Dialog360Provider` **متطابقين حرفيّاً إلّا سطرَ تعليق**:
 *
 *     diff <(sed -n '/sendOtp/,/^    }/p' MetaCloudProvider.php) \
 *          <(sed -n '/sendOtp/,/^    }/p' Dialog360Provider.php)
 *     8d7
 *     <             // قوالبُ المصادقة في Meta تتطلّب زرّ «نسخ الرمز»…
 *
 * لأنّ 360dialog وسيطٌ لواجهة Meta نفسِها: القالبُ واحد، والمكوّناتُ
 * واحدة، وشرطُ النجاح واحد (`messages[0].id`). **ولا يختلفان إلّا في
 * العنوان وترويسة المصادقة.**
 *
 * فصار المشتركُ هنا مرّةً واحدة، ولا يكتب المزوّدُ إلّا `transport()`.
 *
 * **وهذا الصنف مجرَّد** — و`ProviderRegistry` يتخطّى المجرَّدات، فلا
 * يُحسب مزوّداً ولا يظهر في قائمة الاكتشاف.
 */
abstract class MetaStyleProvider extends AbstractProvider implements SupportsFreeText
{
    /** النقلُ الفعليّ — العنوانُ والترويسةُ وحدهما يختلفان. */
    abstract protected function transport(array $c, array $payload): Response;

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, fn () => $this->succeeded($this->transport($c, [
            'to' => $this->normalize($to, false),
            'type' => 'template',
            'template' => [
                'name' => $c['template_name'] ?? 'otp_code',
                'language' => ['code' => $c['lang_code'] ?? 'ar'],
                'components' => $this->otpComponents($c, $otp),
            ],
        ])));
    }

    public function sendText(string $to, string $message): bool
    {
        $c = $this->config();

        return $this->attempt($to, fn () => $this->succeeded($this->transport($c, [
            'to' => $this->normalize($to, false),
            'type' => 'text',
            'text' => ['body' => $message],
        ])));
    }

    /**
     * مكوّناتُ قالب المصادقة.
     *
     * **وزرّ «نسخ الرمز» مُفعَّلٌ افتراضاً** لأنّ Meta تشترطه في قوالب
     * المصادقة — وقالبٌ بلا زرٍّ تردّه الواجهة، فيُقرأ العطلُ «المزوّد
     * لا يعمل» بينما القالبُ وحده ناقص.
     */
    protected function otpComponents(array $c, string $otp): array
    {
        $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]]];

        if ($c['otp_button'] ?? true) {
            $components[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $otp]],
            ];
        }

        return $components;
    }

    /** Meta تُرجع `messages[0].id` عند القبول — و200 وحدها لا تكفي. */
    protected function succeeded(Response $r): bool
    {
        return $r->successful() && isset($r->json()['messages'][0]['id']);
    }
}
