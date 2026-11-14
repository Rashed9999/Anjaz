<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-MUWATHAQ-REGISTRY-001 — سجلُّ كلِّ حسابات موثّق في لوحة الإدارة.
 *
 * السؤال الذي أُجيب: «كيف لإدارة المشروع أن ترى تفاصيل عملائها؟» — الحالةُ والمستوى والمحافظة والرصيد والمرجع هنا،
 * والوثائقُ تُفتح في موثّق (لا تُنسخ إلى أميال باي: فصلُ الملكية). فالمحروس: السجلُّ يعرض كلَّ الأنواع والحالات
 * بفلاترَ من الخادم، **ولا يحمل ملفَّ وثيقةٍ ولا رابطَ ملفّ**، والرابطُ إلى موثّق مبنيٌّ من رقم الطلب.
 */
class MuwathaqRegistryPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009201']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    private function account(string $kind, string $status, string $phone, int $tier = 2, string $balance = '1000', array $userOver = []): MuwathaqAccountOpeningApplication
    {
        $type = match ($kind) { 'agent' => AGENT_TYPE, 'merchant' => MERCHANT_TYPE, default => CUSTOMER_TYPE };
        $u = User::factory()->create($userOver + ['type' => $type, 'phone' => $phone, 'f_name' => 'اسم' . substr($phone, -4), 'l_name' => 'تجريبي', 'kyc_tier' => $tier]);
        EMoney::updateOrCreate(['user_id' => $u->id], ['current_balance' => $balance]);

        return MuwathaqAccountOpeningApplication::query()->create([
            'application_ulid' => strtoupper(substr(str_replace('-', '', (string) Str::uuid()) . 'ABCDEFGHJK', 0, 26)),
            'user_id' => $u->id, 'origin' => 'muwathaq', 'subject_kind' => $kind,
            'verification_path' => $kind === 'customer' ? 'women_progressive' : 'merchant_sector', 'requested_tier' => $kind === 'customer' ? $tier : null,
            'status' => $status, 'submitted_at' => now(), 'decided_at' => now(), 'muwathaq_decided_at' => now(),
            'decision_reference' => 'MWT-' . strtoupper(Str::random(20)), 'decision_context' => [],
        ]);
    }

    private function list(User $admin, string $query = ''): array
    {
        return $this->actingAs($admin, 'user')->getJson('/admin/amial/hub/muwathaq-accounts.json' . $query)->assertSuccessful()->json();
    }

    /** @test */
    public function it_lists_every_kind_and_status_with_state_balance_and_reference(): void
    {
        config(['muwathaq.base_url' => 'https://muwathaq.example.test']);
        $admin = $this->admin();
        $c = $this->account('customer', 'approved', '967781000101', 2, '120000');
        $this->account('merchant', 'approved', '967781000102');
        $this->account('agent', 'rejected', '967781000103');

        $json = $this->list($admin);

        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame(3, count($json['data']));
        $row = collect($json['data'])->firstWhere('application_ulid', $c->application_ulid);
        $this->assertSame('customer', $row['subject_kind']);
        $this->assertSame(2, $row['requested_tier']);
        $this->assertSame($c->decision_reference, $row['decision_reference']);
        $this->assertSame(0, bccomp($row['user']['balance'], '120000', 4), 'الرصيد لا يصل السجلَّ');
        $this->assertSame('967781000101', $row['user']['phone']);
        $this->assertStringContainsString('/admin/amial/hub/account/' . $c->user_id, $row['user']['account_url']);
        // الوثائق تُفتح في موثّق، من رقم الطلب.
        $this->assertSame('https://muwathaq.example.test/cases/by-application/' . $c->application_ulid, $row['muwathaq_url']);
    }

    /** @test */
    public function filters_run_on_the_server(): void
    {
        $admin = $this->admin();
        $this->account('customer', 'approved', '967781000111', 2);
        $this->account('customer', 'under_review', '967781000112', 1);
        $this->account('merchant', 'approved', '967781000113');
        $this->account('agent', 'rejected', '967781000114');

        $this->assertSame(1, $this->list($admin, '?kind=merchant')['meta']['total']);
        $this->assertSame(1, $this->list($admin, '?status=rejected')['meta']['total']);
        $this->assertSame(1, $this->list($admin, '?kind=customer&tier=1')['meta']['total']);
        $this->assertSame(1, $this->list($admin, '?q=0112')['meta']['total'], 'البحث بذيل الهاتف');
        $this->assertSame(4, $this->list($admin, '?kind=bogus&status=bogus&tier=9')['meta']['total'], 'قيمٌ مجهولة تُتجاهل ولا تُحقن');
    }

    /** @test */
    public function the_registry_carries_no_document_content_or_file_links(): void
    {
        config(['muwathaq.base_url' => 'https://muwathaq.example.test']);
        $admin = $this->admin();
        $this->account('customer', 'approved', '967781000121');

        $body = json_encode($this->list($admin), JSON_UNESCAPED_UNICODE);

        foreach (['document', 'encrypted_path', 'content_sha256', 'file_url', 'storage/'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "السجلُّ يحمل أثرَ وثيقةٍ ({$needle}) — وثائق موثّق لا تُنسخ إلى أميال باي");
        }
    }

    /** @test */
    public function a_non_admin_cannot_open_the_registry(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'role' => 'customer', 'phone' => '967781000131']);

        $res = $this->actingAs($customer, 'user')->getJson('/admin/amial/hub/muwathaq-accounts.json');

        $this->assertNotSame(200, $res->getStatusCode(), 'عميلٌ عاديٌّ فتح سجلَّ حسابات موثّق');
    }

    /** @test */
    public function the_page_is_reachable_from_the_sidebar(): void
    {
        $sidebar = file_get_contents(resource_path('views/admin-views/amial/partials/_sidebar.blade.php'));
        $this->assertStringContainsString("admin.amial.hub.muwathaq-accounts.index", $sidebar, 'صفحةٌ مبنيةٌ لا يُوصَل إليها ليست مبنيّة');

        $admin = $this->admin();
        $this->actingAs($admin, 'user')->get('/admin/amial/hub/muwathaq-accounts')->assertSuccessful()->assertSee('سجلّ كل الحسابات');
    }
}
