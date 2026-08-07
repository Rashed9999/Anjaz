<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\Whatsapp\WhatsappMoneyLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-WA-LIMIT-001 — سقفُ المال عبر بوت واتساب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وتصحيحُ قولٍ لي:** قلتُ إنّ البوت «بلا رمز حماية». وقِستُ فوجدتُه
 * **يتحقّق منه**: `PinService::verify` قبل دفع الفاتورة، و`pin` يُمرَّر
 * إلى `TransferService::initiate`. فالجملةُ كانت خطأً.
 *
 * **والقلقُ الحقيقيّ أدقُّ من ذاك:** الرمزُ في البوت **يُكتب في محادثة
 * واتساب**، فيبقى في سجلّها على الهاتفين وعند المزوّد. فالسقفُ ليس
 * حاجزاً أقوى — بل تحديدٌ لأكبر خسارةٍ ممكنة حين يُقرأ الرمزُ من محادثة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ مسارات لا مسارٌ واحد:** التحويل، والفواتير، وفاتورة التاجر.
 * وسقفٌ على واحدٍ من ثلاثةٍ ليس سقفاً: من بلغ الحدَّ في التحويل يُقسّمه
 * على فواتير.
 */
class WhatsappMoneyLimitTest extends TestCase
{
    use RefreshDatabase;

    private function setCap(string $v): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => WhatsappMoneyLimit::KEY],
            ['value' => $v, 'created_at' => now(), 'updated_at' => now()],
        );
        WhatsappMoneyLimit::forget();
    }

    private function limit(): WhatsappMoneyLimit
    {
        WhatsappMoneyLimit::forget();

        return app(WhatsappMoneyLimit::class);
    }

    private function admin(string $role = PlatformRoleService::ADMIN, string $phone = '967770030001'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'الحدود', 'phone' => $phone,
            'email' => $phone . '@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->assign($u, $role);

        return $u->fresh();
    }

    // ══════════════════════════════════════════════════════════════
    // ١) السقف نفسُه
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **القيمةُ غيرُ المضبوطة ليست «بلا حدّ».**
     *
     * ولو كانت لشُحن النظامُ بلا حماية حتّى يتذكّرها إنسان — وهو نمطُ
     * العطل الأكثر تكراراً هنا: مبنيٌّ ولا يُوصَل إليه.
     */
    public function an_unset_limit_is_the_configured_default_not_unlimited(): void
    {
        $this->assertFalse($this->limit()->isConfigured());

        $max = $this->limit()->max();

        $this->assertSame(1, bccomp($max, '0', 4), 'الافتراضيّ صفرٌ — يُقفل البوت على الجميع');

        // ومبلغٌ فوقه يُمنع **قبل** أن يضبط أحدٌ شيئاً.
        $this->assertNotNull($this->limit()->refuse(bcadd($max, '1', 4)),
            'لا سقفَ قبل الضبط — النظامُ يُشحن بلا حماية');
    }

    /**
     * @test
     *
     * **وما دون السقف يمرّ — فسقفٌ يمنع المشروعَ أسوأ من غيابه.**
     */
    public function an_amount_under_the_cap_passes(): void
    {
        $this->setCap('10000');

        $l = $this->limit();

        $this->assertNull($l->refuse('9999'), 'مُنع مبلغٌ دون السقف');
        $this->assertNull($l->refuse('10000'), 'مُنع مبلغٌ يساوي السقف تماماً');
        $this->assertNotNull($l->refuse('10001'), 'مرّ مبلغٌ فوق السقف');
    }

    /**
     * @test
     *
     * **وصفرٌ مضبوطٌ يعني: لا مالَ عبر البوت إطلاقاً.**
     *
     * وهو مفتاحُ الإيقاف حين يقع بلاغُ احتيال — بلا نشرٍ ولا شيفرة.
     */
    public function a_configured_zero_stops_all_money(): void
    {
        $this->setCap('0');

        $this->assertNotNull($this->limit()->refuse('1'),
            'السقفُ صفرٌ ومرّ ريالٌ واحد');
    }

    /**
     * @test
     *
     * **والرفضُ يقول سببَه ويذكر السقف — لا «فشلت العمليّة».**
     *
     * فمن مُنع عند صندوقٍ يحتاج أن يعرف السقفَ ليقسّم عمليّته أو يفتح
     * التطبيق. والرسالةُ المبهمة تُرسله يُعيد المحاولة بلا جدوى.
     */
    public function the_refusal_states_the_cap_and_the_way_out(): void
    {
        $this->setCap('7500');

        $msg = $this->limit()->refuse('9000');

        $this->assertNotNull($msg);
        $this->assertStringContainsString('7,500', $msg, 'الرسالةُ لا تذكر السقف');
        $this->assertStringContainsString('التطبيق', $msg, 'الرسالةُ لا تدلّ على طريق');
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) المسارات الثلاثة كلُّها تمرّ بالسقف
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **والمسارات الثلاثة كلُّها تُفحص — لا واحدٌ منها.**
     *
     * وهذا الحارسُ يقرأ الشيفرة نفسَها: لو أُضيف مسارُ مالٍ رابعٌ في البوت
     * غداً بلا فحص، **يبقى هذا الحارسُ يمرّ وهو لا يعرف بوجوده** — فيُفحص
     * أنّ كلَّ نداءِ خدمةٍ ماليّةٍ في البوت يسبقه فحصُ السقف.
     */
    public function all_three_bot_money_paths_go_through_the_cap(): void
    {
        $src = file_get_contents(app_path('Services/Whatsapp/WhatsappBotService.php'));

        // نداءاتُ الخدمات المالية في البوت
        $moneyCalls = [
            'transferSvc->initiate' => 'التحويل',
            'billPaySvc->createAndExecute' => 'دفع الفواتير',
            'paymentRequestSvc->pay' => 'فاتورة تاجر',
        ];

        foreach ($moneyCalls as $needle => $label) {
            $this->assertStringContainsString($needle, $src,
                "اختفى مسارُ {$label} — الحارسُ يفحص شيئاً غير موجود");
        }

        // ولكلٍّ فحصُ سقفٍ **قبله** في الملفّ.
        foreach ($moneyCalls as $needle => $label) {
            $callPos = strpos($src, $needle);
            $before = substr($src, 0, $callPos);

            $this->assertStringContainsString('refuseIfOverLimit', $before,
                "مسارُ {$label} يُنفَّذ بلا فحص سقف");
        }

        $this->assertSame(3, substr_count($src, '$this->refuseIfOverLimit('),
            'عددُ فحوص السقف لا يساوي عددَ مسارات المال الثلاثة');
    }

    /**
     * @test
     *
     * **والمبلغُ يُقرأ من الفاتورة لا من الجلسة.**
     *
     * (القاعدة السادسة.) فما في الجلسة نصٌّ عُرض على المستعمل، وما في
     * الفاتورة هو ما سيُخصم. ولو اختلفا لمرّ الفحصُ على رقمٍ غيرِ الذي
     * يُدفع — وهو بالضبط ما يجعل السقفَ قابلاً للالتفاف.
     */
    public function the_invoice_amount_is_read_from_the_invoice(): void
    {
        $src = file_get_contents(app_path('Services/Whatsapp/WhatsappBotService.php'));

        $this->assertStringContainsString(
            "refuseIfOverLimit(\$phone, (string) \$paymentRequest->amount",
            $src,
            'فحصُ فاتورة التاجر يقرأ المبلغَ من الجلسة لا من الفاتورة',
        );
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) الشاشة: تُفتح · تُعدَّل · تُحرَس
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الشاشةُ تُفتح، وفيها كلُّ ما تعد به.**
     */
    public function the_screen_opens_with_its_parts(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/whatsapp/limits')->assertOk()->getContent();

        foreach (['wa-max', 'wa-reason', 'wa-save', 'wa-refresh',
                  'wa-state', 'wa-paths', 'wa-blocked', 'wa-why'] as $part) {
            $this->assertStringContainsString($part, $html, "ناقصٌ من الشاشة: {$part}");
        }
    }

    /**
     * @test
     *
     * **ويُوصل إليها من القائمة الجانبيّة — لا بعنوانٍ يُكتب يدويّاً.**
     */
    public function the_screen_is_reachable_from_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('/admin/amial/whatsapp/limits', $html,
            'لا رابطَ إلى حدود البوت من أيّ صفحةٍ يمرّ بها المدير');
    }

    /**
     * @test
     *
     * **وتُقال حالةُ القيمة: أمضبوطةٌ أم افتراضيّة.**
     *
     * فرقمٌ معروضٌ بلا بيانِ مصدره يُقرأ «مضبوط» — ثمّ يُكتشف أنّه
     * افتراضيٌّ لم يمسّه أحد. (القاعدة السابعة.)
     */
    public function the_screen_says_whether_the_value_was_ever_set(): void
    {
        $admin = $this->admin();

        $m = $this->actingAs($admin, 'user')
            ->getJson('/admin/amial/whatsapp/limits/show')->assertOk()->json('meta');

        $this->assertFalse($m['is_configured'], 'قيمةٌ لم تُضبط وتُعرض «مضبوطة»');

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/whatsapp/limits', ['max_amount' => '20000', 'reason' => 'بداية التجربة'])
            ->assertOk();

        $m = $this->actingAs($admin, 'user')
            ->getJson('/admin/amial/whatsapp/limits/show')->assertOk()->json('meta');

        $this->assertTrue($m['is_configured']);
    }

    /**
     * @test
     *
     * **والحفظُ يُغيّر السقفَ فعلاً — والقياسُ من الخدمة لا من الردّ.**
     *
     * فزرٌّ يردّ «حُفظ» ثمّ يبقى السقفُ القديم عاملاً دقيقةً كاملة (ذاكرةٌ
     * لم تُنسَ) **زرٌّ يكذب** — والدقيقةُ كافيةٌ لمن يُلاحَق الآن.
     */
    public function saving_actually_changes_what_the_bot_enforces(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/whatsapp/limits', ['max_amount' => '3000', 'reason' => 'بلاغ احتيال'])
            ->assertOk();

        // **القياسُ الحاسم:** ليس «ردّ ٢٠٠» بل «صار يمنع».
        $this->assertNotNull(app(WhatsappMoneyLimit::class)->refuse('3001'),
            'حُفظ السقفُ ولم يصر نافذاً — الذاكرةُ لم تُنسَ');

        $this->assertNull(app(WhatsappMoneyLimit::class)->refuse('2999'));
    }

    /**
     * @test
     *
     * **ولا يُغيَّر سقفُ مالٍ بلا سبب مكتوب.**
     */
    public function changing_the_cap_requires_a_written_reason(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/whatsapp/limits', ['max_amount' => '99999', 'reason' => ''])
            ->assertStatus(422);

        $this->assertFalse($this->limit()->isConfigured(), 'حُفظ السقفُ بلا سبب');
    }

    /**
     * @test
     *
     * **ويُسجَّل التغييرُ في التدقيق — بالقديم والجديد والسبب.**
     */
    public function the_change_is_recorded_in_the_audit_trail(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/whatsapp/limits', ['max_amount' => '5000', 'reason' => 'تشديد مؤقّت'])
            ->assertOk();

        $row = DB::table('audit_decisions')->where('action', 'WA_LIMIT_CHANGED')->first();

        $this->assertNotNull($row, 'تغيّر سقفُ مالٍ ولا أثرَ له في التدقيق');
        // **والعمودُ اسمُه `context`.** وأوّلُ صيغةٍ لي كتبت `data` في
        // الحمولة، و`AuditService::record` يقرأ `context` وحده — فكانت
        // الحمولةُ **تُبتلع صامتةً**: صفُّ تدقيقٍ موجودٌ بلا سببٍ ولا أرقام.
        // ووُجد الخللُ نفسُه في ثلاثة مواضع أخرى فأُصلحت معه.
        $this->assertStringContainsString('تشديد مؤقّت', (string) $row->context);
        $this->assertStringContainsString('5000', (string) $row->context);
    }

    /**
     * @test
     *
     * **وموظّفُ الدعم لا يرى السقفَ ولا يرفعه.**
     *
     * ويُفحص من **كلا المدخلين**: الصفحة ونقطةُ الفعل. فحراسةُ الصفحة
     * وحدها تترك الفعلَ مفتوحاً لمن يعرف عنوانه — **ومن يملك تغييرَ حدود
     * المال يملك المال.**
     */
    public function support_staff_can_neither_see_nor_raise_the_cap(): void
    {
        $sup = $this->admin(PlatformRoleService::SUPPORT, '967770030002');

        $this->actingAs($sup, 'user')->get('/admin/amial/whatsapp/limits')->assertForbidden();
        $this->actingAs($sup, 'user')->getJson('/admin/amial/whatsapp/limits/show')->assertForbidden();

        $this->actingAs($sup, 'user')
            ->postJson('/admin/amial/whatsapp/limits', ['max_amount' => '9999999', 'reason' => 'محاولة'])
            ->assertForbidden();

        $this->assertFalse($this->limit()->isConfigured(), 'رفع الدعمُ السقف');
    }
}
