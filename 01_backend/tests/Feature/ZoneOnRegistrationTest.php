<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecipientVerificationService;
use App\Services\ZoneAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-ZONE-REG-001 — **«غير معروف» ليس «مرفوضاً».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «أنشأتُ حساب عميلٍ لكنّه لا يستقبل التحويلات — ما السبب؟»
 *
 * وقِيس، فوُجد أنّ التصميمَ سليمٌ والسلسلةَ مقطوعةٌ في حلقةٍ واحدة:
 *
 *   · `zone_code` يولد `UNKNOWN`        — **مقصود**: «ممنوعٌ حتّى يثبت»
 *   · `assignFromKyc()` تحسم عند التوثيق — مبنيّةٌ ويناديها ثلاثةُ مسارات
 *   · شرطُ الاستقبال `=== 'SOUTH'`       — سياسةٌ مقصودة
 *   · **`assignOnRegistration()`**       — **مبنيّةٌ ولا يناديها أحد**
 *   · `zone_assignment_logs`             — **صفرُ صفوفٍ** منذ بُني الجدول
 *
 * **فالحلقةُ الناقصةُ ليست في المنع بل في الأثر**: الحسابُ يولد ممنوعاً
 * بحقّ، **بلا سطرٍ يقول لماذا ولا إشاراتٍ يبني عليها المدقّقُ قرارَه**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ من ذلك: الرسالة.**
 *
 *     «المستلم غير مؤهل لاستقبال التحويلات حالياً»
 *
 * صادقةٌ حرفيّاً وصامتةٌ عن سببها. **لا تفرّق بين حسابٍ لم يُوثَّق بعد
 * وحسابٍ خارج نطاق الخدمة** — والفرقُ هو كلُّ ما يحتاجه القارئ: الأوّلُ
 * ينتظر مراجعةً تنتهي، والثاني لن يُخدَم أبداً.
 *
 * فيظنّ المرسِلُ أنّ الحسابَ محظورٌ أو مشبوه، ويظنّ صاحبُه أنّ التطبيقَ
 * معطوب. **ويذهب كلاهما إلى الدعم بلا معلومة.**
 */
class ZoneOnRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $zone = 'UNKNOWN'): User
    {
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill(['zone_code' => $zone])->save();

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الأثرُ يُكتب — ولا يولد حسابٌ بلا سطرٍ يقول لماذا مُنع
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function registration_records_the_zone_decision_and_its_signals(): void
    {
        $u = User::factory()->create(['type' => 2]);

        app(ZoneAssignmentService::class)->assignOnRegistration($u);

        $row = DB::table('zone_assignment_logs')->where('user_id', $u->id)->first();

        $this->assertNotNull($row,
            'حسابٌ أُنشئ بلا سطرٍ في سجلّ إسناد المناطق — '
            . 'فالمدقّقُ يبدأ من فراغٍ ولا يعرف على أيّ شيءٍ يبني');

        $this->assertSame('registration', $row->method);
        $this->assertSame('UNKNOWN', $row->assigned_zone,
            'التسجيلُ منح منطقةً — و«ممنوعٌ حتّى يثبت» انقلب إلى عكسه');
    }

    /**
     * حمولةُ تسجيلٍ صالحةٌ أدنى ما تقبله نقطةُ النهاية.
     */
    private function registrationPayload(string $phone = '783545525'): array
    {
        return [
            'f_name' => 'راشد', 'l_name' => 'المعربي', 'gender' => 'male',
            'dial_country_code' => '+967', 'phone' => $phone,
            'password' => '4321',
        ];
    }

    /** @test */
    public function the_registration_endpoint_actually_calls_it(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ولا يُقاس هذا بمسحِ نصّ الملفّ.**
        //
        // كُتب هذا الحارسُ أوّلَ مرّةٍ `assertStringContainsString`، ثمّ
        // حُذف النداءُ عمداً للتجريب بالعكس — **فمرّ الحارسُ أخضرَ**:
        // الكلمةُ باقيةٌ في **التعليق العربيّ الذي يشرحها**. أي أنّ
        // التعليقَ الذي يصف الإصلاحَ كان يُخفي غيابَه.
        //
        // فالقياسُ على الأثر: يُنشأ حسابٌ من النقطة نفسِها، ويُسأل
        // السجلُّ. وهذا لا يخدعه تعليقٌ ولا إعادةُ تسمية.
        // ══════════════════════════════════════════════════════════════
        $this->withoutExceptionHandling();
        $this->disablePhoneVerification();

        $this->postJson('/api/v1/customer/auth/register', $this->registrationPayload())
            ->assertSuccessful();

        $user = User::where('phone', 'like', '%783545525')->firstOrFail();

        $this->assertNotNull(
            DB::table('zone_assignment_logs')->where('user_id', $user->id)->first(),
            'سُجّل حسابٌ من نقطة النهاية بلا سطرٍ في سجلّ إسناد المناطق — '
            . 'فالدالّةُ مبنيّةٌ ولا يناديها أحد');
    }

    /** @test */
    public function a_failing_assignment_never_blocks_creating_the_account(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والإسنادُ أثرٌ لا شرط** — فإن انفجر لا يسقط إنشاءُ الحساب.
        //
        // وكُتب هذا الحارسُ أوّلَ مرّةٍ بحذف جدول السجلّ، **فمرّ والـ`try`
        // محذوفة**: `logAssignment` تلتقط أخطاءَها بنفسِها، فالفشلُ لم
        // يبلغ المتحكّمَ أصلاً. أي أنّ الحارسَ كان يحرس `catch` الخدمة
        // ويظنّ نفسَه يحرس `try` المتحكّم.
        //
        // فيُحقَن انفجارٌ لا مهربَ منه.
        // ══════════════════════════════════════════════════════════════
        $this->disablePhoneVerification();

        $this->app->bind(ZoneAssignmentService::class, fn () => new class extends ZoneAssignmentService
        {
            public function assignOnRegistration(User $user, ?\Illuminate\Http\Request $request = null): string
            {
                throw new RuntimeException('انفجارٌ مقصودٌ في الإسناد');
            }
        });

        $this->postJson('/api/v1/customer/auth/register', $this->registrationPayload('783545526'))
            ->assertSuccessful();

        $this->assertNotNull(User::where('phone', 'like', '%783545526')->first(),
            'سقط إنشاءُ الحساب بفشل إسنادٍ — والإسنادُ أثرٌ لا شرط');
    }

    private function disablePhoneVerification(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والرفضُ يقول أيَّ رفضٍ هو
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_unverified_recipient_is_told_verification_is_pending(): void
    {
        $sender = $this->customer('SOUTH');
        $recipient = $this->customer('UNKNOWN');

        try {
            app(RecipientVerificationService::class)
                ->verifyRecipient((string) $recipient->phone, $sender->id);

            $this->fail('قُبل مستلمٌ غيرُ موثَّق');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لم يُوثَّق بعد', $e->getMessage(),
                'الرسالةُ لا تقول إنّ التوثيقَ ناقص — فيظنّ القارئُ '
                . 'الحسابَ محظوراً، ويذهب إلى الدعم بلا معلومة');

            // **وتقول ماذا يفعل** — رفضٌ بلا مخرجٍ يُنتج تذكرةَ دعم.
            $this->assertStringContainsString('التوثيق', $e->getMessage());
        }
    }

    /** @test */
    public function a_recipient_outside_the_service_area_gets_a_different_answer(): void
    {
        // **والفرقُ هو كلُّ الفائدة**: هذا لن يُخدَم، وذاك ينتظر مراجعة.
        $sender = $this->customer('SOUTH');
        $recipient = $this->customer('NORTH');

        try {
            app(RecipientVerificationService::class)
                ->verifyRecipient((string) $recipient->phone, $sender->id);

            $this->fail('قُبل مستلمٌ خارج النطاق');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('خارجَ نطاق الخدمة', $e->getMessage());
            $this->assertStringNotContainsString('لم يُوثَّق', $e->getMessage(),
                'خُلط «خارج النطاق» بـ«لم يُوثَّق» — والأوّلُ نهائيٌّ '
                . 'والثاني مؤقَّت');
        }
    }

    /** @test */
    public function a_verified_southern_recipient_still_passes(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة.**
        $sender = $this->customer('SOUTH');
        $recipient = $this->customer('SOUTH');

        $result = app(RecipientVerificationService::class)
            ->verifyRecipient((string) $recipient->phone, $sender->id);

        $this->assertNotEmpty($result, 'مستلمٌ موثَّقٌ في النطاق رُفض');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والسياسةُ نفسُها لا تُمسّ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function unknown_still_means_no_transfers_it_is_not_loosened(): void
    {
        // **ولا يُقرأ هذا الإصلاحُ تخفيفاً.** «ممنوعٌ حتّى يثبت» قرارٌ
        // أمنيٌّ مكتوبٌ في `ZoneAssignmentService`، وكلُّ ما تغيّر هو أنّ
        // الرفضَ صار يقول سببَه.
        $sender = $this->customer('SOUTH');

        $this->expectException(RuntimeException::class);

        app(RecipientVerificationService::class)
            ->verifyRecipient((string) $this->customer('UNKNOWN')->phone, $sender->id);
    }

    /** @test */
    public function an_iso_governorate_code_resolves_to_a_zone_not_to_unknown(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والرمزُ يُترجَم قبل أن يُطابَق.**
        //
        // `residence_governorate` يخزّن `YE-AD` لا «عدن» — و`cityToZone`
        // كانت تطابق أسماءَ المدن وحدَها. فكلُّ اعتمادٍ يمرّ برمزٍ كان
        // يُخرج `UNKNOWN`: **أي أنّ التوثيقَ يحسم المنطقةَ إلى «غير
        // معروفة»، فيبقى الحسابُ ممنوعاً بعد اعتماده.**
        //
        // وهو عطلٌ لا يظهر في أيّ سجلّ: السطرُ يُكتب والقيمةُ تبدو محسومة.
        // ══════════════════════════════════════════════════════════════
        $zones = app(ZoneAssignmentService::class);

        $this->assertSame('SOUTH', $zones->cityToZone('YE-AD'),
            'رمزُ عدن خرج غيرَ جنوبيّ — والتوثيقُ يُخلّد المنعَ بدل أن يرفعه');

        $this->assertSame('NORTH', $zones->cityToZone('YE-SN'));

        // **ولا يُخترَع جوابٌ لما لا يُعرف** — القاعدة السابعة.
        $this->assertSame('UNKNOWN', $zones->cityToZone('YE-XX'));
        $this->assertSame('UNKNOWN', $zones->cityToZone(''));
    }

    /** @test */
    public function approving_the_identity_also_settles_the_zone(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذه هي الحلقةُ التي كان غيابُها سيجعل الإصلاحَ عطلاً.**
        //
        // `KycDocumentService::decideAccount()` هو مسارُ الاعتماد الرئيس،
        // ولم يكن يمسّ `zone_code` بحرف. فتثبيتُ `UNKNOWN` عند التسجيل
        // — بلا هذه الحلقة — **يُخلّد المنعَ بعد التوثيق**، ويشلّ كلَّ
        // حسابٍ جديدٍ في المنتج.
        //
        // (وقِيس أنّ القيمةَ الافتراضيّةَ في القاعدة `SOUTH` لا `UNKNOWN`
        // خلافاً لِما يقوله توثيقُ الخدمة — أي أنّ سياسة «ممنوعٌ حتّى
        // يثبت» لم تكن نافذةً أصلاً قبل هذا التغيير.)
        // ══════════════════════════════════════════════════════════════
        $u = $this->customer('UNKNOWN');
        $u->forceFill([
            'residence_governorate' => 'YE-AD',
            'is_kyc_verified' => 0,
        ])->save();

        app(ZoneAssignmentService::class)->assignFromKyc(
            $u->refresh(), (string) $u->residence_governorate, 1,
        );

        $this->assertSame('SOUTH', $u->refresh()->zone_code,
            'اعتُمدت الهويّةُ وبقيت المنطقةُ غيرَ محسومة — فالتوثيقُ نصفُ توثيق');
    }

    /** @test */
    public function an_approval_with_no_residence_on_file_is_said_not_swallowed(): void
    {
        // **وأسوأُ حالةٍ ممكنةٍ هي التي تبدو مكتملة:** هويّةٌ معتمدةٌ
        // ومنطقةٌ غيرُ محسومة — فالشاشةُ تقول «موثَّق» والتحويلُ يُرفض،
        // ولا سطرَ في أيّ سجلٍّ يربط بينهما.
        $src = file_get_contents(app_path('Services/KycDocumentService.php'));

        $this->assertStringContainsString('KYC_ZONE_UNRESOLVED', $src,
            'اعتمادٌ بلا محافظةِ سكنٍ يمرّ صامتاً — والحسابُ موثَّقٌ وممنوع');

        $this->assertStringContainsString('MISSING_RESIDENCE_GOVERNORATE', $src);
    }

    /** @test */
    public function the_kyc_approval_path_actually_settles_the_zone(): void
    {
        // **ومسارُ الاعتماد يُقاس من الشيفرة لا من النيّة** — فالحلقةُ
        // السابقةُ تُثبت أنّ الخدمةَ تعمل، لا أنّ أحداً يناديها.
        $src = file_get_contents(app_path('Services/KycDocumentService.php'));

        $this->assertMatchesRegularExpression(
            '~if\s*\(\$approve\)\s*\{[^}]{0,400}assignFromKyc~s', $src,
            'مسارُ اعتماد الهويّة لا يحسم المنطقة — فالحسابُ يُوثَّق ويبقى ممنوعاً');
    }

    /** @test */
    public function only_kyc_approval_moves_an_account_into_the_service_area(): void
    {
        // **والمنطقةُ تُحسم بالوثيقة لا بالتسجيل** — وهو الفرقُ الذي
        // يجعل «ممنوعٌ حتّى يثبت» يعني شيئاً.
        $u = $this->customer('UNKNOWN');

        app(ZoneAssignmentService::class)->assignFromKyc($u, 'عدن');

        $this->assertSame('SOUTH', $u->refresh()->zone_code);

        // **والقيمةُ تُقاس من المصدر لا تُخمَّن**: `assignFromKyc` تكتب
        // `kyc_verification`. ومقياسٌ يحرس صياغةً لا معنىً يسقط على
        // شيفرةٍ سليمة — وهو أسوأ من غيابه.
        $row = DB::table('zone_assignment_logs')->where('user_id', $u->id)
            ->where('method', 'kyc_verification')->first();

        $this->assertNotNull($row,
            'حُسمت المنطقةُ بلا أثرٍ يقول من حسمها وبأيّ وثيقة');

        // **والوثيقةُ نفسُها تُحفظ** — فالرمزُ وحدَه لا يقول من أيّ مدينةٍ
        // اشتُقّ، ومراجعةٌ بعد شهرٍ تحتاج ما قرأه المدقّق لا نتيجتَه.
        $this->assertStringContainsString('عدن', (string) $row->signals,
            'حُفظ القرارُ بلا المدينة التي بُني عليها');

        $this->assertSame('SOUTH', $row->kyc_zone,
            'عمودُ `kyc_zone` فارغٌ في إسنادٍ مصدرُه الوثيقةُ نفسُها');
    }
}
