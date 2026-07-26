<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuditService;
use App\Services\ZonePolicyService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

/**
 * AMIAL-ZONE-001 — اختبارات وحدة لمصفوفة قرار سياسة المنطقة (جنوب فقط).
 *
 * يثبت كامل منطق authorize() (مسموح/مرفوض بكل decision_code) دون قاعدة بيانات،
 * عبر مضاعِف اختبار لـ AuditService (مسار الرفض يكتب تدقيقاً فقط). كما يغطّي
 * detectRequestZone() و buildSessionPolicy() (منطق نقي).
 */
class ZonePolicyUnitTest extends TestCase
{
    private ZonePolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        // مضاعِف اختبار: مسار الرفض يستدعي record() فقط — نُبطله لتفادي قاعدة البيانات.
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnNull();
        $this->policy = new ZonePolicyService($audit);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function userInZone(?string $zone): User
    {
        return (new User())->forceFill(['id' => 1, 'zone_code' => $zone]);
    }

    private function requestWithZoneHeader(?string $zone): Request
    {
        $req = Request::create('/api/v1/test', 'GET');
        if ($zone !== null) {
            $req->headers->set('X-Amial-Zone', $zone);
        }
        return $req;
    }

    // ---------- authorize(): غير المصادَق ----------

    public function test_unauthenticated_financial_action_is_denied(): void
    {
        $d = $this->policy->authorize(null, 'send_money');
        $this->assertFalse($d['allowed']);
        $this->assertSame('AUTH_REQUIRED', $d['decision_code']);
    }

    public function test_unauthenticated_read_action_is_allowed(): void
    {
        $d = $this->policy->authorize(null, 'view_balance');
        $this->assertTrue($d['allowed']);
        $this->assertSame('ALLOWED_PUBLIC', $d['decision_code']);
    }

    // ---------- authorize(): المنطقة ----------

    public function test_south_user_financial_action_allowed(): void
    {
        $d = $this->policy->authorize($this->userInZone('SOUTH'), 'send_money');
        $this->assertTrue($d['allowed']);
        // AMIAL-ZONE-BOUNDARY-001: التحويل صار عملية دفتر (لا نقد يعبر)
        // فرمز القرار ALLOWED_LEDGER_ONLY. الإذن نفسه لم يتغيّر.
        $this->assertSame('ALLOWED_LEDGER_ONLY', $d['decision_code']);
        $this->assertSame('SOUTH', $d['account_zone']);
    }

    public function test_north_user_cash_action_blocked(): void
    {
        // AMIAL-ZONE-BOUNDARY-001: كان الفحص على send_money، وهو عملية دفتر
        // لا يعبر فيها نقد فلم يعد يُحجَب. الحجب حيث يُلمَس النقد فعلاً.
        $d = $this->policy->authorize($this->userInZone('NORTH'), 'cash_out');
        $this->assertFalse($d['allowed']);
        $this->assertSame('TX_ZONE_BLOCKED', $d['decision_code']);
        $this->assertSame('NORTH', $d['account_zone']);
    }

    public function test_north_user_ledger_action_allowed(): void
    {
        // الوجه الآخر للقاعدة: التحويل يبقى عاملاً لمن انتقل أو سافر —
        // القيمة تنتقل داخل دفتر واحد ولا تلمس بنكاً ولا صرفاً.
        $d = $this->policy->authorize($this->userInZone('NORTH'), 'send_money');
        $this->assertTrue($d['allowed']);
        $this->assertSame('ALLOWED_LEDGER_ONLY', $d['decision_code']);
    }

    public function test_unknown_zone_user_blocked_with_zone_unknown_code(): void
    {
        $d = $this->policy->authorize($this->userInZone(null), 'send_money');
        $this->assertFalse($d['allowed']);
        $this->assertSame('ACCOUNT_ZONE_UNKNOWN', $d['decision_code']);
    }

    public function test_read_action_allowed_in_any_zone(): void
    {
        foreach (['SOUTH', 'NORTH', 'MIDDLE', 'OTHER'] as $zone) {
            $d = $this->policy->authorize($this->userInZone($zone), 'view_history');
            // ملاحظة: UNKNOWN يُمنع حتى من القراءة (لا zone)، لذا نستثنيه هنا
            $this->assertTrue($d['allowed'], "القراءة يجب أن تُسمح في {$zone}");
            $this->assertSame('ALLOWED_READ', $d['decision_code']);
        }
    }

    public function test_unknown_action_is_denied_whitelist_only(): void
    {
        $d = $this->policy->authorize($this->userInZone('SOUTH'), 'launch_rockets');
        $this->assertFalse($d['allowed']);
        $this->assertSame('ACTION_UNKNOWN', $d['decision_code']);
    }

    public function test_every_cash_boundary_action_blocked_for_north(): void
    {
        // كان يمرّ على FINANCIAL_ACTIONS كلها. صار التصنيف مقسوماً: النقدية
        // تُحجَب، والدفترية لا. الحجب الشامل كان يعاقب المسافر بلا مقابل.
        $north = $this->userInZone('NORTH');
        foreach (ZonePolicyService::CASH_BOUNDARY_ACTIONS as $action) {
            $d = $this->policy->authorize($north, $action);
            $this->assertFalse($d['allowed'], "العملية النقدية {$action} يجب أن تُرفض للشمال");
        }
    }

    public function test_every_ledger_action_allowed_for_north(): void
    {
        $north = $this->userInZone('NORTH');
        foreach (ZonePolicyService::LEDGER_ACTIONS as $action) {
            $d = $this->policy->authorize($north, $action);
            $this->assertTrue($d['allowed'], "عملية الدفتر {$action} لا تُحجَب بالجغرافيا");
        }
    }

    public function test_the_two_action_sets_do_not_overlap(): void
    {
        // تداخلٌ هنا يعني عملية مسموحة ومحجوبة في آن — التصنيف يجب أن يقطع.
        $this->assertSame(
            [],
            array_intersect(
                ZonePolicyService::LEDGER_ACTIONS,
                ZonePolicyService::CASH_BOUNDARY_ACTIONS
            )
        );
    }

    // ---------- detectRequestZone() ----------

    public function test_detect_zone_null_when_no_request(): void
    {
        $this->assertNull($this->policy->detectRequestZone(null));
    }

    public function test_detect_zone_null_when_no_header(): void
    {
        $this->assertNull($this->policy->detectRequestZone($this->requestWithZoneHeader(null)));
    }

    public function test_detect_zone_valid_header(): void
    {
        $this->assertSame('SOUTH', $this->policy->detectRequestZone($this->requestWithZoneHeader('SOUTH')));
    }

    public function test_detect_zone_invalid_header_maps_to_other(): void
    {
        $this->assertSame('OTHER', $this->policy->detectRequestZone($this->requestWithZoneHeader('ATLANTIS')));
    }

    // ---------- buildSessionPolicy() ----------

    public function test_session_policy_south_user_can_transact(): void
    {
        $p = $this->policy->buildSessionPolicy($this->userInZone('SOUTH'), $this->requestWithZoneHeader(null));
        $this->assertTrue($p['can_transact']);
        $this->assertFalse($p['read_only_mode']);
        $this->assertNotEmpty($p['available_actions']['financial']);
        $this->assertNull($p['banner_message']);
    }

    public function test_session_policy_north_user_is_read_only(): void
    {
        $p = $this->policy->buildSessionPolicy($this->userInZone('NORTH'), $this->requestWithZoneHeader(null));
        $this->assertFalse($p['can_transact']);
        $this->assertTrue($p['read_only_mode']);
        $this->assertSame([], $p['available_actions']['financial']);
        $this->assertNotNull($p['banner_message']);
    }

    public function test_session_policy_null_user_not_read_only_mode(): void
    {
        $p = $this->policy->buildSessionPolicy(null, $this->requestWithZoneHeader(null));
        $this->assertFalse($p['can_transact']);
        $this->assertFalse($p['read_only_mode']); // read_only_mode للمصادَقين فقط
        $this->assertSame('UNKNOWN', $p['account_zone']);
    }
}
