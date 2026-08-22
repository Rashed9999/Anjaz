<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\ZoneAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-ZONE-PROVENANCE-001 — **نطاقٌ يخالف الوثيقة يُقال إنّه يخالفها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** `zone_assignment_logs` تسجّل `method` بثلاث قيم ولا تسجّل
 * شيئاً عن التعارض. و`assignByAdmin` تحفظ `old_zone` — **وليس ذاك ما
 * تطلبه الوثيقة**: «عند Manual Override، لا تمسح حقيقة KYC الأصلية».
 *
 * والفرقُ جوهريّ: `old_zone` ما كان مكتوباً قبل لحظة، وقد يكون `UNKNOWN`
 * من التسجيل أو أثرَ تجاوزٍ سابق. أمّا **ما تقوله الوثيقة** فالنطاقُ
 * المشتقُّ من محافظة السكن الموثَّقة.
 *
 * فمن يقرأ حساباً بعد شهرٍ يرى `NORTH` **ولا يعرف** أهو من هويّةٍ موثّقة
 * أم من قرارِ موظّفٍ ضدَّ هويّةٍ تقول `SOUTH`. **والنطاقُ يحكم حركةَ
 * المال** — فتعارضٌ مكتومٌ بابُ تحايلٍ لا خطأُ تصنيف.
 */
class ZoneOverrideProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): ZoneAssignmentService
    {
        return app(ZoneAssignmentService::class);
    }

    private function southerner(): User
    {
        // محافظةُ سكنٍ موثّقةٌ في الجنوب — فالوثيقةُ تقول `SOUTH`.
        return User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'residence_governorate' => 'عدن',
        ]);
    }

    private function lastLog(User $u): object
    {
        return DB::table('zone_assignment_logs')
            ->where('user_id', $u->id)->latest('id')->first();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① التعارضُ يُقاس ويُكتب
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_admin_zone_against_the_document_is_marked_an_override(): void
    {
        $u = $this->southerner();

        $this->svc()->assignByAdmin($u, 'NORTH', adminId: 1,
            reason: 'قرارُ إدارةٍ بعد مراجعةٍ يدويّة');

        $log = $this->lastLog($u);

        $this->assertTrue((bool) $log->is_override,
            'أُسند نطاقٌ يخالف الوثيقة ولم يُوسَم تجاوزاً — '
            . 'فمن يقرأ الحسابَ لاحقاً لا يعرف أنّه ضدَّ الهويّة');

        $this->assertSame('kyc_verification', $log->overrides_source);

        // **وحقيقةُ الوثيقة محفوظةٌ ولم تُمحَ** — وهو نصُّ المطلوب.
        $this->assertSame('SOUTH', $log->kyc_zone);
        $this->assertSame('NORTH', $log->assigned_zone);

        $signals = json_decode((string) $log->signals, true);
        $this->assertSame('عدن', $signals['documented_city'] ?? null,
            'حُفظ الرمزُ ولم تُحفظ المدينةُ — والرمزُ وحدَه لا يقول من أين اشتُقّ');
        $this->assertNotEmpty($signals['reason'] ?? null);
    }

    /** @test */
    public function an_admin_zone_that_agrees_with_the_document_is_not_an_override(): void
    {
        // **وهذا شرطُ صحّة الوسم كلِّه.** لو وُسم كلُّ قرارِ موظّفٍ تجاوزاً
        // لصار الوسمُ كلمةً أخرى لـ`admin_decision` — ولا يُميّز شيئاً.
        $u = $this->southerner();

        $this->svc()->assignByAdmin($u, 'SOUTH', adminId: 1,
            reason: 'تأكيدُ ما تقوله الوثيقةُ بعد مراجعة');

        $log = $this->lastLog($u);

        $this->assertFalse((bool) $log->is_override);
        $this->assertNull($log->overrides_source);
        $this->assertSame('SOUTH', $log->kyc_zone);
    }

    /** @test */
    public function a_user_with_no_document_cannot_be_contradicted(): void
    {
        // **ومخالفةُ «لا أعرف» ليست مخالفة.** حسابٌ بلا محافظةِ سكنٍ
        // موثّقةٍ لا وثيقةَ له تُخالَف — ووسمُه تجاوزاً يُغرق اللوحةَ
        // بإنذاراتٍ لا معنى لها، فيُعتاد تجاهلُها.
        $u = User::factory()->create([
            'type' => 2, 'zone_code' => 'UNKNOWN', 'residence_governorate' => null,
        ]);

        $this->svc()->assignByAdmin($u, 'NORTH', adminId: 1, reason: 'إسنادٌ مبدئيّ');

        $log = $this->lastLog($u);

        $this->assertFalse((bool) $log->is_override);
        $this->assertNull($log->kyc_zone);
    }

    /** @test */
    public function an_unreadable_city_is_not_treated_as_a_documented_opinion(): void
    {
        // مدينةٌ لا تُقرأ تُخرج `UNKNOWN` من `cityToZone` — **وهي عجزٌ عن
        // قراءة الوثيقة لا رأيٌ لها**. فلا يُوسَم الإسنادُ مخالفاً لها.
        $u = User::factory()->create([
            'type' => 2, 'zone_code' => 'UNKNOWN',
            'residence_governorate' => 'زنجبار٩',
        ]);

        $this->assertNull($this->svc()->zoneFromDocuments($u));

        $this->svc()->assignByAdmin($u, 'NORTH', adminId: 1, reason: 'إسنادٌ يدويّ');

        $this->assertFalse((bool) $this->lastLog($u)->is_override);
    }

    /** @test */
    public function assigning_from_the_document_records_the_document_as_its_own_source(): void
    {
        $u = $this->southerner();

        $this->svc()->assignFromKyc($u, 'عدن', adminId: 2);

        $log = $this->lastLog($u);

        $this->assertFalse((bool) $log->is_override,
            'إسنادٌ من الوثيقة وُسم مخالفاً لها');
        $this->assertSame($log->assigned_zone, $log->kyc_zone);
        $this->assertSame('high', $log->confidence);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الثقةُ قاعدةٌ مكتوبةٌ لا رقمٌ مخترَع
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function confidence_follows_the_written_rule_per_source(): void
    {
        $u = $this->southerner();

        $this->svc()->assignByAdmin($u, 'NORTH', adminId: 1, reason: 'قرارٌ إداريٌّ مسبَّب');
        $this->assertSame('medium', $this->lastLog($u)->confidence);

        $this->svc()->assignFromKyc($u, 'عدن', adminId: 1);
        $this->assertSame('high', $this->lastLog($u)->confidence);

        $fresh = User::factory()->create(['type' => 2]);
        $this->svc()->assignOnRegistration($fresh);
        $this->assertSame('low', $this->lastLog($fresh)->confidence);
    }

    /** @test */
    public function confidence_is_never_a_made_up_number(): void
    {
        // **رقمٌ مئويٌّ لا حسبةَ خلفه يُقرأ قياساً وليس قياساً**، ومن يراه
        // يبني عليه قراراً. فالقيمُ ثلاثٌ معدودةٌ لكلٍّ قاعدةٌ مكتوبة.
        foreach (ZoneAssignmentService::CONFIDENCE as $method => $level) {
            $this->assertContains($level, ['high', 'medium', 'low'],
                "درجةُ ثقةٍ خارج التعداد لـ{$method}: {$level}");
        }

        $this->assertSame(
            ['kyc_verification', 'admin_decision', 'registration'],
            array_keys(ZoneAssignmentService::CONFIDENCE),
            'مصدرُ إسنادٍ بلا درجةِ ثقةٍ معلنة — فيُعرَض فارغاً بلا سبب');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ ويُرى في اللوحة — لا يُخزَّن فقط
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_board_feed_says_the_assignment_contradicts_the_document(): void
    {
        // القاعدة الثانية عشرة: تعارضٌ يُسجَّل ولا يُرى ليس مبنيّاً.
        $u = $this->southerner();
        $this->svc()->assignByAdmin($u, 'NORTH', adminId: 1, reason: 'قرارُ إدارة');

        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);

        $json = $this->actingAs($admin->refresh(), 'user')
            ->getJson(route('admin.amial.hub.zones.events', ['type' => 'assignments']))
            ->assertOk()->json('data');

        $row = collect($json)->firstWhere('user_id', $u->id);

        $this->assertNotNull($row, 'الإسنادُ لا يظهر في تغذية اللوحة إطلاقاً');
        $this->assertTrue($row['is_override']);
        $this->assertSame('SOUTH', $row['kyc_zone']);
        $this->assertStringContainsString('يخالف الوثيقة', (string) $row['note']);
        $this->assertSame('قرارُ موظّف', $row['method_ar']);
    }

    /** @test */
    public function rows_older_than_the_columns_report_unmeasured_not_confident(): void
    {
        // **القاعدة السابعة: «غير معروف» ليس صفراً.** صفٌّ كُتب قبل هذه
        // الأعمدة لم يُقَس تعارضُه — فدرجتُه `null` تُقرأ «لم يُقَس»،
        // ولا تُملأ بالتخمين فتُقرأ «فُحص فلم يُخالف».
        $u = $this->southerner();

        DB::table('zone_assignment_logs')->insert([
            'user_id' => $u->id, 'assigned_zone' => 'NORTH',
            'method' => 'admin_decision', 'signals' => '{}',
            'created_at' => now(),
        ]);

        $log = $this->lastLog($u);

        $this->assertNull($log->confidence,
            'مُلئت درجةُ ثقةٍ لصفٍّ لم يُقَس — فصار المجهولُ يُقرأ مقيساً');
        $this->assertNull($log->kyc_zone);
    }
}
