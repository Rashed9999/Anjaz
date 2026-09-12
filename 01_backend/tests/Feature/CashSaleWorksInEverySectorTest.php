<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-OFFLINE-POS-003 — **البيعُ النقديُّ لا ينهار ولا يُقفَل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما وصل صاحبَ المشروع:** شريطٌ أحمرُ عند تأكيد الدفع النقديّ —
 * `Method CashierController::denyUnless does not exist`. **انهيارٌ فادحٌ
 * في مسار المال.**
 *
 * وسببُه عطلان متراكبان، والثاني أخطر:
 *
 * ① **استيرادُ الصنف ليس استعمالَ التِّرَيت.** الملفُّ يستورد
 *    `Concerns\DeniesByPlan` في رأسه **ولا سطرَ له داخل الصنف** — فالدالّةُ
 *    غيرُ موجودة. ولا يمسكه `php -l` ولا مُحلِّل: الاستيرادُ مستعمَلٌ
 *    نحويّاً، والنداءُ لا يُفحَص إلّا في وقت التشغيل.
 *
 * ② **والإشارةُ خاطئةٌ واقعاً.** الحارسُ كان على `client_uuid` بتعليقٍ
 *    يقول «لا يُرسَل إلّا من طابور المزامنة» — **وقِيس في التطبيق فإذا هو
 *    يُرسَل في كلّ بيع** (مفتاحُ منع التكرار). فلو أُضيف التِّرَيتُ وحدَه
 *    لتحوّل الانهيارُ إلى ٤٠٢ على البيع الأساسيِّ لكلّ تاجرٍ مجّانيّ.
 *
 * **والفحصُ يمشي كلَّ القطاعات** — فمتحكّمُ الكاشير مشتركٌ بين التجزئة
 * والبيع السريع، وللصيدليّة والوقود والجملة متحكّماتُها. (القاعدة الرابعة.)
 */
class CashSaleWorksInEverySectorTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $vertical, string $plan = A::PLAN_FREE): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small',
            'verification_status' => 'verified',
            'business_type' => $vertical,
            'subscription_plan' => $plan,
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);

                // AMIAL-SHIFT-GATE-001 — **وتُفتَح ورديّةٌ لأنّ البيعَ صار يشترطها.**
        //
        // «لا يُقبض نقدٌ بلا ورديّة تحمل اسمَ فاتحها» — وهذا الملفُّ يفحص
        // شيئاً آخر، فيُهيَّأ الشرطُ ولا يُطفأ الحدّ: إطفاؤه هنا يجعل
        // الاختبارَ يمرّ على مسارٍ لا يسلكه تاجرٌ حقيقيّ.
        app(\App\Services\CashierShiftService::class)->open($u, null, '0');

        return $u;
    }

    /** حمولةُ بيعٍ نقديٍّ كما يبنيها التطبيق — ومعها `client_uuid` دائماً. */
    private function cashSale(): array
    {
        return [
            'payment_method' => 'cash',
            'total' => '500',
            'items' => [
                ['name' => 'بريك بيج', 'qty' => 1, 'unit_price' => '500'],
            ],
            // **يُرسَل في كلّ بيع** — متّصلاً كان أو غيرَ متّصل.
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① البيعُ النقديُّ لا ينهار — والانهيارُ هو ما وصل الشاشة.**
     *
     * ويُقاس بألّا يكون الردُّ ٥٠٠ ولا ٥٠١: أيُّ خطأٍ فادحٍ في هذا المسار
     * يعني كاشيراً لا يستطيع إتمامَ بيعةٍ واحدة.
     */
    /** @test */
    public function a_cash_sale_never_crashes_for_any_vertical(): void
    {
        $crashed = [];

        foreach (['quick_sale', 'retail'] as $vertical) {
            $m = $this->merchant($vertical);
            $r = $this->actingAs($m, 'api')
                ->postJson('/api/v1/amial/merchant/cashier/sales', $this->cashSale());

            // **ويُشترط النجاحُ صراحةً** — لا «ليس ٥٠٠». فأوّلُ صياغةٍ
            // قبلت ٤٢٢ (حمولتي كانت `total_amount` والعقدُ `total`)،
            // فكان الحارسُ يخضرّ على بيعٍ لم يقع. (حارسٌ يمرّ والعطلُ قائم.)
            if (!in_array($r->status(), [200, 201], true)) {
                $crashed[] = sprintf('%s → %d %s', $vertical, $r->status(),
                    (string) ($r->json('message') ?? ''));
            }
        }

        $this->assertSame([], $crashed, sprintf(
            "**البيعُ النقديُّ ينهار:**\n  %s\n\n"
            .'وهو مسارُ المال نفسُه — كاشيرٌ لا يُتمّ بيعةً واحدة.',
            implode("\n  ", $crashed)));
    }

    /**
     * **② ولا يُقفَل بباقةٍ مدفوعة.** (وهذا ما كان سيقع لو أُصلح الأوّلُ وحدَه.)
     *
     * البيعُ المتّصلُ مجّانيٌّ بالتصميم — والتعليقُ في الشيفرة يقوله بنصّه:
     * «والبيعُ المتّصلُ مجّانيّ… ووضعُ `capability:` عليه يُقفل الكاشيرَ على
     * كلّ تاجرٍ مجّانيّ».
     */
    /** @test */
    public function an_online_cash_sale_is_not_gated_behind_offline_pos(): void
    {
        $m = $this->merchant('retail', A::PLAN_FREE);

        $r = $this->actingAs($m, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/sales', $this->cashSale());

        $this->assertNotSame(402, $r->status(),
            '**بيعٌ نقديٌّ متّصلٌ رُدّ ٤٠٢ «قدرةٌ مدفوعة»** — و`client_uuid` '
            .'يُرسَل في كلّ بيع، فالحارسُ يقع على الجميع. البيعُ المتّصلُ مجّانيّ.');
    }

    /**
     * **③ والبيعُ المؤجَّلُ من الطابور يبقى محروساً — فالقدرةُ ليست زينة.**
     *
     * وهذا الشقُّ المقابل: لو رُفع الحارسُ كلَّه لصار «البيع دون اتصال»
     * مجّانيّاً وهو مُسعَّر. فالإشارةُ هي أثرُ الطابور (`_offline_queued_at`)
     * الذي يضيفه التطبيقُ عند الحفظ المحلّيّ ولا يرسله في البيع المتّصل.
     */
    /** @test */
    public function a_queued_offline_sale_still_requires_the_paid_capability(): void
    {
        $m = $this->merchant('retail', A::PLAN_FREE);

        $payload = $this->cashSale() + [
            '_offline_queued_at' => now()->toIso8601String(),
            '_offline_state' => 'pending',
        ];

        $r = $this->actingAs($m, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/sales', $payload);

        $this->assertSame(402, $r->status(),
            '**بيعٌ من طابور «دون اتصال» مرّ على باقةٍ مجّانيّة** — '
            .'فالقدرةُ المُسعَّرةُ تُعطى بلا ثمن. (الردُّ كان: '.$r->status().')');
    }

    /**
     * **④ وكلُّ متحكّمٍ ينادي `denyUnless` يستعمل التِّرَيتَ فعلاً.**
     *
     * **حارسٌ بنيويٌّ يمنع تكرارَ العطل نفسِه** في أيّ قطاعٍ آخر: استيرادُ
     * النطاق يُقرأ «موجود» ولا يمنح الدالّة، ولا يظهر الفرقُ إلّا بانهيارٍ
     * في وجه المستعمل.
     */
    /** @test */
    public function every_controller_calling_deny_unless_actually_uses_the_trait(): void
    {
        $dir = app_path('Http/Controllers');
        $missing = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, 'Concerns/DeniesByPlan.php')) {
                continue;
            }

            $src = (string) file_get_contents($path);
            if (!str_contains($src, '$this->denyUnless(')) {
                continue;
            }

            // جسمُ الصنف وحدَه — لا رأسُ الملفّ.
            $at = strpos($src, "\nclass ");
            $body = $at === false ? $src : substr($src, $at);

            if (!preg_match('~^\s+use\s+[^;]*DeniesByPlan~m', $body)) {
                $missing[] = basename($path);
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**متحكّماتٌ تنادي `denyUnless` ولا تستعمل التِّرَيت:**\n  %s\n\n"
            .'ينهار النداءُ بـ«Method does not exist» في وجه المستعمل — '
            .'ولا يمسكه `php -l` ولا مُحلِّل.',
            implode("\n  ", $missing)));
    }

    /**
     * **⑤ ولا مسارَ بيعٍ في أيّ قطاعٍ ينهار.**
     *
     * ══════════════════════════════════════════════════════════════════
     * الكاشيرُ مشتركٌ بين التجزئة والبيع السريع، **ولكلٍّ من الصيدليّة
     * والوقود والجملة مسارُ بيعه**. فالفحصُ الذي طلبه صاحبُ المشروع —
     * «الدفعُ نقدي، افحص كلَّ القطاعات» — لا يكتمل بالكاشير وحدَه.
     *
     * **وما يُقاس هنا صنفُ العطل نفسُه**: خطأٌ فادحٌ (5xx) في مسار البيع.
     * ولا يُشترَط ٢٠٠: لكلّ قطاعٍ حمولتُه (الصيدليّةُ تطلب دفعةً وتاريخَ
     * صلاحية، والوقودُ مضخّةً وخزّاناً، والجملةُ عميلاً) وبناؤها كاملةً
     * هنا يقيس تلك الحمولاتِ لا صمودَ المسار.
     *
     * **و٤٢٢ جوابٌ صحيحٌ يُثبت المطلوب**: أنّ المتحكّمَ بُلِغ فعلاً وشغّل
     * مُحقِّقَه — أي أنّه لم ينهر قبل أن يبدأ، وهو بعينه ما وقع في
     * الكاشير (‏`denyUnless` تنهار قبل أيّ منطق).
     */
    /** @test */
    public function no_sector_sale_route_crashes(): void
    {
        $doors = [
            'الصيدلية' => ['pharmacy', 'merchant/pharmacy/sales'],
            'الوقود' => ['fuel', 'merchant/fuel/sales'],
            'الجملة (فاتورة)' => ['wholesale', 'merchant/wholesale/invoices'],
        ];

        $crashed = [];
        foreach ($doors as $label => [$vertical, $path]) {
            $m = $this->merchant($vertical, A::PLAN_ENTERPRISE);

            $r = $this->actingAs($m, 'api')
                ->postJson('/api/v1/amial/'.$path, $this->cashSale());

            // **ولا يُقبَل ٤٠٤ ولا ٤٠٥**: مسارٌ لا وجودَ له يجعل الحارسَ
            // يمرّ فارغاً — وقد وقع لي هنا بعينه: كتبتُ
            // `wholesale/sales` وهو لا يوجد (بحثي طابق `sales-reps`
            // بالبادئة)، فمرّ المدخلُ ولم يقس شيئاً.
            if ($r->status() >= 500 || in_array($r->status(), [404, 405], true)) {
                $crashed[] = sprintf('%s (%s) → %d %s', $label, $path,
                    $r->status(), (string) ($r->json('message') ?? ''));
            }
        }

        $this->assertSame([], $crashed, sprintf(
            "**مسارُ بيعٍ ينهار في قطاع:**\n  %s\n\n"
            .'وهو صنفُ العطل الذي أوقف الكاشير — انهيارٌ قبل أيّ منطق.',
            implode("\n  ", $crashed)));
    }
}
