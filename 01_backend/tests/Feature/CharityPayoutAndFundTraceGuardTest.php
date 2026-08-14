<?php

namespace Tests\Feature;

use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\User;
use App\Services\CharityService;
use App\Services\DonationsService;
use App\Services\LedgerService;
use App\Services\PlatformRoleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-CHARITY-PAYOUT-001 · AMIAL-FUND-DETAIL-001 · AMIAL-OPERATOR-CREATE-001
 *
 * ══════════════════════════════════════════════════════════════════════
 * ثلاثةُ أسئلةٍ سألها صاحبُ المشروع، وكلُّها من صنفٍ واحد: **مبنيٌّ ولا
 * يُوصَل إليه.**
 *
 *  ① «طريقة سحب المال من عملية التبرّع إلى محفظة أميال باي أو عبر وكيل»
 *
 *     والقياسُ كشف أسوأ ممّا سُئل: `CHARITY_ESCROW` يُدائَن في كلّ
 *     تبرّع، **ولا يُدان في المشروع كلِّه**. و«تمّ التحويل» كانت حقلَ
 *     نصٍّ يُكتب فيه رقمُ حوالة — لا قيدَ ولا رصيدَ يتحرّك. فالعهدةُ
 *     تكبر إلى الأبد والالتزامُ لا يُطفأ.
 *
 *  ② «صناديق العائلة يجب إظهار التفاصيل … أين اختفى المال من الصندوق،
 *     من سحبه؟»
 *
 *     والشاشةُ كانت خمسةَ أعمدة آخرُها «الرصيد» — ورصيدٌ بلا حركةٍ لا
 *     يجيب. و`family_fund_transactions` مكتوبٌ منذ بُنيت الصناديق ولا
 *     شاشةَ تقرؤه.
 *
 *  ③ «أدوار المنصّة والموظّفين يجب إنشاء موظّف مع اختيار صلاحيّاته
 *     ورقم الهاتف وكلمة المرور»
 *
 *     وصفحةُ الأدوار كانت تعرض الأدوار وتُسندها لحساباتٍ قائمة — ولا
 *     بابَ يُنشئ الحساب أصلاً.
 * ══════════════════════════════════════════════════════════════════════
 */
class CharityPayoutAndFundTraceGuardTest extends TestCase
{
    use RefreshDatabase;

    private CharityService $charity;
    private DonationsService $donations;
    private User $admin;
    private CharityOrganization $org;
    private CharityCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        config()->set('amial.donations.fee_percent', '2.0');

        $this->charity = app(CharityService::class);
        $this->donations = app(DonationsService::class);

        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($this->admin, PlatformRoleService::ADMIN);

        $this->category = CharityCategory::create([
            'code' => 'relief', 'name_ar' => 'إغاثة', 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->org = CharityOrganization::create([
            'org_ulid' => 'CHPAYOUT0000000000000000AA',
            'name_ar' => 'جمعيّة الخير',
            'license_number' => 'LIC-PAYOUT-1',
            'description_ar' => 'وصف',
            'contact_phone' => '+967100000077',
            'verification_status' => 'pending_verification',
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);
    }

    /** تسويةٌ معلَّقةٌ خلف تبرّعٍ حقيقيّ — لا صفٌّ مصنوعٌ يدوياً. */
    private function pendingSettlement(string $amount = '500.0000'): \App\Models\CharitySettlement
    {
        $this->charity->verifyOrganization($this->org, $this->admin);
        $campaign = $this->charity->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'حملة', 'description_ar' => 'وصف',
            'target_amount' => '100000.0000',
        ], $this->admin);
        $this->charity->approveCampaign($campaign, $this->admin);

        $donor = User::factory()->create(['zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $donor->id, 'current_balance' => '100000.0000']);
        $this->donations->donate($donor, $campaign, $amount);

        return $this->charity->generateSettlement(
            $this->org, Carbon::now()->subDay(), Carbon::now()->addDay(), $this->admin,
        );
    }

    private function escrowBalance(): string
    {
        $account = LedgerAccount::where('account_code', 'like', '%CHARITY_ESCROW%')->first()
            ?? LedgerAccount::where('name_ar', 'like', '%عهدة التبرعات%')->first();

        $this->assertNotNull($account, 'حسابُ عهدة التبرّعات غير موجود — والتبرّعُ يُقيَّد عليه');

        return app(LedgerService::class)->computeBalanceFromLines($account->id);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① صرفُ التبرّعات — العهدةُ تُدان فعلاً
    // ══════════════════════════════════════════════════════════════════

    public function test_donation_escrow_is_debited_when_settlement_is_paid_to_a_wallet(): void
    {
        $settlement = $this->pendingSettlement('500.0000');
        $payable = (string) $settlement->payable_amount;   // 490 بعد رسم 2%

        $before = $this->escrowBalance();
        $this->assertTrue(bccomp($before, '0', 4) > 0, 'العهدةُ صفرٌ بعد تبرّعٍ — القيدُ لم يُكتب');

        $recipient = User::factory()->create(['phone' => '967771900001']);
        EMoney::create(['user_id' => $recipient->id, 'current_balance' => '0.0000']);

        $this->charity->payoutSettlement(
            $settlement, $this->admin, 'wallet', 'REF-W-1', $recipient,
        );

        // **المحفظةُ زادت بالمبلغ نفسِه** — لا بمبلغٍ آخرَ ولا بصفر.
        $this->assertSame(
            0,
            bccomp((string) EMoney::where('user_id', $recipient->id)->value('current_balance'), $payable, 4),
            'الرصيدُ لم يُشحن بمبلغ التسوية',
        );

        // **والعهدةُ نقصت بالمبلغ نفسِه.**
        $after = $this->escrowBalance();
        $this->assertSame(
            0,
            bccomp(bcsub($before, $after, 4), $payable, 4),
            "العهدةُ لم تُدان: قبل={$before} بعد={$after} المستحقّ={$payable}",
        );
    }

    public function test_payout_links_the_settlement_to_its_journal_entry(): void
    {
        $settlement = $this->pendingSettlement();
        $recipient = User::factory()->create(['phone' => '967771900002']);
        EMoney::create(['user_id' => $recipient->id, 'current_balance' => '0.0000']);

        $paid = $this->charity->payoutSettlement(
            $settlement, $this->admin, 'wallet', 'REF-W-2', $recipient,
        );

        // **بلا رابطٍ إلى الدفتر يبقى الصرفُ رقماً في شاشة.**
        $this->assertNotNull($paid->payout_journal_entry_id, 'التسويةُ صُرفت بلا رقم قيد');
        $this->assertSame('wallet', $paid->payout_method);
        $this->assertSame($recipient->id, (int) $paid->payout_user_id);

        $this->assertTrue(
            LedgerEntryLine::where('journal_entry_id', $paid->payout_journal_entry_id)->exists(),
            'رقمُ القيد مسجَّلٌ ولا سطورَ خلفه',
        );
    }

    public function test_agent_payout_credits_the_agent_float(): void
    {
        $settlement = $this->pendingSettlement('300.0000');
        $payable = (string) $settlement->payable_amount;

        $agent = User::factory()->create(['type' => AGENT_TYPE, 'phone' => '967771900003']);
        EMoney::create(['user_id' => $agent->id, 'current_balance' => '0.0000']);

        $paid = $this->charity->payoutSettlement(
            $settlement, $this->admin, 'agent', 'REF-A-1', $agent,
        );

        $this->assertSame('agent', $paid->payout_method);
        $this->assertSame(
            0,
            bccomp((string) EMoney::where('user_id', $agent->id)->value('current_balance'), $payable, 4),
            'الوكيلُ دفع نقداً ولم يأخذ رصيداً مقابله',
        );
    }

    public function test_agent_payout_refuses_an_account_that_is_not_an_agent(): void
    {
        $settlement = $this->pendingSettlement();
        $notAgent = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771900004']);
        EMoney::create(['user_id' => $notAgent->id, 'current_balance' => '0.0000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ليس وكيلاً');

        $this->charity->payoutSettlement($settlement, $this->admin, 'agent', 'REF-A-2', $notAgent);
    }

    public function test_a_settlement_cannot_be_paid_twice(): void
    {
        $settlement = $this->pendingSettlement();
        $recipient = User::factory()->create(['phone' => '967771900005']);
        EMoney::create(['user_id' => $recipient->id, 'current_balance' => '0.0000']);

        $this->charity->payoutSettlement($settlement, $this->admin, 'wallet', 'REF-W-3', $recipient);

        // **الصرفُ مرّتين يخلق مالاً من العدم** — والحالةُ هي البوّابة.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ليست معلَّقة');

        $this->charity->payoutSettlement(
            $settlement->refresh(), $this->admin, 'wallet', 'REF-W-4', $recipient,
        );
    }

    public function test_wallet_payout_refuses_to_run_without_a_recipient(): void
    {
        $settlement = $this->pendingSettlement();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('المستلم');

        $this->charity->payoutSettlement($settlement, $this->admin, 'wallet', 'REF-W-5', null);
    }

    public function test_the_payout_endpoint_is_reachable_from_the_panel(): void
    {
        $settlement = $this->pendingSettlement();
        $recipient = User::factory()->create(['phone' => '967771900006']);
        EMoney::create(['user_id' => $recipient->id, 'current_balance' => '0.0000']);

        // **من مدخل الشاشة لا من الخدمة** (القاعدة الرابعة).
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/charity/settlements/' . $settlement->settlement_ulid . '/payout', [
                'method' => 'wallet',
                'reference' => 'REF-HTTP-1',
                'recipient_phone' => '967771900006',
            ])->assertOk();

        $this->assertSame('transferred', (string) $settlement->refresh()->status);
    }

    public function test_the_payout_button_exists_on_the_donations_page(): void
    {
        // القاعدة الثانية عشرة: **مسارٌ مسجَّلٌ ليس ظهوراً.**
        $blade = base_path('resources/views/admin-views/amial/charity/index.blade.php');
        $html = file_get_contents($blade);

        foreach (['data-testid="settlement-payout"', 'data-testid="payout-form"',
                  '/payout', 'payout-method'] as $needle) {
            $this->assertStringContainsString($needle, $html, "شاشةُ التبرّعات بلا: {$needle}");
        }

        // ولا نوافذَ متصفّحٍ في شاشةٍ تُحرّك مالاً (AMIAL-UI-DIALOGS-002).
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])confirm\s*\(/', $html);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])prompt\s*\(/', $html);
    }

    public function test_the_donations_page_can_create_organizations_and_campaigns(): void
    {
        // «لا يوجد إنشاء تبرّعات» — والصفحةُ كانت قوائمَ وأزرارَ اعتمادٍ فقط.
        $html = file_get_contents(base_path('resources/views/admin-views/amial/charity/index.blade.php'));

        foreach (['id="org-form"', 'id="camp-form"',
                  'name="target_amount"', 'name="deadline_at"',
                  'name="cover_image_url"', 'name="gallery_images"',
                  'data-donors'] as $needle) {
            $this->assertStringContainsString($needle, $html, "شاشةُ التبرّعات بلا: {$needle}");
        }
    }

    public function test_the_donors_endpoint_hides_anonymous_donors_even_from_admin(): void
    {
        $this->charity->verifyOrganization($this->org, $this->admin);
        $campaign = $this->charity->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'حملة', 'description_ar' => 'وصف',
            'target_amount' => '10000.0000',
        ], $this->admin);
        $this->charity->approveCampaign($campaign, $this->admin);

        $donor = User::factory()->create(['f_name' => 'سرّيّ', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $donor->id, 'current_balance' => '5000.0000']);
        $this->donations->donate($donor, $campaign, '100.0000', true);

        $body = $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/charity/campaigns/' . $campaign->campaign_ulid . '/donors')
            ->assertOk()->json();

        $donors = data_get($body, 'meta.donors', []);
        $this->assertNotEmpty($donors, 'المتبرّعون فارغون بعد تبرّعٍ حقيقيّ');

        // **الإخفاءُ وعدٌ للمتبرّع لا زينةٌ في التطبيق.**
        $this->assertTrue((bool) $donors[0]['is_anonymous']);
        $this->assertNull($donors[0]['donor'] ?? null, 'اسمُ متبرّعٍ مجهولٍ ظهر للإدارة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② صندوقُ العائلة — «أين اختفى المال ومَن سحبه»
    // ══════════════════════════════════════════════════════════════════

    /** @return array{0:int,1:int} [fund_id, actor_id] */
    private function fundWithMovement(): array
    {
        $owner = User::factory()->create(['f_name' => 'أبو', 'l_name' => 'الصندوق']);

        $fundId = DB::table('family_funds')->insertGetId([
            'fund_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'name' => 'صندوق الاختبار',
            'owner_user_id' => $owner->id,
            'balance' => '700.0000',
            'status' => 'active',
            'zone_code' => 'SOUTH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('family_fund_transactions')->insert([
            [
                'tx_ulid' => (string) \Illuminate\Support\Str::ulid(),
                'fund_id' => $fundId, 'tx_type' => 'contribute',
                'amount' => '1000.0000', 'balance_before' => '0.0000', 'balance_after' => '1000.0000',
                'user_id' => $owner->id, 'status' => 'completed', 'created_at' => now(),
            ],
            [
                'tx_ulid' => (string) \Illuminate\Support\Str::ulid(),
                'fund_id' => $fundId, 'tx_type' => 'disburse_to_member',
                'amount' => '300.0000', 'balance_before' => '1000.0000', 'balance_after' => '700.0000',
                'user_id' => $owner->id, 'status' => 'completed', 'created_at' => now(),
            ],
        ]);

        return [$fundId, $owner->id];
    }

    public function test_the_fund_row_opens_a_detail_page(): void
    {
        [$fundId] = $this->fundWithMovement();

        // **الاسمُ يُنقر** — القاعدة الثانية عشرة: مسارٌ بلا رابطٍ ليس ظهوراً.
        $list = file_get_contents(base_path('resources/views/admin-views/amial/surface/funds.blade.php'));
        $this->assertStringContainsString('data-testid="fund-open"', $list);
        $this->assertStringContainsString('surface.funds.detail', $list);

        $this->actingAs($this->admin, 'user')
            ->get('/admin/amial/surface/funds/' . $fundId)
            ->assertOk()
            ->assertSee('حركة الصندوق', false)
            ->assertSee('fund-transactions', false);
    }

    public function test_the_fund_detail_names_who_moved_the_money(): void
    {
        [$fundId, $actorId] = $this->fundWithMovement();

        $html = $this->actingAs($this->admin, 'user')
            ->get('/admin/amial/surface/funds/' . $fundId)->assertOk()->getContent();

        // من سحب: اسمُ المُنفِّذ ورابطٌ إلى حسابه.
        $this->assertStringContainsString('أبو', $html, 'من نفّذ الحركة غيرُ مذكور');
        $this->assertStringContainsString('/hub/account/' . $actorId, $html,
            'اسمُ المُنفِّذ مذكورٌ ولا يُنقر — والسؤالُ التالي دائماً «ومن هو؟»');

        // وكم: الإيداعُ والسحبُ كلاهما ظاهر.
        $this->assertStringContainsString('1,000', $html);
        $this->assertStringContainsString('300', $html);
    }

    public function test_the_fund_detail_flags_a_balance_that_the_movement_cannot_explain(): void
    {
        [$fundId] = $this->fundWithMovement();

        // الرصيدُ يطابق الحركة (1000 − 300 = 700) ⇒ لا فجوة.
        $ok = $this->actingAs($this->admin, 'user')
            ->get('/admin/amial/surface/funds/' . $fundId)->assertOk()->getContent();
        $this->assertStringContainsString('يطابق الحركة', $ok);

        // **ثمّ يُكسر عمداً** — القاعدة الثانية: حارسٌ لم يسقط ليس حارساً.
        // الرصيدُ المخزَّن يُرفع بلا حركةٍ تفسّره: هذا بعينه «أين اختفى المال».
        DB::table('family_funds')->where('id', $fundId)->update(['balance' => '9999.0000']);

        $broken = $this->actingAs($this->admin, 'user')
            ->get('/admin/amial/surface/funds/' . $fundId)->assertOk()->getContent();

        $this->assertStringContainsString('فرقٌ قدره', $broken,
            'الرصيدُ لا تفسّره الحركةُ والشاشةُ صامتة');
        // **والفجوةُ تُقال برقمها** — «لا يطابق» وحدها لا تُرشد.
        $this->assertStringContainsString('9,299', $broken);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ إنشاءُ موظّفٍ بصلاحيّاته
    // ══════════════════════════════════════════════════════════════════

    public function test_the_roles_page_has_a_form_that_creates_an_employee(): void
    {
        $html = file_get_contents(base_path('resources/views/admin-views/amial/ops/roles.blade.php'));

        foreach (['data-testid="operator-create-form"', 'name="phone"',
                  'name="password"', 'role_ids'] as $needle) {
            $this->assertStringContainsString($needle, $html, "صفحةُ الأدوار بلا: {$needle}");
        }
    }

    public function test_creating_an_employee_grants_the_chosen_roles_in_one_step(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'code' => 'auditor_guard_test', 'label_ar' => 'مدقّق',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'user')
            ->post('/admin/amial/ops/operators', [
                'f_name' => 'موظّف', 'l_name' => 'جديد',
                'phone' => '967771900010',
                'password' => 'Passw0rd!2026',
                'role_ids' => [$roleId],
            ])->assertRedirect();

        $created = User::whereIn('phone', \App\Support\Phone::variants('967771900010'))->first();
        $this->assertNotNull($created, 'اللوحةُ ردّت بنجاح ولا حسابَ في القاعدة');
        $this->assertSame(ADMIN_TYPE, (int) $created->type, 'الموظّفُ أُنشئ بنوعٍ لا يدخل اللوحة');

        // **الصلاحيّةُ تُسند مع الإنشاء** — وحسابٌ بلا دورٍ يدخل ولا يرى شيئاً،
        // فيعود صاحبُه يسأل «لماذا لا تعمل؟».
        $this->assertTrue(
            DB::table('admin_user_roles')->where('user_id', $created->id)
                ->where('role_id', $roleId)->exists(),
            'أُنشئ الموظّفُ بلا الدور الذي اختير له',
        );
    }

    public function test_creating_an_employee_refuses_a_phone_that_is_already_taken(): void
    {
        User::factory()->create(['phone' => '967771900011']);

        $this->actingAs($this->admin, 'user')
            ->post('/admin/amial/ops/operators', [
                'f_name' => 'مكرَّر',
                'phone' => '967771900011',
                'password' => 'Passw0rd!2026',
            ])->assertSessionHasErrors('phone');

        // **رقمٌ بحسابين يكسر الدخول نفسَه**: من يسجّل الدخول لا يُعرف مَن هو.
        $this->assertSame(
            1,
            User::whereIn('phone', \App\Support\Phone::variants('967771900011'))->count(),
        );
    }
}
