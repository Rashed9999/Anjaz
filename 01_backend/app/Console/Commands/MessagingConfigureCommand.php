<?php

namespace App\Console\Commands;

use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-MESSAGING-002 — **عشرةُ مزوّدين مبنيّون، وصفرُ طريقٍ لتشغيل واحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *     مزوّدو الرسائل     →  ١٠ أصنافٍ مكتملة (Meta Cloud · 360Dialog ·
 *                            GreenAPI · Twilio×٢ · Nexmo · Msg91 · 2Factor)
 *     ProviderRegistry   →  سجلٌّ وسلسلةُ احتياطٍ بالأولويّة
 *     isEnabled()        →  تقرأ `addon_settings`
 *     addon_settings     →  **صفرُ صفوف**
 *     من يكتب فيه؟       →  **لا متحكّمَ واحدٌ في المشروع كلِّه**
 *
 * ⇒ كلُّ مزوّدٍ مُعطَّلٌ بالضرورة ⇒ `deliveryReady()` كاذبةٌ أبداً ⇒
 * **لا رقمَ حقيقيٍّ يستطيع التسجيل** — ولا سبيلَ لصاحب المشروع أن
 * يُفعّل قناةً ولو أراد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا أعمقُ «مبنيٌّ ولا يُوصَل إليه» في المشروع**: ليس دالّةً بلا
 * مُنادٍ، بل **طبقةً كاملةً بلا مفتاحِ تشغيل**. وأثرُها أنّ التجربةَ
 * لا تبدأ: أرقامُ العرض وحدَها تعمل، وكلُّ عميلٍ حقيقيٍّ يقف على
 * البابِ الأوّل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ أمرٌ لا شاشة:** المفاتيحُ أسرار (`access_token` · `sid` ·
 * `token`). وحقلُ سرٍّ في شاشةٍ يُخزَّن في سجلّ الوصول، ويُلتقَط في
 * صورةٍ للشاشة، ويمرّ في سجلّ الخادم. **والطرفيّةُ في Coolify هي
 * الموضعُ الذي يضع فيه صاحبُ المشروع أسرارَه أصلاً** — وهو نفسُ نمط
 * `wizard-production-blockers.sh`.
 *
 * ولا يُطبَع سرٌّ ولا يُعاد عرضُه: يُقال «مضبوط» أو «ناقص».
 *
 * ══════════════════════════════════════════════════════════════════════
 * يظهر في : الطرفيّة — `php artisan amial:messaging`. **وأثرُه يظهر**
 * في التطبيق ← التسجيل (يعمل بدل ٥٠٣)، وفي لوحة الإدارة ← الامتثال
 * والمخاطر ← صحّة النظام.
 * ويُوصل إليه من : `scripts/wizard-production-blockers.sh` — المرحلةُ
 * الأولى، فهي الحاجزُ الأوّل.
 */
class MessagingConfigureCommand extends Command
{
    protected $signature = 'amial:messaging
        {--set= : مفتاحُ المزوّد، مثل meta_cloud أو green_api}
        {--channel= : whatsapp أو sms — يلزم حين يشترك مفتاحٌ في القناتين}
        {--value=* : زوجٌ key=value، يُكرَّر لكلّ مفتاح}
        {--enable : يُفعَّل بعد الضبط}
        {--disable : يُعطَّل}
        {--test= : رقمٌ يُرسَل إليه رمزٌ تجريبيٌّ للتحقّق من الوصول}';

    protected $description = 'قنواتُ إيصال رمز التحقّق — عرضُها وضبطُها وإثباتُ وصولها';

    public function handle(ProviderRegistry $registry): int
    {
        $key = (string) $this->option('set');

        if ($key === '' && ! $this->option('test')) {
            return $this->listAll($registry);
        }

        if ($this->option('test')) {
            return $this->prove($registry, (string) $this->option('test'));
        }

        return $this->apply($registry, $key);
    }

    // ══════════════════════════════════════════════════════════════════

    private function listAll(ProviderRegistry $registry): int
    {
        $this->newLine();
        $this->line('  <options=bold>قنواتُ إيصال رمز التحقّق</>');
        $this->newLine();

        $ready = false;

        foreach (['whatsapp', 'sms'] as $channel) {
            $this->line("  <fg=gray>── {$channel} ──</>");

            foreach ($registry->all() as $p) {
                if ($p->channel() !== $channel) {
                    continue;
                }

                $on = $p->isEnabled();
                $ready = $ready || $on;

                $this->line(sprintf('  %s %-16s <fg=gray>%s</>',
                    $on ? '<fg=green>✓</>' : '<fg=yellow>؟</>',
                    $p->key(),
                    $on ? 'مُفعَّلٌ وقادرٌ على الإرسال' : $this->whyNot($p)));
            }

            $this->newLine();
        }

        // **والحكمُ يُقال، فقائمةٌ بلا خلاصةٍ تُقرأ حسبَ مزاج قارئها.**
        if ($ready) {
            $this->line('  <fg=green>التسجيلُ مفتوحٌ للأرقام الحقيقيّة.</>');
        } else {
            $this->line('  <fg=red;options=bold>لا قناةَ عاملة — والتسجيلُ مقفلٌ على كلّ رقمٍ حقيقيّ.</>');
            $this->line('  <fg=gray>أرقامُ العرض وحدَها تعمل (AMIAL_DEMO_OTP).</>');
        }

        $this->newLine();
        $this->line('  الضبط: <options=bold>php artisan amial:messaging --set=meta_cloud \\</>');
        $this->line('           <options=bold>--value=access_token=… --value=phone_number_id=… --enable</>');
        $this->line('  الإثبات: <options=bold>php artisan amial:messaging --test=967xxxxxxxxx</>');
        $this->newLine();

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** **ولا يُقال «معطَّل» وحدَها** — تُقال العلّة، وإلّا أُرسل قارئُها يبحث. */
    private function whyNot(MessageProvider $p): string
    {
        $row = DB::table('addon_settings')
            ->where('key_name', $p->key())
            ->where('settings_type', $p->channel() === 'whatsapp' ? 'whatsapp_config' : 'sms_config')
            ->first();

        if (! $row || $row->live_values === null) {
            return 'غيرُ مضبوطٍ إطلاقاً — لا صفَّ له في addon_settings';
        }

        $c = json_decode((string) $row->live_values, true) ?: [];

        if ((int) ($c['status'] ?? 0) !== 1) {
            return 'مضبوطٌ ومُطفأ — أضِف ‎--enable';
        }

        $missing = array_values(array_filter(
            $this->requiredKeysOf($p),
            fn ($k) => empty($c[$k])));

        // **ولا فرعَ لـ«مضبوط» هنا** — `whyNot` لا تُنادى إلّا حين
        // `isEnabled()` كاذبة، و«مضبوطٌ كاملاً» يعني أنّها صادقة. فإرجاعُ
        // «مضبوط» شيفرةٌ ميّتة، وقد أثبت قلبُ الحارس أنّها كذلك: عُدّلت
        // فلم يسقط شيء.
        return 'مُفعَّلٌ وينقصه: ' . implode(' · ', $missing ?: ['—']);
    }

    /** @return list<string> */
    private function requiredKeysOf(MessageProvider $p): array
    {
        $m = new \ReflectionMethod($p, 'requiredKeys');
        $m->setAccessible(true);

        return (array) $m->invoke($p);
    }

    // ══════════════════════════════════════════════════════════════════

    /**
     * **ولا تُسمّى `configure`** — فهي محجوزةٌ في `Symfony\Command`
     * و`private` عليها خطأٌ قاتل. (وقع مثلُه على `run()` في هذه الجلسة.)
     */
    private function apply(ProviderRegistry $registry, string $key): int
    {
        // ══════════════════════════════════════════════════════════
        // **ومفتاحٌ واحدٌ لقناتين يجعل الاختيارَ صامتاً.** `twilio`
        // مزوّدُ واتسابٍ **ومزوّدُ رسائلَ قصيرة**، والأخذُ بأوّل مطابقٍ
        // يكتب المفاتيحَ في القناة الخطأ ثمّ يقول «مُفعَّل» — فيُضبَط
        // ما لا يُرسل، ويُبحَث عن العلّة في المزوّد لا في الاختيار.
        // ══════════════════════════════════════════════════════════
        $channel = (string) $this->option('channel');

        $matches = array_values(array_filter(
            $registry->all(),
            fn ($p) => $p->key() === $key
                && ($channel === '' || $p->channel() === $channel)));

        if ($matches === []) {
            $this->error("لا مزوّدَ بهذا المفتاح: {$key}"
                . ($channel === '' ? '' : " في قناة {$channel}"));
            $this->line('  المتاح: ' . implode(' · ', array_map(
                fn ($p) => $p->key() . '/' . $p->channel(), $registry->all())));

            return self::FAILURE;
        }

        if (count($matches) > 1) {
            $this->error("المفتاح «{$key}» مشتركٌ بين "
                . implode(' و', array_map(fn ($p) => $p->channel(), $matches))
                . ' — حدّد ‎--channel=…');

            return self::FAILURE;
        }

        $provider = $matches[0];

        $type = $provider->channel() === 'whatsapp' ? 'whatsapp_config' : 'sms_config';

        $row = DB::table('addon_settings')
            ->where('key_name', $key)->where('settings_type', $type)->first();
        $current = $row && $row->live_values ? (json_decode((string) $row->live_values, true) ?: []) : [];

        foreach ((array) $this->option('value') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("صيغةٌ خاطئة: {$pair} — تُكتب key=value");

                return self::FAILURE;
            }

            [$k, $v] = explode('=', (string) $pair, 2);
            $current[trim($k)] = trim($v);
        }

        if ($this->option('enable')) {
            $current['status'] = 1;
        }

        if ($this->option('disable')) {
            $current['status'] = 0;
        }

        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => $key, 'settings_type' => $type],
            [
                // **و`id` هنا `char(36)` بلا قيمةٍ افتراضيّة** — لا
                // `auto_increment`. فإدراجٌ بلا مُعرِّفٍ يسقط بـ1364.
                // (قِيس من `SHOW COLUMNS` ولم يُفترَض من اسم العمود.)
                'id' => $row->id ?? (string) \Illuminate\Support\Str::uuid(),
                'live_values' => json_encode($current, JSON_UNESCAPED_UNICODE),
                'mode' => 'live',
                'is_active' => (int) ($current['status'] ?? 0),
                'updated_at' => now(),
                'created_at' => $row->created_at ?? now(),
            ],
        );

        // **ويُعاد سؤالُ المزوّد نفسِه** — فالحكمُ من مصدره لا من نيّتنا.
        $registry->forget();

        $fresh = null;

        foreach (app(ProviderRegistry::class)->all() as $p) {
            if ($p->key() === $key && $p->channel() === $provider->channel()) {
                $fresh = $p;
                break;
            }
        }

        $ok = $fresh?->isEnabled() ?? false;

        $this->line($ok
            ? "  <fg=green>✓</> {$key} مُفعَّلٌ وقادرٌ على الإرسال"
            : "  <fg=yellow>؟</> {$key} — " . ($fresh ? $this->whyNot($fresh) : 'تعذّرت القراءة'));

        // **والضبطُ ليس وصولاً.** فلا يُقال «تمّ» قبل أن تصل رسالة.
        if ($ok) {
            $this->line('  <fg=gray>ولم يصل شيءٌ بعد — أثبِت: '
                . 'php artisan amial:messaging --test=967xxxxxxxxx</>');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    // ══════════════════════════════════════════════════════════════════

    /**
     * **إثباتُ الوصول — لا الضبط.**
     *
     * صيغةٌ خاطئة، أو نافذةُ واتساب المغلقة (٢٤ ساعة)، أو رمزٌ منتهٍ:
     * ثلاثتُها تجعل `status=1` صادقاً والرسالةَ لا تصل. **فيُرسَل فعلاً،
     * ويُقال للقارئ أن يفتح هاتفَه** — وهو نفسُ ما يفعله
     * `amial:alert-test`.
     */
    private function prove(ProviderRegistry $registry, string $phone): int
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $any = false;

        foreach (['whatsapp', 'sms'] as $channel) {
            if (! $registry->hasEnabled($channel)) {
                $this->line("  <fg=gray>{$channel}: لا مزوّدَ مُفعَّل — تُخطَّى</>");

                continue;
            }

            $any = true;
            $result = $registry->sendOtp($channel, $phone, $code);

            $this->line(sprintf('  %s %s: %s',
                $result === 'success' ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $channel, $result));
        }

        if (! $any) {
            $this->error('لا قناةَ مُفعَّلةً إطلاقاً — لا شيءَ يُختبَر.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  الرمزُ المُرسَل: <options=bold>{$code}</>");
        $this->line('  <fg=yellow>وافتح الهاتفَ الآن.</> «نجح الإرسال» ليس «وصلت الرسالة» — '
            . 'والفرقُ بينهما تجربةٌ تبدأ أو ألفا عميلٍ يقفون على الباب.');
        $this->newLine();

        return self::SUCCESS;
    }
}
