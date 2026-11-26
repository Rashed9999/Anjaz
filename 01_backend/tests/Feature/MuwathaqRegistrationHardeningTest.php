<?php

namespace Tests\Feature;

use App\Jobs\SendMuwathaqWelcomeEmailJob;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use App\Services\Muwathaq\MuwathaqWelcomeEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** التسجيل عبر موثّق: الاسم الرباعي، وضع إثبات الهاتف الجاهز للتفعيل، ورسالة الدخول الأولى بعد الاعتماد. */
class MuwathaqRegistrationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $applicant = [], string $kind = 'customer'): array
    {
        $ulid = (string) Str::ulid();

        return [
            'event_version' => 1, 'event_type' => 'account.approved', 'application_ulid' => $ulid,
            'decision_reference' => 'MWT-'.strtoupper(substr(md5($ulid), 0, 12)),
            'subject_kind' => $kind, 'verification_path' => 'standard_customer', 'business_type' => null, 'requested_tier' => null,
            'applicant' => $applicant + ['f_name' => 'محمد', 'l_name' => 'القحطاني', 'phone' => '+9677'.random_int(10000000, 99999999),
                'dial_country_code' => '+967', 'email' => 'welcome-'.strtolower(Str::random(8)).'@example.test', 'gender' => 'male'],
            'subject' => [],
            'decision_context' => ['policy_version' => 'standard-customer-v1', 'verified_phone' => true, 'verified_residence_governorate' => 'YE-AD', 'verified_identity' => true],
        ];
    }

    private function provision(array $event): User
    {
        $r = app(MuwathaqProvisioningService::class)->provisionApproved($event);

        return User::query()->findOrFail((int) $r['user_id']);
    }

    /** @test */
    public function a_four_part_name_is_stored_as_parts_and_marked_declared_not_verified(): void
    {
        Queue::fake();
        $u = $this->provision($this->event(['father_name' => 'أحمد', 'grandfather_name' => 'علي', 'family_name' => 'القحطاني']));
        $this->assertSame('محمد أحمد علي القحطاني', $u->declared_legal_name);
        $this->assertSame(['أحمد', 'علي', 'القحطاني'], [$u->father_name, $u->grandfather_name, $u->family_name]);
        $this->assertSame('declared', $u->legal_name_status);
        $this->assertNull($u->legal_name_verified_at);
        $this->assertSame(1, DB::table('legal_name_events')->where('user_id', $u->id)->where('event_type', 'DECLARED_AT_REGISTRATION')->where('source', 'muwathaq')->count());
    }

    /** @test */
    public function an_older_two_part_event_still_works_and_is_declared(): void
    {
        Queue::fake();
        $u = $this->provision($this->event());
        $this->assertSame('محمد القحطاني', $u->declared_legal_name);
        $this->assertSame('declared', $u->legal_name_status);
    }

    /** @test */
    public function phone_ownership_follows_the_mode_and_the_audit_says_which(): void
    {
        Queue::fake();
        config(['muwathaq.phone_verification' => 'reviewer']);
        $a = $this->provision($this->event());
        $this->assertSame(1, (int) $a->is_phone_verified, 'الوضع الحالي: علامة المراجع تُفعّل الهاتف');

        config(['muwathaq.phone_verification' => 'otp']);
        $b = $this->provision($this->event());
        $this->assertSame(0, (int) $b->is_phone_verified, 'وضع otp: لا تفعيل من علامة المراجع');
        $ctx = json_decode((string) DB::table('audit_decisions')->where('action', 'MUWATHAQ_ACCOUNT_PROVISIONED')->where('subject_id', (string) $b->id)->value('context'), true);
        $this->assertSame('otp', $ctx['phone_verification']);
        $this->assertFalse($ctx['phone_verified']);
    }

    /** @test */
    public function approval_queues_exactly_one_welcome_email_and_a_replay_does_not_queue_another(): void
    {
        Queue::fake();
        $event = $this->event();
        $u = $this->provision($event);
        Queue::assertPushed(SendMuwathaqWelcomeEmailJob::class, fn ($j) => $j->userId === $u->id && $j->kind === 'customer' && $j->applicationUlid === $event['application_ulid']);
        $this->assertSame(['duplicate' => true], ['duplicate' => app(MuwathaqProvisioningService::class)->provisionApproved($event)['duplicate']]);
        Queue::assertPushed(SendMuwathaqWelcomeEmailJob::class, 1);
    }

    /** @test */
    public function the_approval_email_carries_a_three_day_activation_code_sent_through_resend(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_1'], 200)]);
        Queue::fake();
        $event = $this->event();
        $u = $this->provision($event);
        $challengeId = app(MuwathaqWelcomeEmailService::class)->send($u, 'customer', $event['application_ulid']);
        Http::assertSent(function (ClientRequest $r) use ($u, $challengeId): bool {
            $d = $r->data();
            $this->assertSame([$u->email], $d['to']);
            $this->assertSame('email-otp/'.$challengeId, $r->header('Idempotency-Key')[0]);
            $this->assertStringContainsString('تفعيل الحساب', $d['text']);
            $this->assertMatchesRegularExpression('/(?<!\d)\d{6}(?!\d)/u', $d['text'], 'الرمز في نص البريد');
            $this->assertStringContainsString('3 أيام', $d['text'], 'الصلاحية أيام لا دقائق');
            $this->assertStringNotContainsString('نسيت كلمة المرور', $d['text'], 'لا طريق التفافيّ: الرمز هو باب الدخول الأول');

            return true;
        });
        $row = DB::table('otp_challenges')->where('challenge_id', $challengeId)->first();
        $this->assertSame('account_activation', $row->purpose);
        $this->assertSame($u->id, (int) $row->user_id);
        $this->assertEqualsWithDelta(4320 * 60, strtotime((string) $row->expires_at) - strtotime((string) $row->created_at), 5);
    }

    /** @test */
    public function the_validity_label_reads_in_minutes_hours_or_days(): void
    {
        $this->assertSame('5 دقائق', \App\Services\Otp\EmailOtpService::ttlLabel(5));
        $this->assertSame('3 أيام', \App\Services\Otp\EmailOtpService::ttlLabel(4320));
        $this->assertSame('يوم واحد', \App\Services\Otp\EmailOtpService::ttlLabel(1440));
    }

    /** @test */
    public function a_provider_failure_is_retried_but_a_missing_key_is_skipped_and_audited(): void
    {
        Queue::fake();
        $u = $this->provision($this->event());
        config(['amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response('down', 503)]);
        $threw = false;
        try {
            (new SendMuwathaqWelcomeEmailJob($u->id, 'customer', (string) Str::ulid()))->handle(app(MuwathaqWelcomeEmailService::class));
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('OTP_DELIVERY_FAILED', $e->getMessage());
            $this->assertSame('RESEND_HTTP_503', $e->getPrevious()?->getMessage());
        }
        $this->assertTrue($threw, 'فشل المزوّد يرمي ليُعاد');

        config(['amial_otp.resend.api_key' => '']);
        (new SendMuwathaqWelcomeEmailJob($u->id, 'customer', (string) Str::ulid()))->handle(app(MuwathaqWelcomeEmailService::class));
        $this->assertSame(1, DB::table('audit_decisions')->where('action', 'MUWATHAQ_WELCOME_EMAIL_FAILED')->where('subject_id', (string) $u->id)->count());
    }
}
