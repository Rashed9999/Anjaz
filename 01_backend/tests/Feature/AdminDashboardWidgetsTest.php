<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-DASH-WIDGETS-001 — لوحة الإدارة: كلّ رقمٍ يُضغط، وكلّ رقمٍ يسأل عن الدور.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عطلان اثنان، وكلاهما لا يُنتج خطأً في أيّ سجلّ.**
 *
 * ── ١) أرقامُ المال تُعرض لمن لا يملك المال ────────────────────────
 *
 * زرّ «المركز المالي» في الترويسة كان يفحص `platform.money.move` —
 * والأرقامُ تحته لا تفحص شيئاً. فموظّفُ الدعم يُمنع من فتح المركز المالي
 * ويقرأ **خزينة المنصّة وأرباحَها وإجماليَّ التغذية** على الشاشة نفسها.
 *
 * والبابُ المقفل بجانب النافذة المفتوحة ليس حراسة. (القاعدة الرابعة:
 * ميزةٌ لها مدخلان تُحرَس من مدخليها — والرقمُ مدخلٌ كالزرّ.)
 *
 * ── ٢) وبطاقاتٌ تُعرض ولا تُضغط ─────────────────────────────────────
 *
 * ستُّ بطاقاتٍ في اللوحة، ثلاثٌ منها روابط وثلاثٌ `div` صامتة. والمدير
 * يقرأ «حجم السنة ٤٠ مليوناً» ولا طريق من الرقم إلى ما يفسّره.
 *
 * (`amial-interactive-ui`: «No fake UI. Everything visible must
 * function.» — وهي التي كشفت من قبلُ بوّابات `/login` غير القابلة للضغط.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والوجهةُ تُقاس لا تُفترض.**
 *
 * `admin.transaction.index` يقبل `date_type` بثلاث قيمٍ فقط: `all`
 * و`last_30` و`custom`. **ولا `today` فيها.** فرابطٌ إلى `date_type=today`
 * يفتح الصفحة ويردّ 200 ويعرض **كلّ** المعاملات — بلا خطأٍ في أيّ سجلّ.
 * وذاك عطلُ القاعدة التاسعة بعينه: «أين ذهبت» لا «هل ظهر خطأ».
 *
 * فيُفحص هنا أنّ الرابط يحمل `custom` وتاريخَ اليوم على طرفيه.
 */
class AdminDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(string $phone, ?string $roleCode): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'موظّف', 'l_name' => 'منصّة', 'phone' => $phone,
            'email' => $phone . '@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        if ($roleCode !== null) {
            app(PlatformRoleService::class)->assign($u, $roleCode);
        }

        return $u->fresh();
    }

    private function tx(int $userId, string $when, float $debit, float $charge = 0): void
    {
        DB::table('transactions')->insert([
            'user_id' => $userId, 'ref_trans_id' => 0,
            'transaction_type' => 'send_money',
            'debit' => $debit, 'credit' => 0, 'charge' => $charge,
            'amount' => $debit, 'transaction_id' => 'WIDGET-' . (++$this->seq),
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    /** يفتح اللوحة بدورٍ ما ويُعيد صفحتها. */
    private function dashboardAs(?string $roleCode, string $phone): string
    {
        return $this->actingAs($this->admin($phone, $roleCode), 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();
    }

    /**
     * كلُّ وسمٍ يحمل السمة المطلوبة ← [قيمتها => الرابط].
     *
     * **وترتيبُ السمات في HTML حرّ.** فتعبيرٌ يفترض `data-kpi` قبل `href`
     * يسقط حين تُبدَّل السمتان وحدهما — فيُقال «البطاقة بلا وجهة» وهي
     * موصولة. يُلتقط الوسمُ كاملاً أوّلاً ثمّ تُقرأ سمتاه بأيّ ترتيب.
     */
    private function linksBy(string $html, string $attr): array
    {
        preg_match_all('/<(\w+)\b[^>]*\b' . preg_quote($attr, '/') . '="([^"]*)"[^>]*>/u',
            $html, $tags, PREG_SET_ORDER);

        $out = [];
        foreach ($tags as [$whole, $element, $key]) {
            $href = preg_match('/\bhref="([^"]*)"/u', $whole, $h) ? $h[1] : null;
            $out[$key] = ['tag' => strtolower($element), 'href' => $href];
        }

        return $out;
    }

    /** يُفكّك استعلامَ رابطٍ خرج من Blade (وفيه `&amp;`). */
    private function query(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $q);

        return $q;
    }

    // ══════════════════════════════════════════════════════════════
    // ١) المال يسأل عن الدور
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **موظّفُ الدعم لا يقرأ خزينة المنصّة.**
     *
     * والرقمُ المختار للفحص فريدٌ عمداً (`8123456`) كي لا يُصادف رقماً
     * آخر في الصفحة فيمرّ الحارس وهو أعمى.
     */
    public function support_staff_does_not_see_platform_money_figures(): void
    {
        $admin = $this->admin('967770003001', PlatformRoleService::ADMIN);

        // خزينةٌ برقمٍ لا يتكرّر، ورسومٌ برقمٍ لا يتكرّر.
        DB::table('e_money')->updateOrInsert(
            ['user_id' => $admin->id],
            ['current_balance' => 8123456, 'pending_balance' => 0]
        );
        $this->tx($admin->id, now()->toDateTimeString(), 100, 7654321);

        $html = $this->dashboardAs(PlatformRoleService::SUPPORT, '967770003002');

        $this->assertStringNotContainsString(number_format(8123456, 0), $html,
            'موظّفُ الدعم يقرأ خزينة المنصّة — والزرّ وحده كان محروساً');
        $this->assertStringNotContainsString(number_format(7654321, 0), $html,
            'موظّفُ الدعم يقرأ أرباح المنصّة');
    }

    /**
     * @test
     *
     * **ولا يُعرض له صفرٌ مكانها — يُقال له إنّها ليست له.**
     *
     * (القاعدة السابعة: «غير معروف» ليس صفراً. وصفرٌ مكان رقمٍ محجوب
     * يجعل موظّف الدعم يبلّغ أنّ خزينة المنصّة فرغت.)
     */
    public function a_hidden_money_figure_is_declared_not_shown_as_zero(): void
    {
        $html = $this->dashboardAs(PlatformRoleService::SUPPORT, '967770003003');

        $this->assertStringContainsString('لا تُتاح بصلاحيّتك', $html,
            'المال محجوبٌ بلا تصريح — فيُقرأ غيابُه صفراً');
    }

    /**
     * @test
     *
     * **ومن يملك المال يراه.**
     *
     * وبلا هذا الطرف يمرّ الحارسُ ولو حُجب المال عن الجميع — وتلك لوحةٌ
     * لا تعمل لا لوحةٌ محروسة. (كلّ حارسٍ من طرفيه.)
     */
    public function the_platform_admin_still_sees_the_money(): void
    {
        $admin = $this->admin('967770003004', PlatformRoleService::ADMIN);
        DB::table('e_money')->updateOrInsert(
            ['user_id' => $admin->id],
            ['current_balance' => 8123456, 'pending_balance' => 0]
        );

        $html = $this->actingAs($admin, 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(number_format(8123456, 0), $html,
            'الأدمن نفسه لا يرى الخزينة — حُجب المال عن الجميع');
        $this->assertStringContainsString('data-testid="financial-map"', $html,
            'صاحب الصلاحية المالية لا يرى الخريطة التنفيذية لمصادر أرقامه');
    }

    /**
     * @test
     *
     * لوحة الانتباه لا تُظهر طوابير امتثال أو تدقيق لموظف الدعم؛ إخفاء
     * الرابط وحده لا يكفي إذا بقيت الأعداد أو الحالة في HTML.
     */
    public function attention_queue_is_scoped_to_the_operator_permissions(): void
    {
        $html = $this->dashboardAs(PlatformRoleService::SUPPORT, '9677700030049');

        $this->assertStringContainsString('data-attention="tickets"', $html);
        $this->assertStringNotContainsString('data-attention="approvals"', $html);
        $this->assertStringNotContainsString('data-attention="security"', $html);
        $this->assertStringNotContainsString('data-attention="reconciliation"', $html);
    }

    /** @test */
    public function an_admin_without_a_platform_role_does_not_receive_zero_valued_sensitive_kpis(): void
    {
        $html = $this->dashboardAs(null, '9677700030059');

        $this->assertStringContainsString('transactions-denied', $html);
        $this->assertStringNotContainsString('data-kpi="today"', $html);
        $this->assertStringContainsString('لا توجد مؤشرات متاحة لهذا الدور', $html);
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) كلّ بطاقةٍ تقود إلى مكان
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **لا بطاقةَ مؤشّرٍ بلا وجهة.**
     *
     * تُستخرج البطاقات بسِمَتِها ثمّ يُتأكَّد أنّ كلَّ واحدةٍ وسمُها `<a>`
     * وفيها `href` غيرُ فارغٍ وغيرُ `#`.
     */
    public function every_kpi_card_leads_somewhere(): void
    {
        $html = $this->dashboardAs(PlatformRoleService::ADMIN, '967770003005');

        $cards = $this->linksBy($html, 'data-kpi');

        $this->assertNotEmpty($cards, 'لا بطاقةَ مؤشّرٍ في الصفحة — القياس لاغٍ');

        $dead = [];
        foreach ($cards as $key => $c) {
            if ($c['tag'] !== 'a' || $c['href'] === null
                || $c['href'] === '' || $c['href'] === '#') {
                $dead[] = $key;
            }
        }

        $this->assertSame([], $dead,
            'بطاقاتٌ تُعرض ولا تُضغط: ' . implode('، ', $dead));
    }

    /**
     * @test
     *
     * **وبطاقةُ «اليوم» تفتح اليوم — لا كلَّ التاريخ.**
     *
     * و`date_type=today` لا وجود له في `TransactionController`: يُقرأ
     * فيُهمَل، فتُفتح الصفحة بكلّ المعاملات وتردّ 200. عطلٌ لا يُنتج
     * سطراً في أيّ سجلّ.
     */
    public function the_today_card_opens_today_not_all_of_history(): void
    {
        $html = $this->dashboardAs(PlatformRoleService::ADMIN, '967770003006');

        $card = $this->linksBy($html, 'data-kpi')['today'] ?? null;
        $this->assertNotNull($card, 'لا رابطَ لبطاقة اليوم');

        $q     = $this->query((string) $card['href']);
        $today = now()->toDateString();

        $this->assertSame('custom', $q['date_type'] ?? null,
            'date_type ليس custom — و«today» قيمةٌ لا يعرفها المتحكّم فتُهمَل');
        $this->assertSame($today, $q['start_date'] ?? null);
        $this->assertSame($today, $q['end_date'] ?? null);
    }

    /**
     * @test
     *
     * **والوجهةُ تُفتح فعلاً — لا يُكتفى بشكل الرابط.**
     *
     * (القاعدة التاسعة: زرٌّ لم يُضغط ليس مبنيّاً. ورابطٌ صحيحُ الصياغة
     * إلى مسارٍ غير مسجَّلٍ يعطي ٤٠٤، ولا يكشفه فحصُ النصّ.)
     */
    public function the_kpi_destinations_actually_open(): void
    {
        $admin = $this->admin('967770003007', PlatformRoleService::ADMIN);
        $html = $this->actingAs($admin, 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $cards = $this->linksBy($html, 'data-kpi');
        $this->assertNotEmpty($cards, 'لا بطاقاتٍ تُفحص');

        foreach ($cards as $key => $c) {
            $this->actingAs($admin, 'user')
                ->get(html_entity_decode((string) $c['href']))
                ->assertSuccessful();   // ٢xx — لا ٤٠٤ ولا ٤٠٣ ولا ٥٠٠
        }
    }

    /**
     * @test
     *
     * **وأعمدةُ الرسم تُضغط، وكلٌّ يفتح شهرَه هو.**
     *
     * (`amial-interactive-ui`: «WHEN CREATING CHARTS support Click,
     * Drill Down». ورسمٌ لا يُضغط زينةٌ لا أداةُ قرار.)
     */
    public function each_chart_bar_drills_into_its_own_month(): void
    {
        $admin = $this->admin('967770003008', PlatformRoleService::ADMIN);
        $y = (int) now()->year;
        $this->tx($admin->id, Carbon::create($y, 3, 10, 12)->toDateTimeString(), 400);

        $html = $this->actingAs($admin, 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $bars = $this->linksBy($html, 'data-month');

        $this->assertCount(12, $bars, 'الأعمدة الاثنا عشر ليست كلُّها روابط');

        foreach ($bars as $month => $bar) {
            $this->assertSame('a', $bar['tag'], "العمود {$month} ليس رابطاً");

            $q    = $this->query((string) $bar['href']);
            $from = Carbon::create($y, (int) $month, 1);

            $this->assertSame('custom', $q['date_type'] ?? null, "الشهر {$month}");
            $this->assertSame($from->toDateString(), $q['start_date'] ?? null, "الشهر {$month}");

            // آخرُ يوم يُحسب لا يُكتب — وهو العطل نفسه الذي أسقط ٣١ آذار.
            $this->assertSame($from->copy()->endOfMonth()->toDateString(),
                $q['end_date'] ?? null, "آخرُ يومٍ خاطئٌ في الشهر {$month}");
        }
    }
}
