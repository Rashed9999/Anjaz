<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-RECON-NIGHTLY-001 — المصالحة تكشف الفرق، وتقول ما لم تفحصه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما يُختبَر هنا ليس أنّها تعمل — بل أنّها تسقط.**
 *
 * مصالحةٌ تقول «لا فرق» على قاعدةٍ سليمة لا تُثبت شيئاً: دالّةٌ تُرجع
 * `clean` دائماً تمرّ الاختبار نفسَه. فيُزرع فرقٌ معلومُ المقدار
 * ويُتأكَّد أنّها **رأته وقاسته بالضبط**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والصفُّ يُكتب حتّى حين لا فرق.**
 *
 * لأنّ صمت الإنذار لا يعني السلامة — قد يعني أنّ المهمّة توقّفت. وليلةٌ
 * بلا صفٍّ تُقرأ لاحقاً «كانت سليمة»، وهي لم تُفحص.
 * (القاعدة السابعة: «غير معروف» ليس صفراً.)
 */
class ReconciliationNightlyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * عميلٌ بمحفظةٍ متّسقةٍ مع الدفتر.
     *
     * و`EMoneyObserver` يُرحّل الرصيد الابتدائيّ عند الإنشاء — فمحفظةٌ
     * تُنشأ برصيدٍ لا تُنتج فرقاً، وهو تصميمٌ سليم.
     */
    private function customer(string $phone, string $balance = '0.0000'): User
    {
        $u = User::factory()->create(['type' => 2, 'phone' => $phone, 'zone_code' => 'SOUTH']);

        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    /**
     * **الفرقُ الواقعيّ: رصيدٌ يتغيّر بلا قيدٍ يقابله.**
     *
     * وهو ما يقع فعلاً حين تُحرّك خدمةٌ محفظةً ولا تُرحّل — كما كانت
     * `MerchantSaleRefundService` قبل إصلاحها اليوم. فيُكتب مباشرةً على
     * الجدول لتخطّي المراقب، تماماً كما يفعل استعلامٌ خام في الإنتاج.
     */
    private function driftWallet(User $u, string $newBalance): void
    {
        DB::table('e_money')->where('user_id', $u->id)
            ->update(['current_balance' => $newBalance]);
    }

    private function svc(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    // ══════════════════════════════════════════════════════════════
    // ١) تكشف الفرق — وتقيسه
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **محفظةٌ فيها مالٌ بلا قيدٍ يقابله = فرقٌ يُرى ويُقاس.**
     *
     * وهذا هو الحدث الذي بُنيت له: ريالٌ في محفظةٍ لا يعرفه الدفتر.
     */
    public function a_wallet_with_no_matching_ledger_entry_is_caught_and_measured(): void
    {
        $this->driftWallet($this->customer('967770009001'), '7500.0000');

        $r = $this->svc()->run();

        $this->assertSame('diverged', $r['status'],
            'محفظةٌ بلا قيدٍ ولم تُكتشف — المصالحة عمياء');

        $this->assertSame(1, $r['wallets']['diverged']);
        $this->assertSame(0, bccomp($r['wallets']['gap'], '7500', 4),
            "الفرق قِيس {$r['wallets']['gap']} والمزروع 7500");
    }

    /**
     * @test
     *
     * **ومحفظةٌ صفريّةٌ ليست فرقاً.**
     *
     * وبلا هذا الطرف تمرّ مصالحةٌ تُرجع `diverged` دائماً — وتلك إنذارٌ
     * دائمٌ يُصمَّت بعد أسبوع، ثمّ يصرخ يوماً بحقٍّ فلا يسمعه أحد.
     */
    public function a_clean_ledger_reports_clean(): void
    {
        $this->customer('967770009002', '0.0000');

        $r = $this->svc()->run();

        $this->assertSame('clean', $r['status'],
            'قاعدةٌ سليمةٌ أُبلغ عنها فرق — إنذارٌ كاذب');
        $this->assertSame(0, $r['wallets']['diverged']);
    }

    /** A ledger liability with no operational wallet is not a clean balance. */
    public function a_ledger_wallet_without_an_emoney_row_is_caught(): void
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967770009005']);
        $wallet = LedgerAccount::create([
            'account_code' => "USER_WALLET_{$user->id}", 'account_type' => 'liability',
            'name_ar' => 'محفظة مفقودة', 'owner_user_id' => $user->id,
            'owner_type' => 'user', 'normal_balance' => 'credit',
            'current_balance' => '100.0000', 'zone_code' => 'SOUTH',
        ]);
        $reserve = LedgerAccount::create([
            'account_code' => 'TEST_RECON_RESERVE', 'account_type' => 'asset',
            'name_ar' => 'احتياطي الفحص', 'owner_type' => 'platform',
            'normal_balance' => 'debit', 'current_balance' => '100.0000', 'zone_code' => 'SOUTH',
        ]);
        $entry = LedgerJournalEntry::create([
            'entry_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'source_type' => 'test', 'description_ar' => 'محفظة بلا صف تشغيلي',
            'total_amount' => '100', 'status' => 'posted', 'zone_code' => 'SOUTH',
            'posted_at' => now(), 'created_at' => now(),
        ]);
        LedgerEntryLine::insert([
            ['journal_entry_id' => $entry->id, 'account_id' => $reserve->id, 'direction' => 'debit',
                'amount' => '100', 'balance_before' => '0', 'balance_after' => '100', 'created_at' => now()],
            ['journal_entry_id' => $entry->id, 'account_id' => $wallet->id, 'direction' => 'credit',
                'amount' => '100', 'balance_before' => '0', 'balance_after' => '100', 'created_at' => now()],
        ]);

        $r = $this->svc()->run();

        $this->assertSame('diverged', $r['status']);
        $this->assertSame(1, $r['wallets']['diverged']);
        $this->assertTrue((bool) ($r['wallets']['worst'][0]['missing_wallet'] ?? false),
            'حسابٌ دفتري بلا محفظة تشغيلية اختفى من المصالحة');
    }

    /**
     * @test
     *
     * **ولا حدَّ لعدد المحافظ المفحوصة.**
     *
     * `walletReconciliation()` تأخذ `limit = 200` لأنّها بُنيت لشاشة.
     * ومصالحةٌ تفحص مئتين وتسكت عن الباقي **تكذب**: تقول «لا فرق» وهي لم
     * تنظر. فيُزرع الفرقُ في محفظةٍ بعد المئتين.
     */
    public function the_check_does_not_stop_at_two_hundred_wallets(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->customer('9677700' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), '0.0000');
        }

        // الفرقُ في الأخيرة — خارج نافذة الشاشة.
        $this->driftWallet($this->customer('967770009999'), '333.0000');

        $r = $this->svc()->run();

        $this->assertGreaterThan(200, $r['wallets']['checked'],
            'توقّف الفحص عند حدّ الشاشة');

        $this->assertSame(1, $r['wallets']['diverged'],
            'فرقٌ بعد المحفظة ٢٠٠ لم يُرَ — المصالحة تقول «سليم» ولم تنظر');
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) العمى يُعلَن
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **كلُّ تقريرٍ يذكر حدوده — لا المُنذِرُ وحده.**
     *
     * فتقريرٌ نظيفٌ بلا ذكرِ ما لم يُفحص يُقرأ إحاطةً كاملة، وليس كذلك.
     */
    public function every_report_declares_what_it_did_not_check(): void
    {
        $r = $this->svc()->run();

        $this->assertSame('clean', $r['status']);
        $this->assertNotEmpty($r['blind_spots'],
            'تقريرٌ نظيفٌ بلا إعلانِ عمى — يُقرأ إحاطةً كاملة وليس كذلك');

        foreach ($r['blind_spots'] as $b) {
            $this->assertNotSame('', trim($b['why']),
                "عمىً بلا سبب: {$b['service']}");
        }
    }

    /**
     * @test
     *
     * **وقائمةُ العمى لا تدّعي جهلاً بما صار معلوماً.**
     *
     * فمن سدّد دَينَ خدمةٍ (صارت تُرحّل) ولم يحذفها من القائمة، يبقى
     * التقريرُ يعتذر عن ثغرةٍ أُغلقت. **والاعتذارُ الباطل يُخفي فرقاً
     * حقيقيّاً**: يُقرأ الفرقُ «هذا من العمى المعلوم» وهو ضياعُ مال.
     *
     * فيُقارَن بالمرحِّلين فعلاً.
     */
    public function the_blind_spot_list_contains_no_service_that_already_posts(): void
    {
        $posting = [];

        foreach (glob(base_path('app/Services/*.php')) as $f) {
            if (str_contains((string) file_get_contents($f), 'LedgerService')) {
                $posting[] = basename($f, '.php');
            }
        }

        $this->assertNotEmpty($posting, 'لم يُعثر على خدمةٍ مرحِّلة — القياس لاغٍ');

        $stale = array_intersect(
            array_column($this->svc()->blindSpots(), 'service'),
            $posting
        );

        $this->assertSame([], array_values($stale),
            'خدمةٌ تُرحّل وما زالت في قائمة العمى: ' . implode('، ', $stale));
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) الأثر يُكتب
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الأمرُ يكتب صفّاً — حتّى حين لا فرق.**
     */
    public function the_command_records_a_row_even_when_clean(): void
    {
        $this->artisan('amial:reconcile-nightly --quiet-alerts')->assertSuccessful();

        $row = DB::table('reconciliation_runs')->latest('id')->first();

        $this->assertNotNull($row, 'جرت المصالحة ولم تترك أثراً');
        $this->assertSame('clean', $row->status);
        $this->assertNotNull($row->blind_spots, 'الصفُّ بلا إعلانِ عمى');
    }

    /**
     * @test
     *
     * **ويكتب `diverged` حين يوجد فرق.**
     */
    public function the_command_records_divergence(): void
    {
        $this->driftWallet($this->customer('967770009003'), '1234.0000');

        $this->artisan('amial:reconcile-nightly --quiet-alerts')->assertSuccessful();

        $row = DB::table('reconciliation_runs')->latest('id')->first();

        $this->assertSame('diverged', $row->status);
        $this->assertSame(1, (int) $row->wallets_diverged);
        $this->assertSame(0, bccomp((string) $row->wallets_gap, '1234', 4));
    }

    /**
     * @test
     *
     * **ولا يُسقط الإنذارُ الساقط المصالحةَ.**
     *
     * فالصفُّ يُكتب قبل الإرسال. ولو أُنذر أوّلاً لضاع الأثر عند انقطاع
     * الشبكة: تعرف أنّ شيئاً وقع ولا تجد له صفّاً.
     */
    public function a_failing_alert_does_not_lose_the_record(): void
    {
        config(['amial.reconciliation.alert_numbers' => ['967770000000']]);

        $this->driftWallet($this->customer('967770009004'), '99.0000');

        // لا مزوّدَ مُفعَّل ⇒ الإرسال يُخفق — والمصالحة تنجح.
        $this->artisan('amial:reconcile-nightly')->assertSuccessful();

        $row = DB::table('reconciliation_runs')->latest('id')->first();

        $this->assertNotNull($row, 'سقط الإنذار فضاع صفُّ المصالحة');
        $this->assertSame('diverged', $row->status);
    }

    /**
     * @test
     *
     * **والدفترُ غيرُ المتوازن يُكتشف وحده.**
     *
     * وهو فحصٌ مستقلّ: محافظُ العملاء قد تطابق الدفتر وهو غيرُ متوازنٍ
     * في ذاته، لو كان النقصُ في حسابٍ ليس محفظةَ عميل.
     */
    public function an_unbalanced_ledger_is_caught_independently(): void
    {
        $account = DB::table('ledger_accounts')->insertGetId([
            'account_code' => 'TEST_SUSPENSE', 'account_type' => 'asset',
            'name_ar' => 'حساب فحص', 'normal_balance' => 'debit',
            'current_balance' => 0, 'currency' => 'YER', 'zone_code' => 'SOUTH',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $entry = DB::table('ledger_journal_entries')->insertGetId([
            'entry_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'source_type' => 'test', 'description_ar' => 'قيدٌ ناقصُ الطرف',
            'total_amount' => 500, 'status' => 'posted', 'zone_code' => 'SOUTH',
            'posted_at' => now(), 'created_at' => now(),
        ]);

        // طرفٌ واحدٌ فقط — لا يقابله دائن.
        DB::table('ledger_entry_lines')->insert([
            'journal_entry_id' => $entry, 'account_id' => $account,
            'direction' => 'debit', 'amount' => 500,
            'balance_before' => 0, 'balance_after' => 500, 'created_at' => now(),
        ]);

        $r = $this->svc()->run();

        $this->assertSame('diverged', $r['status'],
            'قيدٌ ناقصُ الطرف ولم يُكتشف');
        $this->assertSame(0, bccomp($r['ledger']['net'], '500', 4),
            "صافي الدفتر {$r['ledger']['net']} والمزروع 500");
    }
}
