<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\AmialNotification;
use App\Models\User;
use App\Services\MerchantVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-VERIFY-001 — اختبارات شاملة.
 *
 * تغطّي:
 *   1. تقديم طلب جديد ينشئ profile + يضع pending_review.
 *   2. تقديم بدون اسم نشاط → رفض.
 *   3. نوع ملف غير مدعوم → رفض.
 *   4. حجم > 5MB → رفض.
 *   5. التاجر verified بالفعل → لا يستطيع تقديم.
 *   6. resubmission_required → التقديم يحدّث الطلب الموجود لا ينشئ جديداً.
 *   7. Admin approve → profile.verification_status='verified' + إشعار.
 *   8. Admin reject → profile.verification_status='rejected' + إشعار.
 *   9. Admin request resubmission → status='resubmission_required'.
 *  10. Admin approve مع tier=gold → tier يُحدَّث.
 */
class MerchantVerificationTest extends TestCase
{
    use RefreshDatabase;

    private MerchantVerificationService $svc;
    private User $merchant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->svc = app(MerchantVerificationService::class);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $this->admin = User::factory()->create(['type' => 1]);
    }

    /** يصنع ملفاً وهمياً صالحاً. */
    private function fakeImage(string $name = 'doc.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600);
    }

    /** يصنع PDF وهمي. */
    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    }

    private function validDocs(): array
    {
        return [
            'id_card_front' => $this->fakeImage('front.jpg'),
            'id_card_back' => $this->fakeImage('back.jpg'),
            'commercial_register' => $this->fakePdf(),
            'store_photo' => $this->fakeImage('store.jpg'),
        ];
    }

    /** @test */
    public function submit_creates_profile_and_pending_request(): void
    {
        $req = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر أبو محمد'],
            $this->validDocs(),
        );

        $this->assertSame('pending_review', $req->status);
        $this->assertSame('متجر أبو محمد', $req->business_name);
        $this->assertNotNull($req->id_card_front_path);

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('pending_review', $profile->verification_status);
    }

    /** @test */
    public function submit_rejects_missing_business_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->submit($this->merchant, [], $this->validDocs());
    }

    /** @test */
    public function submit_rejects_invalid_mime(): void
    {
        $invalid = UploadedFile::fake()->create('bad.exe', 100, 'application/x-msdownload');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/نوع الملف/');
        $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر'],
            ['id_card_front' => $invalid],
        );
    }

    /** @test */
    public function submit_rejects_oversize_file(): void
    {
        // 6 MB > 5MB limit
        $big = UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/5MB/');
        $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر'],
            ['id_card_front' => $big],
        );
    }

    /** @test */
    public function verified_merchant_cannot_resubmit(): void
    {
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'tier' => 'standard',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('موثَّق بالفعل');
        $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر'],
            $this->validDocs(),
        );
    }

    /** @test */
    public function resubmission_updates_existing_request(): void
    {
        // تقديم أول
        $first = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر قديم'],
            $this->validDocs(),
        );

        // الإدارة تطلب إعادة رفع
        $this->svc->requestResubmission($first, $this->admin->id, 'صورة المحل غير واضحة');

        // التاجر يعيد التقديم
        $second = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر جديد'],
            $this->validDocs(),
        );

        // نفس الطلب، وليس جديد
        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending_review', $second->status);
        $this->assertSame('متجر جديد', $second->business_name);
        $this->assertNull($second->admin_note); // مُسحت
    }

    /** @test */
    public function admin_approve_marks_verified_and_notifies(): void
    {
        $req = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر العولقي'],
            $this->validDocs(),
        );

        $approved = $this->svc->approve($req, $this->admin->id);

        $this->assertSame('verified', $approved->status);
        $this->assertSame($this->admin->id, $approved->reviewed_by_admin_id);

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertSame('verified', $profile->verification_status);

        // إشعار
        $n = AmialNotification::where('user_id', $this->merchant->id)
            ->where('type', 'merchant_verified')->first();
        $this->assertNotNull($n);
    }

    /** @test */
    public function admin_approve_with_tier_updates_profile_tier(): void
    {
        $req = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر ذهبي'],
            $this->validDocs(),
        );

        $this->svc->approve($req, $this->admin->id, 'gold');

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertSame('gold', $profile->tier);
        $this->assertSame('verified', $profile->verification_status);
    }

    /** @test */
    public function admin_reject_with_reason_and_notifies(): void
    {
        $req = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر'],
            $this->validDocs(),
        );

        $rejected = $this->svc->reject($req, $this->admin->id, 'صورة الهوية غير واضحة');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('صورة الهوية غير واضحة', $rejected->admin_note);

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertSame('rejected', $profile->verification_status);

        $n = AmialNotification::where('user_id', $this->merchant->id)
            ->where('type', 'merchant_verification_rejected')->first();
        $this->assertNotNull($n);
    }

    /** @test */
    public function admin_request_resubmission_keeps_can_resubmit(): void
    {
        $req = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر'],
            $this->validDocs(),
        );

        $updated = $this->svc->requestResubmission($req, $this->admin->id, 'صورة المحل قديمة');

        $this->assertSame('resubmission_required', $updated->status);
        $this->assertSame('صورة المحل قديمة', $updated->admin_note);

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertSame('resubmission_required', $profile->verification_status);

        $n = AmialNotification::where('user_id', $this->merchant->id)
            ->where('type', 'merchant_resubmission_required')->first();
        $this->assertNotNull($n);
    }

    /** @test */
    public function approve_fails_if_status_not_pending(): void
    {
        $req = $this->svc->submit(
            $this->merchant,
            ['business_name' => 'متجر'],
            $this->validDocs(),
        );
        $this->svc->approve($req, $this->admin->id);

        // محاولة الموافقة ثانياً
        $this->expectException(\RuntimeException::class);
        $this->svc->approve($req->fresh(), $this->admin->id);
    }
}
