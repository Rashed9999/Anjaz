<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\IdentityExpiryService;
use App\Services\Kyc\ProfileChangeRequestService;
use App\Services\PlatformRoleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-KYC-EXPIRY-003 · AMIAL-PROFILE-CHANGE-004
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب، بنصّه:** «لم أرَ أيّ زرّ تعديل ملفّ معلومات العميل ولا يوجد
 * تواريخ انتهاء هويّته أو تنبيهاً... بالتأكيد لا يجيب على الدعم الحصول
 * على زرّ التعديل، ربّما وظيفة أخرى».
 *
 * وقِيس قبل بناء سطر، فكان الحدسُ صائباً والعطلُ في غير موضع السؤال:
 *
 *   · مسارات تعديل بيانات العميل من اللوحة  →  **صفر** (والحدسُ: هذا صواب)
 *   · `identification_expiry_date`           →  **صفرُ قُرّاءٍ** في المشروع
 *   · `document_expires_at`                  →  قارئٌ واحد: عند اعتمادٍ جديد
 *   · أوامرُ مجدولةٌ تعيد فحصَ الموثَّقين     →  **صفر**
 *
 * **فالانتهاءُ محروسٌ عند البوّابة ومهجورٌ بعدها**: من وُثِّق مرّةً وُثِّق
 * للأبد وإن انتهت وثيقتُه قبل سنتين. وحقلٌ يُطلَب ولا يُقرأ أسوأ من
 * غيابه — يُوهم بأنّ الأمرَ مضبوطٌ فلا يبحث أحد.
 */
class IdentityExpiryAndChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function verified(?string $expiry = null): User
    {
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill(array_filter([
            'is_kyc_verified' => 1,
            'kyc_tier' => 2,
            'identification_expiry_date' => $expiry,
        ], fn ($v) => $v !== null))->save();

        return $u->refresh();
    }

    private function staff(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① التاريخُ يُقرأ — وكان يُجمَع ولا يُقرأ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_expired_identity_is_seen_at_all(): void
    {
        // **وهذا هو العطلُ بعينه:** التاريخُ في القاعدة منذ التسجيل، ولا
        // سطرَ في المشروع كلِّه يقرؤه.
        $u = $this->verified(now()->subDays(30)->toDateString());

        $this->assertSame(IdentityExpiryService::STATE_EXPIRED,
            app(IdentityExpiryService::class)->stateOf($u)['state'],
            'هويّةٌ انتهت قبل شهرٍ قُرئت سارية');
    }

    /** @test */
    public function a_missing_date_is_unknown_not_valid(): void
    {
        // **و«غير معروف» ليس «سارية»** — القاعدة السابعة. فحسابٌ بلا
        // تاريخٍ ليس حساباً سليماً، هو حسابٌ لا نعرف عنه.
        $this->assertSame(IdentityExpiryService::STATE_UNKNOWN,
            app(IdentityExpiryService::class)->stateOf($this->verified())['state'],
            'غيابُ التاريخ قُرئ سلامة — فلا يبحث أحدٌ عمّا ينقص');
    }

    /** @test */
    public function the_nearest_of_the_two_sources_wins(): void
    {
        // **ولا يُؤخذ أوّلُ مصدرٍ يوجد.** للحساب تاريخٌ في ملفّه وتواريخُ
        // على وثائقه، وأخذُ أحدهما اعتباطاً يُخرج «سارية» على حسابٍ
        // إحدى وثيقتيه منتهية.
        $u = $this->verified(now()->addYears(3)->toDateString());

        KycDocument::create([
            'user_id' => $u->id, 'doc_type' => KycDocument::TYPE_ID_FRONT,
            'encrypted_path' => 'x', 'original_mime' => 'image/png',
            'size_bytes' => 10, 'content_sha256' => str_repeat('a', 64),
            'status' => KycDocument::STATUS_APPROVED,
            'document_expires_at' => now()->subDay()->toDateString(),
        ]);

        $state = app(IdentityExpiryService::class)->stateOf($u->refresh());

        $this->assertSame(IdentityExpiryService::STATE_EXPIRED, $state['state'],
            'وثيقةٌ منتهيةٌ حجبها تاريخٌ بعيدٌ في الملفّ');
        $this->assertSame('document', $state['source'],
            'لم يُقَل من أين جاء الحكم — ومراجعةٌ بلا مصدرٍ لا تُبنى عليها');
    }

    /** @test */
    public function the_day_boundary_does_not_shift_with_the_clock(): void
    {
        // **وإلّا قرأ موظّفان الحالةَ نفسَها مختلفة**: «سارية» صباحاً
        // و«منتهية» مساءً على الصفّ نفسِه.
        $u = $this->verified(now()->toDateString());
        $svc = app(IdentityExpiryService::class);

        $morning = $svc->stateOf($u, Carbon::parse(now()->toDateString() . ' 06:00'));
        $evening = $svc->stateOf($u, Carbon::parse(now()->toDateString() . ' 22:00'));

        $this->assertSame($morning['state'], $evening['state'],
            'حالةُ الهويّة تتغيّر بساعة اليوم — فيقرأ موظّفان جوابين');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والرادارُ يَسِم ولا يُجمّد
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_sweep_flags_an_expired_account_for_review(): void
    {
        $u = $this->verified(now()->subDays(5)->toDateString());

        $stats = app(IdentityExpiryService::class)->sweep();

        $this->assertSame(1, $stats['expired']);
        $this->assertSame(1, (int) $u->refresh()->kyc_update_required,
            'انتهت الهويّةُ ولم يدخل الحسابُ طابورَ المراجعة');
    }

    /** @test */
    public function the_sweep_never_freezes_money_by_itself(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وتجميدٌ صامتٌ ليلاً على مئات الحسابات أسوأ من الثغرة التي
        // يسدّها**: يشلّ عملاءَ لم يخطئوا، ويصل صاحبَ المشروع عبر مئة
        // شكوى لا عبر شاشة. فيُنذَر أوّلاً ويقرّر إنسان.
        // ══════════════════════════════════════════════════════════════
        $u = $this->verified(now()->subYear()->toDateString());

        app(IdentityExpiryService::class)->sweep();

        $this->assertSame(1, (int) $u->refresh()->is_kyc_verified,
            'جمّد الرادارُ حساباً من تلقائه — والقرارُ للمراجع لا للمجدوِل');
    }

    /** @test */
    public function the_same_account_is_not_flagged_every_single_night(): void
    {
        // **وسجلُّ تدقيقٍ يمتلئ بالسطر نفسِه ثلاثين مرّةً في الشهر يُعوّد
        // القارئَ على التمرير**، ويُغرق الحدثَ الحقيقيَّ.
        $this->verified(now()->subDays(5)->toDateString());
        $svc = app(IdentityExpiryService::class);

        $svc->sweep();
        $second = $svc->sweep();

        $this->assertSame(0, $second['flagged'],
            'أُعيد الوسمُ في الجولة الثانية — فيغرق السجلُّ بالتكرار');

        $this->assertSame(1, DB::table('audit_decisions')
            ->where('action', 'KYC_IDENTITY_EXPIRED')->count());
    }

    /** @test */
    public function an_approaching_expiry_warns_but_does_not_flag(): void
    {
        $u = $this->verified(now()->addDays(30)->toDateString());

        $stats = app(IdentityExpiryService::class)->sweep();

        $this->assertSame(1, $stats['due']);
        $this->assertSame(0, $stats['flagged']);
        $this->assertNotSame(1, (int) ($u->refresh()->kyc_update_required ?? 0),
            'وُسم حسابٌ هويّتُه سارية — وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة');

        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'KYC_IDENTITY_EXPIRING',
            'subject_id' => (string) $u->id,
        ]);
    }

    /** @test */
    public function an_unverified_account_is_not_swept(): void
    {
        // فحسابٌ لم يُوثَّق بعدُ ممنوعٌ أصلاً، وإنذارُه ضجيجٌ يُغرق الحقيقيَّ.
        User::factory()->create(['type' => 2])
            ->forceFill(['is_kyc_verified' => 0,
                'identification_expiry_date' => now()->subYear()->toDateString()])->save();

        $this->assertSame(0, app(IdentityExpiryService::class)->sweep()['scanned']);
    }

    /** @test */
    public function a_blind_sweep_is_a_failure_not_a_clean_board(): void
    {
        // **وصفرُ ممسوحين لا يعني «لا هويّةَ منتهية» — يعني «لم أنظر».**
        $this->artisan('amial:kyc:scan-expiry')->assertExitCode(1);
    }

    /** @test */
    public function the_radar_is_actually_scheduled(): void
    {
        // **وأمرٌ لا يُنفَّذ ليس أمراً.** (`saher:*` مبنيّةٌ ولا تُجدوَل
        // عمداً وبتعليلٍ مكتوب — وهذا يُجدوَل لأنّ له شاشةً تقرؤه.)
        $this->assertStringContainsString('amial:kyc:scan-expiry',
            file_get_contents(base_path('routes/console.php')),
            'الرادارُ مبنيٌّ ولا يجري — فالعطلُ باقٍ وإن كُتب علاجُه');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والدعمُ يطلب ولا يكتب
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function there_is_no_direct_edit_route_for_customer_identity(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا حارسٌ على قرارٍ لا على شيفرة.**
        //
        // من يستطيع كتابةَ `identification_number` من اللوحة يستطيع
        // **تحويلَ حسابٍ موثَّقٍ إلى شخصٍ آخر** ثمّ سحبَ رصيده. فزرُّ
        // «تعديل» ليس ميزةً ناقصةً — هو بابٌ يجب ألّا يُفتح.
        //
        // ويُقاس من الشيفرة لا من النيّة: متحكّمُ الطلبات لا يحوي فعلاً
        // يكتب قيمةً يُدخلها موظّف.
        // ══════════════════════════════════════════════════════════════
        $src = file_get_contents(
            app_path('Http/Controllers/Admin/ProfileChangeRequestController.php'));

        $this->assertStringNotContainsString('function update', $src,
            'ظهر فعلُ كتابةٍ مباشرةٍ في متحكّم الطلبات');
        $this->assertStringNotContainsString('function edit', $src);
    }

    /** @test */
    public function support_opens_a_request_and_the_old_value_is_captured_now(): void
    {
        // **و«قبل» تُلتقط عند الفتح لا عند القرار** — فقراءتُها بعد شهرٍ
        // تُخرج جواباً عن لحظةٍ غير اللحظة التي وقع فيها الطلب.
        $customer = $this->verified();
        $customer->forceFill(['job_title' => 'صيّاد'])->save();

        $id = app(ProfileChangeRequestService::class)->open(
            $customer->refresh(), 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');

        $this->assertSame('صيّاد',
            DB::table('profile_change_requests')->where('id', $id)->value('old_value'));
    }

    /** @test */
    public function only_the_account_owner_fills_the_new_value(): void
    {
        // **وإلّا فالطلبُ ثوبٌ لزرّ التعديل نفسِه.**
        $customer = $this->verified();
        $staff = $this->staff();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'job_title', $staff->id, 'admin', 'اتّصل العميل');

        $this->expectException(DomainException::class);
        $svc->submit($id, $staff, 'مهندس');
    }

    /** @test */
    public function an_identity_field_needs_a_supporting_document(): void
    {
        // **ورقمُ هويّةٍ يتغيّر بلا وثيقةٍ ليس تحديثاً بل استبدالَ شخص.**
        $customer = $this->verified();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'identification_number',
            $this->staff()->id, 'admin', 'الهويّةُ الجديدة');

        try {
            $svc->submit($id, $customer, '15589480');
            $this->fail('قُبل تغييرُ رقم الهويّة بلا وثيقة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('وثيقةٍ داعمة', $e->getMessage());
        }
    }

    /** @test */
    public function a_field_outside_the_whitelist_is_refused(): void
    {
        // **قائمةٌ بيضاءُ لا سوداء:** بلا هذا يصير المسارُ باباً لكتابة
        // `is_kyc_verified` أو `zone_code` أو أيِّ عمودٍ في الجدول.
        $this->expectException(DomainException::class);

        app(ProfileChangeRequestService::class)->open(
            $this->verified(), 'is_kyc_verified', $this->staff()->id, 'admin', 'محاولة');
    }

    /** @test */
    public function whoever_opened_the_request_cannot_approve_it(): void
    {
        // **وإلّا صار «الطلبُ» زرَّ تعديلٍ بخطوتين.**
        $customer = $this->verified();
        $opener = $this->staff();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'job_title', $opener->id, 'admin', 'اتّصل العميل');
        $svc->submit($id, $customer, 'مهندس');

        $this->expectException(DomainException::class);
        $svc->decide($id, $opener, true);
    }

    /** @test */
    public function a_second_reviewer_can_approve_and_the_value_lands(): void
    {
        // **وحاجزٌ يمنع كلَّ شيءٍ يجتاز نصفَ الفحص ثمّ يشلّ العملَ السليم.**
        $customer = $this->verified();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');
        $svc->submit($id, $customer, 'مهندس');
        $svc->decide($id, $this->staff(), true);

        $this->assertSame('مهندس', $customer->refresh()->job_title);
        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'PROFILE_CHANGE_DECIDED', 'decision_code' => 'APPROVED',
        ]);
    }

    /** @test */
    public function changing_an_identity_field_sends_the_account_back_to_verification(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والوثيقةُ المعتمَدةُ تخصّ البيانَ القديم.**
        //
        // فإبقاءُ «موثَّق» فوق اسمٍ جديدٍ لم تُراجَع وثيقتُه هو التزويرُ
        // بعينه — ولا خطأَ في أيّ سجلّ، ولا شاشةَ تقول إنّ شيئاً تغيّر.
        // ══════════════════════════════════════════════════════════════
        $customer = $this->verified();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'f_name', $this->staff()->id, 'admin', 'تصحيحُ اسم');
        $svc->submit($id, $customer, 'راشد', supportingDocumentId: 1);
        $svc->decide($id, $this->staff(), true);

        $this->assertSame(1, (int) $customer->refresh()->kyc_update_required,
            'تغيّر الاسمُ وبقي التوثيقُ قائماً على وثيقةٍ لا تخصّه');
    }

    /** @test */
    public function a_non_identity_field_does_not_reset_verification(): void
    {
        // **ولا يُعاد كلُّ من غيّر مهنتَه إلى طابور التوثيق** — تشديدٌ
        // بلا سببٍ يُطفَأ عند أوّل شكوى.
        $customer = $this->verified();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');
        $svc->submit($id, $customer, 'مهندس');
        $svc->decide($id, $this->staff(), true);

        $this->assertNotSame(1, (int) ($customer->refresh()->kyc_update_required ?? 0));
    }

    /** @test */
    public function a_rejection_must_say_why(): void
    {
        // رفضٌ بلا سببٍ يجعل العميلَ يعيد الطلبَ نفسَه مرّةً بعد مرّة.
        $customer = $this->verified();
        $svc = app(ProfileChangeRequestService::class);

        $id = $svc->open($customer, 'job_title', $this->staff()->id, 'admin', 'اتّصل العميل');
        $svc->submit($id, $customer, 'مهندس');

        $this->expectException(DomainException::class);
        $svc->decide($id, $this->staff(), false, 'لا');
    }

    /** @test */
    public function two_open_requests_for_one_field_are_refused(): void
    {
        // **فيتعارضان، ويعتمد مراجعان قيمتين مختلفتين في دقيقة.**
        $customer = $this->verified();
        $svc = app(ProfileChangeRequestService::class);

        $svc->open($customer, 'job_title', $this->staff()->id, 'admin', 'الأوّل');

        $this->expectException(DomainException::class);
        $svc->open($customer, 'job_title', $this->staff()->id, 'admin', 'الثاني');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ والشاشةُ تُفتح ويُوصَل إليها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_screen_opens(): void
    {
        $this->actingAs($this->staff(), 'user')
            ->get(route('admin.amial.kyc.changes.page'))
            ->assertOk()
            ->assertSee('pcr-page', false);
    }

    /** @test */
    public function the_sidebar_links_to_it(): void
    {
        // **وصفحةٌ لا يُوصل إليها ليست مبنيّة** — والمسارُ المسجَّلُ ليس
        // ظهوراً. (القاعدة الثانية عشرة.)
        $this->assertStringContainsString('kyc.changes.page',
            file_get_contents(resource_path(
                'views/admin-views/amial/partials/_sidebar.blade.php')),
            'الشاشةُ مبنيّةٌ ولا رابطَ يقود إليها');
    }

    /** @test */
    public function the_screen_says_why_there_is_no_edit_button(): void
    {
        // **وعلّةٌ لا تُقال تُنسى، فيُطلَب الزرُّ كلَّ شهر.**
        $html = file_get_contents(resource_path(
            'views/admin-views/amial/kyc/change-requests.blade.php'));

        $this->assertStringContainsString('ولا يكتب قيمةً', $html);
    }
}
