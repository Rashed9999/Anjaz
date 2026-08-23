<?php

namespace App\Saher\Collectors;

use App\Saher\Findings\Evidence;
use App\Saher\Findings\Finding;
use Illuminate\Routing\Route;

/**
 * SAHER-GUARD-COLLECTOR-001 — **حارسُ الحراس.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لمَ هذا أوّلُ جامعٍ يُبنى، لا «خريطةُ المشروع»:**
 *
 * قضيتُ جلسةً كاملةً أكتشف بيدي ما يقوله هذا الملفُّ في ثانية:
 *
 *   · واحدٌ وثلاثون مسارَ كتابةٍ في اللوحة بلا صلاحيّةٍ إطلاقاً — فيها
 *     `POST hub/agents/{id}/credit` **يُنشئ مالاً**
 *   · خمسةٌ وعشرون مسارَ كتابةٍ محروسةٌ بصلاحيّة **قراءة** — منها تعديلُ
 *     قواعد غسل الأموال وإرسالُ تقريرٍ رقابيّ
 *   · تسعةٌ وأربعون مسارَ مالٍ خلف مفتاحٍ واحدٍ يملكه دورٌ واحد
 *
 * **وكلُّ واحدةٍ منها كانت تحتاج إنساناً يسأل.** وساهرٌ يسأل في كلّ
 * جولة، ويقول «منذ متى» و«أجديدٌ أم قديم».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ دروسٍ من قياسي اليدويّ مبنيّةٌ في هذا الجامع:**
 *
 * ① `gatherMiddleware()` تُرجع وسائطَ **المجموعة والمسار معاً**،
 *    والمجموعةُ أوّلاً. **فأخذُ أوّل `platform:` يقرأ نصفَ الشرط** —
 *    ومسارٌ يطلب صلاحيّتين يُقرأ بأضعفهما. تُجمَع كلُّها.
 *
 * ② **الفئةُ في جدول الصلاحيّات تسميةٌ لا إنفاذ.** أوّلُ مقياسٍ كتبتُه
 *    عرّف «القراءة» بعمود `category` فأخطأ. فالتمييزُ هنا بالفعل: صلاحيّةٌ
 *    تنتهي بـ`.view` تحرس كتابةً = قسمةٌ مفقودة.
 *
 * ③ **وبعضُ الكتابة مفتوحٌ بحقّ** — تسجيلُ الدخول والخروج ورفعُ صورة.
 *    فالحكمُ بلا استثناءٍ يُغرق الشاشةَ بضجيجٍ يُعوّد القارئَ التجاهل.
 */
class GuardCoverageCollector
{
    public const SOURCE = 'guards';

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * **مساراتُ كتابةٍ مفتوحةٌ عن قصد** — ولكلٍّ سببُه المكتوب.
     *
     * والقائمةُ موجبةٌ بالاسم لا بنمطٍ عامّ: نمطٌ مثل `*.login*` يبتلع
     * `login-as-user` وهو انتحالُ هويّة.
     *
     * ══════════════════════════════════════════════════════════════════
     * **وأوّلُ جولةٍ صادقةٍ صحّحت هذه القائمة نفسَها.**
     *
     * كُتب فيها `admin.auth.login` — **ولا وجودَ لهذا الاسم**. المسارُ
     * الحقيقيُّ `POST admin/auth/login` اسمُه `admin.auth.` بنقطةٍ
     * زائدة (عطلُ تسميةٍ قائمٌ في المشروع). فاستثناءٌ لا يطابق الواقع
     * ليس استثناءً — وقد كشفه الجامعُ لأنّه بلّغ عن الباب.
     *
     * **والباقي قِيس ولم يُفترَض:** خمسةٌ منها تعمل على `$request->user()`
     * وحدَه، وكلمةُ المرور على `auth('user')->id()`. **فالهويّةُ تحدّد
     * النطاق** (القاعدة الثامنة)، ومعرِّفٌ لا يُقبل من الطلب لا يحتاج
     * صلاحيّةً تحرسه. ولو قَبِل أحدُها معرِّفَ غيرِه لكان تصعيدَ امتياز:
     * **من يُعطّل مصادقةً ثنائيّةً لمشرفٍ آخر يفتح حسابَه.**
     *
     * @var array<string,string>
     */
    private const OPEN_BY_DESIGN = [
        // بابُ الدخول نفسُه — حارسُه الكابتشا وحدُّ المحاولات، لا صلاحيّةٌ
        // (ولا صلاحيّةَ لمن لم يدخل بعد).
        'admin.auth.' => 'POST admin/auth/login — بابُ الدخول، ولا دورَ قبل الدخول',
        'admin.auth.logout' => 'الخروجُ لا يحتاج صلاحيّةً غير الجلسة',
        'admin.auth.two-factor.verify' => 'تحدّي المصادقة الثنائيّة أثناء الدخول',

        // AMIAL-AUTH-PIN-FORCE — **تغييرُ الموظّفِ رمزَ دخوله هو.**
        //
        // ولا صلاحيّةَ له عمداً لثلاثة أسباب مقيسة:
        //   · النطاقُ من الهويّة لا من الطلب — `auth('user')->user()`
        //   · والمتنُ يطلب الرمزَ الحاليَّ ويرفض ما لا يطابقه، فالجلسةُ
        //     المسروقةُ لا تُبدّله
        //   · **وهو المخرجُ الوحيدُ من حاجز `ForcePlatformPinChange`** —
        //     فصلاحيّةٌ عليه تُقفل اللوحةَ على من رمزُه أوّليٌّ ولا يملك
        //     تلك الصلاحيّة. حاجزٌ بلا مخرجٍ سجنٌ لا حاجز.
        'admin.auth.pin.update' => 'يغيّر الموظّفُ رمزَ دخوله هو — '
            . 'الهويّةُ تحدّد النطاق، والرمزُ الحاليُّ يُطلَب، '
            . 'وهو مخرجُ حاجز الرمز الأوّليّ',

        // خدمةٌ ذاتيّةٌ على الحساب نفسِه — النطاقُ من الهويّة لا من الطلب.
        'admin.amial.2fa.setup' => 'مصادقةُ المشرف الثنائيّة على حسابه — $request->user()',
        'admin.amial.2fa.confirm' => 'تأكيدُ مصادقته الثنائيّة — $request->user()',
        'admin.amial.2fa.disable' => 'تعطيلُ مصادقته هو — $request->user()',
        'admin.amial.2fa.regenerate' => 'رموزُ استرداده هو — $request->user()',
        'admin.settings-password' => 'كلمةُ مروره هو — auth(\'user\')->id()',
        'admin.amial.locale' => 'لغةُ واجهته — تفضيلٌ لا يمسّ بياناتِ أعمال',
    ];

    /**
     * **مساراتٌ يفحص متنُها الصلاحيّةَ بدقّةٍ أكبر من الوسيطة.**
     *
     * `customer.action` تُمرَّر إلى `CustomerActionService::run()`، وفيها
     * `self::ACTIONS[$action]` تُخرج صلاحيّةَ الفعل بعينه ثمّ تُفحص.
     * **فحاجزٌ عامٌّ على المسار يشلّ أفعالاً لكلٍّ صلاحيّتُها.**
     *
     * وهو الاستثناءُ نفسُه المكتوبُ في `ReadOnlyAuditorTest` — ومصدرُه
     * واحدٌ هناك وهنا لئلّا ينحرفا.
     *
     * @var array<string,string>
     */
    private const ENFORCED_IN_SERVICE = [
        'admin.amial.customer.action' =>
            'CustomerActionService::run() تفحص صلاحيّةَ كلّ فعلٍ على حدة',
    ];

    /** @return array{findings:list<Finding>, assets_seen:int} */
    public function collect(): array
    {
        $findings = [];
        $seen = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! $this->isAdminWrite($route)) {
                continue;
            }

            $seen++;
            $name = (string) $route->getName();

            if (array_key_exists($name, self::OPEN_BY_DESIGN)
                || array_key_exists($name, self::ENFORCED_IN_SERVICE)) {
                continue;
            }

            $perms = $this->platformPermissions($route);

            if ($perms === []) {
                $findings[] = $this->unguarded($route, $name);

                continue;
            }

            if ($this->allAreReads($perms)) {
                $findings[] = $this->writeBehindReadOnly($route, $name, $perms);
            }
        }

        return ['findings' => $findings, 'assets_seen' => $seen];
    }

    // ══════════════════════════════════════════════════════════════════

    private function isAdminWrite(Route $route): bool
    {
        if (! array_intersect(self::WRITE_METHODS, $route->methods())) {
            return false;
        }

        // **نطاقُ هذا الجامع لوحةُ الإدارة.** مساراتُ التاجر والوكيل
        // محرّكاتُها أخرى (`MerchantPermissionService`)، وخلطُها يُنتج
        // «غيرَ محروس» على ما يحرسه محرّكٌ لا يعرفه هذا الجامع — وهو
        // إيجابيٌّ كاذبٌ يُفقد الثقة.
        return str_starts_with($route->uri(), 'admin/');
    }

    /**
     * **كلُّ** صلاحيّات المنصّة على المسار — المجموعةُ والمسارُ معاً.
     *
     * @return list<string>
     */
    private function platformPermissions(Route $route): array
    {
        $out = [];

        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'platform:')) {
                $out[] = substr($mw, strlen('platform:'));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * أكلُّ الصلاحيّات صلاحيّاتُ قراءة؟
     *
     * **ويُقاس بالفعل في الرمز لا بعمود `category`.** والقسمةُ المتكرّرة
     * في هذا المشروع سبعَ مرّات: `zones.override`/`assign` ·
     * `money.view`/`move` · `aml.decide`/`investigate` — وكلُّها وُلدت من
     * فعلٍ وقراءةٍ في حبّةٍ واحدة.
     */
    private function allAreReads(array $perms): bool
    {
        foreach ($perms as $p) {
            if (! $this->isRead($p)) {
                return false;
            }
        }

        return $perms !== [];
    }

    private function isRead(string $permission): bool
    {
        foreach (['.view', '.list', '.export', '.read'] as $suffix) {
            if (str_ends_with($permission, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function unguarded(Route $route, string $name): Finding
    {
        $method = implode('|', array_intersect(self::WRITE_METHODS, $route->methods()));
        $action = $route->getActionName();

        // **مسارٌ ماليٌّ بلا حارسٍ حرجٌ، وغيرُه عالٍ.** والتمييزُ بالكلمة
        // في المسار: `credit` و`transfer` و`settlement` و`topup` تُحرّك
        // مالاً، وردُّ فعلٍ متأخّرٍ عليها يُقاس بالريال لا بالإزعاج.
        $money = (bool) preg_match(
            '~(credit|debit|transfer|topup|payout|settle|refund|issue|adjust|fee)~i',
            $route->uri(),
        );

        return (new Finding(
            ruleId: 'SAHER.GUARD.WRITE_WITHOUT_PERMISSION',
            sourceCode: self::SOURCE,
            category: 'security',
            title: 'مسارُ كتابةٍ في لوحة الإدارة بلا صلاحيّةٍ إطلاقاً',
            severity: $money ? 'CRITICAL' : 'HIGH',
            confidence: 'PROVEN',
            assetKey: $name !== '' ? $name : $method . ' ' . $route->uri(),
            assetType: 'route',
            expected: 'كلُّ مسارِ كتابةٍ في اللوحة خلف وسيطة `platform:<صلاحيّة>`',
            actual: 'لا وسيطةَ صلاحيّةِ منصّةٍ واحدةٌ على هذا المسار',
            impact: $money
                ? 'يُنفّذه كلُّ من يدخل اللوحة — وهو مسارٌ يُحرّك مالاً'
                : 'يُنفّذه كلُّ من يدخل اللوحة مهما كان دورُه',
            suggestedAction: 'تُضاف `->middleware(\'platform:<صلاحيّة الفعل>\')` — '
                . 'وصلاحيّةُ فعلٍ لا صلاحيّةُ قراءة',
            symbol: is_string($action) ? $action : null,
        ))->withEvidence(
            new Evidence(
                'ROUTE_MIDDLEWARE',
                'الوسائطُ المحمولةُ على المسار فعلاً',
                $method . ' ' . $route->uri() . "\n"
                    . implode("\n", array_map(
                        fn ($m) => '  · ' . (is_string($m) ? $m : gettype($m)),
                        $route->gatherMiddleware(),
                    )),
                'GuardCoverageCollector::platformPermissions',
            ),
            Evidence::absence(
                'ما بُحث عنه ولم يوجد',
                'وسيطةٌ تبدأ بـ`platform:` ضمن `gatherMiddleware()` — '
                    . 'وهي المجموعةُ والمسارُ معاً، لا أحدُهما',
                'GuardCoverageCollector',
            ),
        );
    }

    private function writeBehindReadOnly(Route $route, string $name, array $perms): Finding
    {
        $method = implode('|', array_intersect(self::WRITE_METHODS, $route->methods()));

        return (new Finding(
            ruleId: 'SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION',
            sourceCode: self::SOURCE,
            category: 'security',
            title: 'مسارُ كتابةٍ محروسٌ بصلاحيّةِ قراءةٍ وحدَها',
            severity: 'HIGH',
            confidence: 'HIGH_CONFIDENCE',
            assetKey: $name !== '' ? $name : $method . ' ' . $route->uri(),
            assetType: 'route',
            expected: 'الفعلُ خلف صلاحيّةِ فعل، والقراءةُ خلف صلاحيّةِ قراءة',
            actual: 'كلُّ صلاحيّاتِ هذا المسار تنتهي بلاحقةِ قراءة: '
                . implode(' + ', $perms),
            impact: 'من مُنح القراءةَ ليطّلع يستطيع بها الكتابة — '
                . 'ودورٌ يُوصَف بأنّه «قراءةٌ فقط» يصير كاذباً',
            suggestedAction: 'تُقسَم الصلاحيّةُ إلى قراءةٍ وفعل، ويُحرَس هذا '
                . 'المسارُ بالثانية. **والقسمةُ نفسُها تكرّرت سبعَ مرّاتٍ في '
                . 'هذا المشروع.**',
            symbol: is_string($route->getActionName()) ? $route->getActionName() : null,
        ))->withEvidence(
            new Evidence(
                'ROUTE_MIDDLEWARE',
                'صلاحيّاتُ المنصّة على هذا المسار',
                $method . ' ' . $route->uri() . "\n"
                    . implode("\n", array_map(fn ($p) => '  · platform:' . $p, $perms)),
                'GuardCoverageCollector',
            ),
        );
    }
}
