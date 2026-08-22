<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kyc\ProfileChangeRequestService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-PROFILE-CHANGE-007 — **الطرفُ الناقص، مُثبتاً من طرفه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الحلقةُ التي بلا هذه النقاط تبقى مقطوعة:** الدعمُ يفتح الطلبَ من
 * اللوحة، ويبقى `PENDING_CUSTOMER` **إلى الأبد** — لأنّ العميلَ لا يراه
 * ولا مكانَ يملأ فيه.
 *
 * وهو نفسُ عطل `AMIAL-KYC-DOCS-001`: زرٌّ يضع علامةً على المستخدم ولا
 * مكان يرفع إليه. **الزرُّ يعمل والعميلُ ينتظر ما لن يأتي.**
 */
class CustomerProfileChangeApiTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill(['is_kyc_verified' => 1, 'job_title' => 'صيّاد'])->save();

        return $u->refresh();
    }

    private function staff(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    private function as(User $u): self
    {
        // **والحارسُ `auth:api` بـPassport لا Sanctum** — قِيس من
        // `routes/api/amial.php` ومن اختبارٍ قائمٍ يعمل، لا من عادة.
        Passport::actingAs($u);

        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① العميلُ يرى ما يُطلب منه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_request_opened_by_support_is_visible_to_the_customer(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وبلا هذا يبقى الطلبُ `PENDING_CUSTOMER` إلى الأبد.**
        // ══════════════════════════════════════════════════════════════
        $customer = $this->customer();

        app(ProfileChangeRequestService::class)->open(
            $customer, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');

        $this->as($customer)
            ->getJson('/api/v1/amial/me/profile-changes')
            ->assertOk()
            ->assertJsonPath('data.requests.0.field', 'job_title')
            ->assertJsonPath('data.requests.0.status', 'PENDING_CUSTOMER')
            // **و«قبل» تُعرَض للعميل** — فيلتقط خطأَه قبل أن يصل المراجع.
            ->assertJsonPath('data.requests.0.old_value', 'صيّاد');
    }

    /** @test */
    public function a_customer_never_sees_another_customers_requests(): void
    {
        // **والهويّةُ تحدّد النطاق لا القائمةُ المنسدلة** — القاعدة الثامنة.
        $mine = $this->customer();
        $other = $this->customer();

        app(ProfileChangeRequestService::class)->open(
            $other, 'job_title', $this->staff()->id, 'admin', 'طلبُ غيري');

        $this->as($mine)
            ->getJson('/api/v1/amial/me/profile-changes')
            ->assertOk()
            ->assertJsonCount(0, 'data.requests');
    }

    /** @test */
    public function the_identity_state_travels_with_the_requests(): void
    {
        // **وسؤالان متلازمان في شاشةٍ واحدة**: «ماذا يُطلب منّي؟» و«متى
        // تنتهي هويّتي؟». وفصلُهما يجعل الثانيَ لا يُرى.
        $customer = $this->customer();
        $customer->forceFill([
            'identification_expiry_date' => now()->addDays(20)->toDateString(),
        ])->save();

        $this->as($customer->refresh())
            ->getJson('/api/v1/amial/me/profile-changes')
            ->assertOk()
            ->assertJsonPath('data.identity.state', 'DUE');
    }

    /** @test */
    public function a_missing_expiry_is_reported_unknown_not_valid(): void
    {
        // **و«غير معروف» ليس «سارية»** — ولا يُعرَض أخضرَ يطمئن على ما
        // لا نعرفه. (القاعدة السابعة.)
        $this->as($this->customer())
            ->getJson('/api/v1/amial/me/profile-changes')
            ->assertOk()
            ->assertJsonPath('data.identity.state', 'UNKNOWN');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والحقولُ تُسأل من الخادم
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_field_list_comes_from_the_server_with_its_flags(): void
    {
        // **وقائمةٌ مكتوبةٌ في التطبيق تشيخ**: يُضاف حقلٌ فلا يظهر أبداً،
        // ويُحذف آخرُ فيبقى معروضاً ويُرفض عند الإرسال.
        $r = $this->as($this->customer())
            ->getJson('/api/v1/amial/me/profile-changes/fields')
            ->assertOk();

        $fields = collect($r->json('data'));

        $this->assertSame(
            count(ProfileChangeRequestService::CHANGEABLE), $fields->count(),
            'قائمةُ الحقول المعروضةُ تفترق عن قائمة الخدمة');

        $idNumber = $fields->firstWhere('field', 'identification_number');

        $this->assertTrue($idNumber['needs_document'],
            'رقمُ الهويّة يُعرَض بلا وسمِ «يلزمه وثيقة» — فيفاجأ العميلُ بالرفض');
        $this->assertTrue($idNumber['resets_verification']);
    }

    /** @test */
    public function a_field_without_a_translation_is_marked_not_invented(): void
    {
        // **وترجمةٌ مخترَعةٌ أسوأ من رمزٍ إنجليزيّ**: الإنجليزيُّ يُوقف
        // القارئَ ليسأل، والمخترَعةُ تُمرّره واثقاً من معنىً لم يقصده أحد.
        $fields = collect($this->as($this->customer())
            ->getJson('/api/v1/amial/me/profile-changes/fields')->json('data'));

        foreach ($fields as $f) {
            $this->assertArrayHasKey('has_label', $f,
                'لا وسمَ يفرّق المترجَمَ من الخام — فتُعرَض الرموزُ كأنّها أسماء');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ ويملأ — وهي الحلقةُ التي كانت مفقودة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_customer_fills_the_value_and_it_moves_to_review(): void
    {
        $customer = $this->customer();

        $id = app(ProfileChangeRequestService::class)->open(
            $customer, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');

        $this->as($customer)
            ->postJson("/api/v1/amial/me/profile-changes/{$id}/submit",
                ['new_value' => 'مهندس'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING_REVIEW');

        $this->assertDatabaseHas('profile_change_requests', [
            'id' => $id, 'new_value' => 'مهندس', 'status' => 'PENDING_REVIEW',
        ]);
    }

    /** @test */
    public function one_customer_cannot_fill_anothers_request(): void
    {
        // **وإلّا فالميزةُ بابُ استيلاءٍ على ملفّ غيرك.**
        $owner = $this->customer();
        $stranger = $this->customer();

        $id = app(ProfileChangeRequestService::class)->open(
            $owner, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');

        $this->as($stranger)
            ->postJson("/api/v1/amial/me/profile-changes/{$id}/submit",
                ['new_value' => 'مهندس'])
            ->assertStatus(422);

        $this->assertDatabaseHas('profile_change_requests',
            ['id' => $id, 'new_value' => null]);
    }

    /** @test */
    public function an_identity_field_is_refused_without_a_document_and_says_so(): void
    {
        // **ورسالةٌ لا تدلّ على سببها تُنتج مكالمةَ دعمٍ لا إجراء.**
        $customer = $this->customer();

        $id = app(ProfileChangeRequestService::class)->open(
            $customer, 'identification_number', $this->staff()->id, 'admin', 'هويّةٌ جديدة');

        $r = $this->as($customer)
            ->postJson("/api/v1/amial/me/profile-changes/{$id}/submit",
                ['new_value' => '15589480'])
            ->assertStatus(422);

        $this->assertStringContainsString('وثيقةٍ داعمة', (string) $r->json('message'));
    }

    /** @test */
    public function the_customer_can_open_a_request_alone_without_calling_support(): void
    {
        // **وميزةٌ لا تُبلَغ إلّا عبر موظّفٍ تُستعمَل عُشرَ ما تُستعمل.**
        $customer = $this->customer();

        $this->as($customer)
            ->postJson('/api/v1/amial/me/profile-changes',
                ['field' => 'residence_area', 'reason' => 'انتقلتُ إلى حيٍّ جديد'])
            ->assertStatus(201);

        $this->assertDatabaseHas('profile_change_requests', [
            'user_id' => $customer->id, 'field' => 'residence_area',
            'opened_by_type' => 'customer',
        ]);
    }

    /** @test */
    public function a_field_outside_the_whitelist_is_refused_from_the_app_too(): void
    {
        // **والحمايةُ في الخدمة لا في الشاشة** — فمدخلٌ ثانٍ يرثها ولا
        // يُعيد كتابتها. (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من
        // مدخليها.)
        $this->as($this->customer())
            ->postJson('/api/v1/amial/me/profile-changes',
                ['field' => 'is_kyc_verified', 'reason' => 'محاولةٌ من التطبيق'])
            ->assertStatus(422);
    }

    /** @test */
    public function the_customer_can_cancel_but_not_someone_elses(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer();

        $id = app(ProfileChangeRequestService::class)->open(
            $owner, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');

        $this->as($stranger)
            ->postJson("/api/v1/amial/me/profile-changes/{$id}/cancel")
            ->assertStatus(422);

        $this->as($owner)
            ->postJson("/api/v1/amial/me/profile-changes/{$id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('profile_change_requests',
            ['id' => $id, 'status' => 'CANCELLED']);
    }

    /** @test */
    public function an_anonymous_caller_gets_nothing(): void
    {
        $this->getJson('/api/v1/amial/me/profile-changes')->assertStatus(401);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ وللشاشة رابطٌ يُوصل إليها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_app_screen_exists_and_is_linked(): void
    {
        // **وشاشةٌ لا يُوصل إليها ليست مبنيّة** — القاعدة الثانية عشرة.
        $screen = base_path(
            '../02_flutter_app/lib/features/kyc_verification/screens/my_profile_changes_screen.dart');

        if (! is_file($screen)) {
            $this->markTestSkipped('التطبيقُ غيرُ موجودٍ في هذه البيئة');
        }

        $src = file_get_contents($screen);

        // النقاطُ الأربعُ تُنادى فعلاً — لا واحدةٌ مبنيّةٌ بلا زرّ.
        foreach (['/profile-changes', '/fields', '/submit', '/cancel'] as $path) {
            $this->assertStringContainsString($path, $src,
                "نقطةُ «{$path}» بلا مُنادٍ في الشاشة");
        }

        $services = base_path('../02_flutter_app/lib/features/me/screens/my_services_screen.dart');

        $this->assertStringContainsString('MyProfileChangesScreen',
            file_get_contents($services),
            'الشاشةُ مبنيّةٌ ولا بطاقةَ تقود إليها في «خدماتي»');
    }
}
