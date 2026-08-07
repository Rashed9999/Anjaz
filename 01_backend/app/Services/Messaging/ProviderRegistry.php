<?php

namespace App\Services\Messaging;

use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-MESSAGING-001 — سجلُّ المزوّدين: يجدهم، ويرتّبهم، ويُجرّبهم.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **حلقةُ الاختيار كانت مكتوبةً ثلاثَ مرّاتٍ في ملفٍّ واحد** —
 * في `hasEnabledProvider()` و`send()` و`sendText()` — وكلُّها تقرأ الثابت
 * `PROVIDERS` نفسَه ثمّ تتفرّع في `match` أو `switch`.
 *
 * فصارت هنا مرّةً واحدة، **والاكتشافُ بالمسح لا بقائمة**: يُقرأ مجلّدُ
 * `Providers/`، فمن أسقط فيه صنفاً صار مزوّداً. ولا ثابتَ يُحرَّر، ولا
 * `match` يُضاف إليه فرع.
 *
 * وهذا هو Open/Closed حرفيّاً: **مفتوحٌ للإضافة، مغلقٌ على التعديل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وفرقٌ سلوكيٌّ مقصود عن القديم — وهو إصلاحُ عطل:**
 *
 * كانت الحلقةُ القديمة تُرجع من **أوّل مزوّدٍ مُفعَّل** حتى لو فشل:
 *
 *     if ($config['status'] === 1) { return self::twilio(…); }   ← return
 *
 * فمزوّدٌ مُفعَّلٌ ومعطوبٌ **يمنع من بعده من أن يُجرَّب**. وبمزوّدٍ واحدٍ
 * معطوبٍ يُقفل التسجيل على كلّ عميلٍ جديد، والسجلُّ يقول «فشل twilio» ولا
 * يقول إنّ `ultramsg` كان جاهزاً ولم يُسأل.
 *
 * فصار يُجرَّب التالي عند الفشل. والقناةُ لا تُعلن العجز إلّا بعد أن
 * يُجرَّب كلُّ مُفعَّلٍ فيها.
 */
class ProviderRegistry
{
    /** @var array<string,MessageProvider>|null */
    private ?array $cache = null;

    /**
     * كلُّ المزوّدين المكتشفين، مرتَّبين بأولويّاتهم.
     *
     * @return array<int,MessageProvider>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return array_values($this->cache);
        }

        $found = [];

        foreach (['Sms', 'Whatsapp'] as $dir) {
            $path = __DIR__ . '/Providers/' . $dir;

            if (!is_dir($path)) {
                continue;
            }

            foreach (glob($path . '/*.php') ?: [] as $file) {
                $class = __NAMESPACE__ . '\\Providers\\' . $dir . '\\' . basename($file, '.php');

                if (!class_exists($class)) {
                    continue;
                }

                $ref = new \ReflectionClass($class);

                // **يُتجاهل ما لا يُنفّذ العقد وما هو مجرَّد** — فملفٌّ
                // مساعدٌ في المجلّد لا يصير مزوّداً بالخطأ.
                if ($ref->isAbstract() || !$ref->implementsInterface(MessageProvider::class)) {
                    continue;
                }

                /** @var MessageProvider $p */
                $p = app($class);
                $found[$p->key() . '@' . $p->channel()] = $p;
            }
        }

        uasort($found, fn (MessageProvider $a, MessageProvider $b) => $a->priority() <=> $b->priority());

        $this->cache = $found;

        return array_values($found);
    }

    /**
     * المزوّدون المُفعَّلون في قناةٍ ما، بالترتيب.
     *
     * @return array<int,MessageProvider>
     */
    public function enabledFor(string $channel): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (MessageProvider $p) => $p->channel() === $channel && $p->isEnabled()
        ));
    }

    public function hasEnabled(string $channel): bool
    {
        return $this->enabledFor($channel) !== [];
    }

    /**
     * يُجرّب مزوّدي القناة بالترتيب حتّى ينجح أحدهم.
     *
     * @return string `success` · `error` · `not_found`
     */
    public function sendOtp(string $channel, string $to, string $otp): string
    {
        $providers = $this->enabledFor($channel);

        if ($providers === []) {
            Log::warning("messaging: لا مزوّدَ مُفعَّلاً في قناة {$channel}");

            return 'not_found';
        }

        foreach ($providers as $p) {
            if ($p->sendOtp($to, $otp)) {
                return 'success';
            }
        }

        return 'error';
    }

    /**
     * يُجرّب مزوّدي النصّ الحرّ في القناة.
     *
     * **ومن لا يُعلن `SupportsFreeText` يُتخطّى** — لا يُستدعى فيُرجع
     * نجاحاً كاذباً. (فصلُ الواجهات: من لا يحتاجها لا يُنفّذها.)
     */
    public function sendText(string $channel, string $to, string $message): string
    {
        $providers = array_filter(
            $this->enabledFor($channel),
            fn (MessageProvider $p) => $p instanceof SupportsFreeText
        );

        if ($providers === []) {
            Log::warning("messaging: لا مزوّدَ نصٍّ حرٍّ مُفعَّلاً في {$channel}");

            return 'not_found';
        }

        foreach ($providers as $p) {
            /** @var SupportsFreeText&MessageProvider $p */
            if ($p->sendText($to, $message)) {
                return 'success';
            }
        }

        return 'error';
    }

    /** تُنسى النسخةُ المحفوظة — يُستعمل في الاختبارات بعد زرع مزوّد. */
    public function forget(): void
    {
        $this->cache = null;
    }
}
