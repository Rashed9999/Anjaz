<?php

namespace Tests\Feature;

use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Services\EncryptionService;
use App\Services\Kyc\IdentityLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-KYC-DUP-001 — **فلترُ الهويّة قبل الاعتماد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «فلتر في الهويّات قبل اعتماد التوثيق — يُدخَل رقمُ الهويّة،
 * فإذا سُجّلت سابقاً تظهر لمن، وإذا لا تُعتمد. مع بيان تاريخ الانتهاء
 * والمنتهية».
 *
 * **وقِيس قبل أن يُكتب سطر، فالمقارنةُ كانت مبنيّةً على عمودٍ فارغ:**
 *
 *     `->national_id =` في المشروع كلِّه    →  **صفرُ إسناد**
 *     ولا عمودَ `national_id` في `users`     →  والمفتاحُ في الخريطة اسمُه
 *     `verified_fields` في متحكّمٍ أو قالب   →  صفر
 *     `IdentityExpiryService` في لوحة التحقّق →  صفر
 *
 * فثلاثةُ أشياءَ كانت معطَّلةً بخطأ اسمٍ واحد: كشفُ الهويّة المكرَّرة،
 * ومطابقةُ قوائم العقوبات، وتشفيرُ رقم الهويّة أصلاً.
 *
 * **وأخطرُها لم يكن اسماً بل تطبيعاً:** `'/[^\d]/'` بلا `/u` تحذف
 * الأرقامَ العربيّةَ كلَّها، **والهويّةُ اليمنيّةُ مطبوعةٌ بها**. فتصير
 * القيمةُ فراغاً، ويُجزَّأ الفراغُ، **فيتشارك كلُّ هؤلاء بصمةً واحدة**.
 * ولو أُطلقت الميزةُ قبل هذا لقالت «مسجَّلةٌ لأربعين شخصاً» على كلّ بحث.
 */
class IdentityLookupGuardTest extends TestCase
{
    use RefreshDatabase;

    private function account(?string $nid = null, int $type = CUSTOMER_TYPE): User
    {
        $u = User::factory()->create(['type' => $type, 'is_active' => 1]);

        if ($nid !== null) {
            $u->identification_number = $nid;
            $u->save();
        }

        return $u->fresh();
    }

    /** @test */
    public function an_arabic_numeral_id_no_longer_collapses_to_one_shared_fingerprint(): void
    {
        // **العطلُ الأمّ.** ثلاثةُ أشخاصٍ لا يشتركون في رقم — وكانوا
        // يتشاركون بصمةً واحدة، فيبدون كلُّهم مكرَّرين.
        $enc = app(EncryptionService::class);

        $a = $enc->blindIndex('١٢٣٤٥٦٧٨', 'national_id');
        $b = $enc->blindIndex('٩٨٧٦٥٤٣٢', 'national_id');
        $c = $enc->blindIndex('١١١٢٢٢٣٣', 'national_id');

        $this->assertNotNull($a, 'هويّةٌ بأرقامٍ عربيّةٍ لا تُنتج بصمةً — '
            .'فلا تدخل الفحصَ إطلاقاً');

        $this->assertNotSame($a, $b, 'رقمان مختلفان أنتجا البصمةَ نفسَها — '
            .'وهو ما يجعل كلَّ بحثٍ يقول «مسجَّلةٌ لعشرات الحسابات»');
        $this->assertNotSame($a, $c);

        // والعربيُّ والفارسيُّ واللاتينيُّ لرقمٍ واحدٍ **يلتقون**.
        $this->assertSame($enc->blindIndex('12345678', 'national_id'), $a);
        $this->assertSame($enc->blindIndex('۱۲۳۴۵۶۷۸', 'national_id'), $a);

        // **والفواصلُ لا تفرّق**: «01-234567» و«01 234567» رقمٌ واحد.
        $this->assertSame(
            $enc->blindIndex('01-234567', 'national_id'),
            $enc->blindIndex('01 234567', 'national_id'));
    }

    /** @test */
    public function a_value_that_normalises_to_nothing_gets_no_fingerprint_at_all(): void
    {
        // **بصمةُ الفراغ تجمع الغرباء** — وهي أسوأُ من غياب البصمة.
        $enc = app(EncryptionService::class);

        $this->assertNull($enc->blindIndex('abc', 'national_id'));
        $this->assertNull($enc->blindIndex('---', 'national_id'));
        $this->assertNull($enc->blindIndex('  ', 'national_id'));
    }

    /** @test */
    public function writing_the_id_number_now_fills_the_indexed_column(): void
    {
        // **جذرُ العطل**: التسجيلُ يكتب `identification_number`، والخريطةُ
        // كانت على `national_id` — اسمٌ لا عمودَ له. فالعمودُ المفهرَسُ
        // يبقى فارغاً مهما سُجّل من حسابات.
        $u = $this->account('012345678');

        $raw = $u->getAttributes();

        $this->assertNotNull($raw['national_id_blind_index'] ?? null,
            'كتابةُ رقم الهويّة لم تملأ العمودَ المفهرَس — فالبحثُ لن يجدَه أبداً');
        $this->assertNotNull($raw['national_id_encrypted'] ?? null,
            'رقمُ الهويّة يُخزَّن مسطَّحاً بلا تشفير');
        $this->assertSame('01*****78', $raw['national_id_masked'] ?? null);

        // والمرادفُ يُصلح قارئاً قائماً — `SanctionScreeningService`
        // تسأل `$user->national_id` وكانت تأخذ `null` دائماً.
        $this->assertSame('012345678', $u->national_id);
    }

    /** @test */
    public function the_search_finds_another_account_and_never_the_one_under_review(): void
    {
        $other = $this->account('012345678', CUSTOMER_TYPE);
        $subject = $this->account('012345678', MERCHANT_TYPE);
        $reviewer = $this->account(null, ADMIN_TYPE);

        $r = app(IdentityLookupService::class)->search('٠١٢٣٤٥٦٧٨', $subject, $reviewer);

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['found'], 'هويّةٌ مسجَّلةٌ لحسابٍ آخرَ لم تُوجَد — '
            .'وهي بالضبط الحالةُ التي بُنيت الميزةُ لها');
        $this->assertSame('DUPLICATE', $r['verdict']);

        $ids = array_column($r['matches'], 'id');
        $this->assertContains($other->id, $ids);
        $this->assertNotContains($subject->id, $ids,
            'الحسابُ قيدَ المراجعة ظهر مكرَّراً لنفسِه — فكلُّ اعتمادٍ يُمنَع');
    }

    /** @test */
    public function the_result_never_leaks_the_other_person_name_or_number(): void
    {
        // ② **«تظهر لمن» تكشف هويّةَ شخصٍ ثالثٍ لموظّف.**
        $other = $this->account('012345678', CUSTOMER_TYPE);
        $other->f_name = 'عبدالرحمن';
        $other->l_name = 'الشرعبي';
        $other->save();

        $subject = $this->account(null, MERCHANT_TYPE);
        $reviewer = $this->account(null, ADMIN_TYPE);

        $r = app(IdentityLookupService::class)->search('012345678', $subject, $reviewer);

        $blob = json_encode($r, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('عبدالرحمن', $blob,
            'خرج الاسمُ الكاملُ لشخصٍ ثالث — فصار البحثُ أداةَ استعلامٍ عن الناس');
        $this->assertStringNotContainsString('الشرعبي', $blob);

        $this->assertSame('ع… ا…', $r['matches'][0]['name_masked']);
        $this->assertArrayNotHasKey('phone', $r['matches'][0]);
        $this->assertArrayNotHasKey('national_id', $r['matches'][0]);
    }

    /** @test */
    public function every_lookup_is_written_to_the_pii_trail_even_when_it_finds_nothing(): void
    {
        // **بحثٌ لم يجد شيئاً استعلامٌ عن شخصٍ أيضاً.** وأثرٌ يُكتب عند
        // الوجود وحدَه يُخفي من يفتّش عن الناس ولا يجد.
        $subject = $this->account(null, MERCHANT_TYPE);
        $reviewer = $this->account(null, ADMIN_TYPE);

        DB::table('pii_access_logs')->delete();

        app(IdentityLookupService::class)->search('999888777', $subject, $reviewer);

        $rows = DB::table('pii_access_logs')
            ->where('actor_user_id', $reviewer->id)
            ->where('field_name', 'national_id')
            ->get();

        $this->assertCount(1, $rows,
            'بحثٌ عن هويّةٍ لم يترك أثراً — والاطّلاعُ على بيانات الناس '
            .'يُسجَّل وُجد أم لم يوجد');
    }

    /** @test */
    public function a_number_too_short_is_refused_with_its_reason(): void
    {
        // «1» و«12» تصادفاتٌ لا انتحال، والبحثُ بها يُخرج نصفَ القاعدة.
        $subject = $this->account(null, MERCHANT_TYPE);
        $reviewer = $this->account(null, ADMIN_TYPE);

        $r = app(IdentityLookupService::class)->search('12', $subject, $reviewer);

        $this->assertFalse($r['ok']);
        $this->assertSame('REFUSED', $r['verdict']);
        $this->assertNotEmpty($r['reason'], 'رُفض البحثُ بلا سبب — '
            .'فيُعيد المراجعُ المحاولةَ ولا يعرف لماذا');
    }

    /** @test */
    public function remembering_never_overwrites_a_different_number_already_on_file(): void
    {
        // ③ **تصحيحُ رقم هويّةٍ له بابُه** — وكتابتُه من هنا صامتةً تُضيع
        // الأصل، وهو مستندٌ رقابيّ.
        $subject = $this->account('012345678', MERCHANT_TYPE);

        $kept = app(IdentityLookupService::class)->remember('999888777', $subject);

        $this->assertFalse($kept['stored']);
        $this->assertNotEmpty($kept['reason']);
        $this->assertSame('012345678', $subject->fresh()->identification_number);
    }

    /** @test */
    public function remembering_fills_an_empty_file_so_the_automatic_check_grows(): void
    {
        $subject = $this->account(null, MERCHANT_TYPE);

        $kept = app(IdentityLookupService::class)->remember('012345678', $subject);

        $this->assertTrue($kept['stored']);
        $this->assertNotNull($subject->fresh()->getAttributes()['national_id_blind_index']);
    }

    /** @test */
    public function an_expired_identity_blocks_the_approval_and_says_so_before_the_press(): void
    {
        // **«مع بيان تاريخ الانتهاء والمنتهية».** و`IdentityExpiryService`
        // كانت مبنيّةً ومقروءةً من ثلاثة مواضع — **ولا واحدَ منها لوحةُ
        // التحقّق**. فالمراجعُ يضغط «اعتماد» ولا يرى التاريخَ أصلاً.
        $merchant = $this->account('012345678', MERCHANT_TYPE);
        $merchant->identification_expiry_date = now()->subMonths(6)->toDateString();
        $merchant->save();

        $reviewer = $this->account(null, ADMIN_TYPE);

        $ev = app(\App\Services\Admin\KycEvidenceService::class)
            ->for($merchant->fresh(), 2, $reviewer);

        $this->assertSame(
            \App\Services\Kyc\IdentityExpiryService::STATE_EXPIRED,
            $ev['identity_expiry']['state'] ?? null,
            'حالةُ الهويّة لا تصل لوحةَ التحقّق — فالتاريخُ يُجمَع ولا يُقرأ');

        $joined = implode(' · ', $ev['blockers']);

        $this->assertStringContainsString('منتهية', $joined,
            'هويّةٌ منتهيةٌ لا تمنع الاعتماد — فيُختَم التوثيقُ على ورقةٍ ميّتة');
    }

    /** @test */
    public function the_same_expiry_rule_also_guards_the_real_approval_path(): void
    {
        // **عرضٌ بلا منعٍ لافتةٌ تُتخطّى، ومنعٌ بلا عرضٍ ردٌّ بلا سبب.**
        // فالشرطُ في الموضعين، ويُقاس في الموضعين. (القاعدة الرابعة.)
        $src = file_get_contents(app_path('Services/KycDocumentService.php'));

        // **والتعليقُ ليس تنفيذاً** — يُنزَع قبل الفحص، وإلّا مرّ الحارسُ
        // على شرحٍ يصف الشرطَ بدل الشرط.
        $code = implode("\n", array_filter(
            explode("\n", $src),
            fn ($l) => ! str_starts_with(ltrim($l), '//')));

        $this->assertStringContainsString('IdentityExpiryService', $code,
            'مسارُ الاعتماد الحقيقيُّ لا يسأل عن انتهاء الهويّة — '
            .'واللوحةُ وحدَها تحرس، وهي بابٌ من بابين');
        $this->assertStringContainsString('STATE_EXPIRED', $code);
    }

    /** @test */
    public function a_missing_expiry_date_is_said_plainly_and_never_read_as_valid(): void
    {
        // **القاعدة السابعة.** التاريخُ اختياريٌّ في التسجيل، فغيابُه
        // شائع — ولا يجوز أن يُعرَض فراغاً يُقرأ سلامة، ولا أن يمنع
        // فيُجمَّد الطابورُ كلُّه.
        $merchant = $this->account('012345678', MERCHANT_TYPE);
        $reviewer = $this->account(null, ADMIN_TYPE);

        $ev = app(\App\Services\Admin\KycEvidenceService::class)
            ->for($merchant, 2, $reviewer);

        $this->assertSame(
            \App\Services\Kyc\IdentityExpiryService::STATE_UNKNOWN,
            $ev['identity_expiry']['state'] ?? null,
            'غيابُ التاريخ لم يُقَل صراحةً — فيُقرأ «سارية»');

        $this->assertStringNotContainsString('منتهية', implode(' · ', $ev['blockers']),
            'غيابُ التاريخ مَنَع الاعتماد — فيُجمَّد كلُّ من سجّل بلا تاريخ');
    }

    /** @test */
    public function the_existing_search_scope_still_resolves_after_the_map_was_renamed(): void
    {
        // **ما كسرَته هذه الجولةُ ثمّ أُصلح.** تغييرُ مفتاح الخريطة أسقط
        // `scopeWhereNationalId` — فبحثُ مركز العملاء كلُّه ٥٠٠. وأمسكته
        // البوّابةُ في ثلاثة اختباراتٍ قائمة، لا هذا الحارس.
        //
        // فيُثبَّت هنا: الاقترانُ بين اسم المفتاح وقارئٍ في ملفٍّ آخرَ
        // صار مقيساً، فلا يُكسَر ثانيةً بصمت.
        $this->account('012345678', CUSTOMER_TYPE);

        $found = User::whereNationalId('٠١٢٣٤٥٦٧٨')->get();

        $this->assertCount(1, $found,
            'نطاقُ البحث عن الهويّة لا يجد ما كُتب — أو يرمي لأنّ مفتاحَ '
            .'الخريطة تغيّر ولم يتبعه');
    }

    /** @test */
    public function the_lookup_route_is_shut_to_a_reviewer_without_the_permission(): void
    {
        // إخفاءُ الواجهة ليس حمايةً — والنداءُ يُجرَّب مباشرةً.
        $merchant = $this->account(null, MERCHANT_TYPE);

        $req = MerchantVerificationRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'status' => 'pending_review',
            'business_name' => 'متجرُ اختبار',
        ]);

        $this->postJson("/admin/amial/merchants/verification/{$req->id}/identity-lookup",
            ['national_id' => '012345678'])
            ->assertStatus(302);   // بلا جلسةِ مشرفٍ ← إلى الدخول
    }
}
