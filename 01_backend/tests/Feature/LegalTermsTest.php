<?php

namespace Tests\Feature;

use App\Models\LegalTerm;
use App\Models\User;
use App\Models\UserLegalAcceptance;
use App\Services\LegalTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LEGAL-001
 */
class LegalTermsTest extends TestCase
{
    use RefreshDatabase;

    private LegalTermsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(LegalTermsService::class);
    }

    /** @test */
    public function publishing_new_version_supersedes_old_one(): void
    {
        $admin = User::factory()->create(['type' => 0]);

        $v1 = $this->svc->publishNewVersion(
            version: '1.0',
            locale: 'ar',
            title: 'سياسة الاستخدام v1',
            content: str_repeat('محتوى السياسة. ', 20),
            changelog: 'الإصدار الأول',
            createdBy: $admin->id,
        );

        $this->assertTrue($v1->is_current);

        $v2 = $this->svc->publishNewVersion(
            version: '2.0',
            locale: 'ar',
            title: 'سياسة الاستخدام v2',
            content: str_repeat('محتوى محدث. ', 20),
            changelog: 'تعديل سياسة الرسوم',
            createdBy: $admin->id,
        );

        $v1->refresh();
        $this->assertFalse($v1->is_current);
        $this->assertNotNull($v1->superseded_at);
        $this->assertTrue($v2->is_current);
    }

    /** @test */
    public function user_needs_acceptance_when_never_accepted(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);

        $this->assertTrue($this->svc->needsAcceptance($user, 'ar'));
    }

    /** @test */
    public function user_does_not_need_acceptance_after_accepting_current(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $term = $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);

        $this->svc->accept($user, $term);

        $this->assertFalse($this->svc->needsAcceptance($user, 'ar'));
    }

    /** @test */
    public function accepting_old_version_does_not_satisfy_new_version(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $v1 = $this->svc->publishNewVersion('1.0', 'ar', 'T1', str_repeat('x', 60), null, $admin->id);
        $this->svc->accept($user, $v1);

        // قبل v1، الآن ينشر v2
        $this->svc->publishNewVersion('2.0', 'ar', 'T2', str_repeat('y', 60), 'change', $admin->id);

        // المستخدم يجب أن يحتاج قبول v2
        $this->assertTrue($this->svc->needsAcceptance($user, 'ar'));
    }

    /** @test */
    public function accepting_twice_is_idempotent(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $term = $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);

        $first = $this->svc->accept($user, $term, '1.2.3.4', 'TestAgent', 'dev-1');
        $second = $this->svc->accept($user, $term, '5.6.7.8', 'OtherAgent', 'dev-1');

        $this->assertNotNull($first);
        $this->assertNull($second); // returns null = already accepted

        // و في DB يوجد سجل واحد فقط
        $this->assertSame(1, UserLegalAcceptance::where('user_id', $user->id)->count());
    }

    /** @test */
    public function acceptance_records_legal_metadata(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $term = $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);

        $acceptance = $this->svc->accept(
            $user,
            $term,
            ip: '192.168.1.42',
            userAgent: 'AmialPay/0.7.0 Android',
            deviceId: 'device-uuid-123',
        );

        $this->assertSame('192.168.1.42', $acceptance->ip_address);
        $this->assertSame('AmialPay/0.7.0 Android', $acceptance->user_agent);
        $this->assertSame('device-uuid-123', $acceptance->device_id);
        $this->assertSame('1.0', $acceptance->accepted_version);
    }

    /** @test */
    public function acceptance_is_append_only_cannot_be_updated(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $term = $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);
        $acceptance = $this->svc->accept($user, $term);

        $this->expectException(\RuntimeException::class);
        $acceptance->update(['ip_address' => '0.0.0.0']);
    }

    /** @test */
    public function acceptance_is_append_only_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2]);

        $term = $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);
        $acceptance = $this->svc->accept($user, $term);

        $this->expectException(\RuntimeException::class);
        $acceptance->delete();
    }

    /** @test */
    public function invalid_version_format_rejected(): void
    {
        $admin = User::factory()->create(['type' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->publishNewVersion('latest', 'ar', 'T', 'content', null, $admin->id);
    }

    /** @test */
    public function locale_fallback_to_arabic_works(): void
    {
        $admin = User::factory()->create(['type' => 0]);

        // ننشر فقط arabic، لا english
        $this->svc->publishNewVersion('1.0', 'ar', 'T', str_repeat('x', 60), null, $admin->id);

        // request locale=en → يجب أن يقع back على ar
        $current = LegalTerm::currentFor('en');
        $this->assertNotNull($current);
        $this->assertSame('ar', $current->locale);
    }
}
