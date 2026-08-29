<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\RecipientVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-EDIT-001 — **المديرُ يفكّ حساباً مقفلاً، من اللوحة، كاملاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:**
 *
 *     «هذا الحساب لا يستقبل تحويلات بسبب لا يوجد مستندات. دخلتُ للحساب
 *      من لوحة الأدمن أردتُ رفعَ مستنداتٍ من أجل يعمل — لا طريقة للرفع.
 *      وهل إذا رفعتُ سوف يستقبل ويعمل بكلّ الصلاحيات؟ ما هذا الغموض.
 *      أنني أصرخُ من شهر يجب إضافة زرّ لتعديل حساب المستخدم».
 *
 * وقِيس فكان محقّاً في الشكوى **وفي الغموض معاً**:
 *
 *   · الرفعُ كان **للعميل وحدَه** من تطبيقه. واللوحةُ تراجع وتعتمد وترفض
 *     وتقرأ OCR — **على وثائقَ موجودةٍ سلفاً**. فمن سجّل بلا وثائق يبقى
 *     مقفلاً أبداً ما لم يرفع بنفسه.
 *   · **ولا زرَّ لتعديل بيانات الحساب** إطلاقاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والجوابُ عن سؤاله «هل إذا رفعتُ يعمل؟» — لا، الرفعُ وحدَه لا يكفي.**
 * وهذا الاختبارُ هو الجواب مكتوباً بالتشغيل: **أربع خطواتٍ لا واحدة**، وإن
 * سقطت واحدةٌ بقي الحسابُ مقفلاً وهو يبدو موثَّقاً:
 *
 *     ① تُرفَع الوثائقُ الثلاث   (وإلّا: KYC_DOCUMENTS_INCOMPLETE)
 *     ② ويُعتمد كلُّ مستندٍ منها  (المرفوعُ غيرُ المعتمَد لا يُحتسب)
 *     ③ وتُضبط محافظةُ السكن     (وإلّا بقي النطاقُ UNKNOWN بعد الاعتماد)
 *     ④ ثمّ يُعتمد الحساب        (فيُشتقّ النطاقُ من المحافظة)
 *
 * **والثالثةُ هي الغموضُ بعينه**: حسابٌ يُعتمد بلا محافظةٍ يخرج
 * «موثَّقاً» ولا يستقبل شيئاً — وصاحبُه يظنّ الأمرَ تمّ.
 */
class AdminUnblocksAnAccountGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009001',
        ]);

        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    private function lockedCustomer(): User
    {
        // حسابٌ كما يخرج من التسجيل: بلا وثائق وبلا نطاق.
        $u = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'phone' => '967783545525', 'is_active' => 1,
        ]);

        $u->forceFill([
            'zone_code' => 'UNKNOWN',
            'is_kyc_verified' => 0,
            'residence_governorate' => null,
        ])->save();

        return $u->refresh();
    }

    /**
     * @test
     *
     * **الحسابُ مقفلٌ فعلاً قبل أيّ شيء — وإلّا فحصنا حالةً غير قائمة.**
     */
    public function the_account_really_is_blocked_before_we_start(): void
    {
        $sender = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'phone' => '967770009009', 'zone_code' => 'SOUTH',
        ]);

        $this->lockedCustomer();

        $this->expectException(\RuntimeException::class);

        app(RecipientVerificationService::class)
            ->verifyRecipient('967783545525', $sender->id);
    }

    /**
     * @test
     *
     * **والمديرُ يفكّه بالخطوات الأربع — ويُقاس الأثرُ لا الردّ.**
     *
     * ولا يُكتفى بـ«ردّت الشاشةُ ٢٠٠»: يُسأل `verifyRecipient` نفسُها في
     * النهاية — أي **المسارُ الذي كان يردّه**. (القاعدة التاسعة: قياسُ ما
     * بعد الضغطة أثرُها لا غيابُ الخطأ.)
     */
    public function an_admin_can_take_a_blocked_account_all_the_way_to_working(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        // ── ① رفعُ الوثائق الثلاث من اللوحة ──
        foreach ([
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ] as $type) {
            $this->actingAs($admin, 'user')
                ->postJson("/admin/amial/hub/users/{$customer->id}/documents", [
                    'doc_type' => $type,
                    'file' => UploadedFile::fake()->image($type . '.jpg'),
                ])
                ->assertSuccessful();
        }

        $this->assertSame(3, KycDocument::where('user_id', $customer->id)->count(),
            'لم تُحفظ الوثائق — فالرفعُ ردّ نجاحاً ولم يفعل شيئاً');

        // ── ② اعتمادُ كلّ مستند ──
        //
        // **والرفعُ لا يعتمد**: مستندٌ مرفوعٌ غيرُ معتمدٍ لا يُحتسب في
        // الاكتمال — وهو الفصلُ المقصود بين الفعل والقرار.
        $docs = KycDocument::where('user_id', $customer->id)->get();

        foreach ($docs as $doc) {
            app(\App\Services\KycDocumentService::class)->approve($doc, $admin);
        }

        // ── ③ ضبطُ محافظة السكن — وبدونها يُعتمد ولا يعمل ──
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile", [
                'residence_governorate' => 'YE-AD',   // عدن — داخل نطاق الخدمة
            ])
            ->assertSuccessful();

        $this->assertSame('YE-AD', $customer->fresh()->residence_governorate,
            'لم تُحفظ المحافظة — والتعديلُ ردّ نجاحاً ولم يفعل شيئاً');

        // ── ④ اعتمادُ الحساب ──
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $after = $customer->fresh();

        $this->assertSame(1, (int) $after->is_kyc_verified, 'لم يُعتمد الحساب');
        $this->assertSame('SOUTH', $after->zone_code,
            '**اعتُمد الحسابُ وبقي خارجَ النطاق** — موثَّقٌ ولا يعمل، وهو '
            . 'أسوأُ من غير الموثَّق: صاحبُه يظنّ الأمرَ تمّ.');

        // ── والقياسُ الحاسم: المسارُ الذي كان يردّ ──
        $sender = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'phone' => '967770009010', 'zone_code' => 'SOUTH',
        ]);

        $result = app(RecipientVerificationService::class)
            ->verifyRecipient('967783545525', $sender->id);

        $this->assertNotEmpty($result['verification_token'],
            'الحسابُ ما زال لا يستقبل بعد الخطوات الأربع كلِّها');
    }

    /**
     * @test
     *
     * **والرفعُ لا يعتمد بنفسه — الفعلُ غيرُ القرار.**
     *
     * فلو اعتمد الرفعُ تلقائيّاً لصار موظّفٌ يرفع صورةً فيوثَّق الحسابُ
     * بضغطةٍ واحدةٍ بلا مراجعة — وهو ما بُنيت الرقابةُ لمنعه.
     */
    public function uploading_does_not_silently_verify_the_account(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/documents", [
                'doc_type' => KycDocument::TYPE_ID_FRONT,
                'file' => UploadedFile::fake()->image('front.jpg'),
            ])
            ->assertSuccessful();

        $this->assertSame(0, (int) $customer->fresh()->is_kyc_verified,
            'وُثّق الحسابُ بمجرّد رفع صورة — بلا مراجعةٍ ولا قرار');

        $this->assertNotSame(KycDocument::STATUS_APPROVED,
            KycDocument::where('user_id', $customer->id)->value('status'),
            'اعتُمد المستندُ لحظةَ رفعه — والاعتمادُ قرارٌ له شاشتُه وسجلُّه');
    }

    /**
     * @test
     *
     * **والهاتفُ لا يُعدَّل من هنا.**
     *
     * فهو مفتاحُ الدخول ومعرّفُ التحويل — وتغييرُه من شاشة التعديل يُسلّم
     * حساباً بتاريخه الماليّ لشخصٍ آخر. وله مسارُه الخاصّ (استعادةُ
     * الحسابات) بموافقةٍ وسجلّ.
     */
    public function the_phone_is_not_editable_from_this_door(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile", [
                'phone' => '967770000000',
                'f_name' => 'اسمٌ جديد',
            ])
            ->assertSuccessful();

        $this->assertSame('967783545525', $customer->fresh()->phone,
            '**تغيّر الهاتفُ من شاشة التعديل** — وهو مفتاحُ الدخول ومعرّفُ '
            . 'التحويل، فتغييرُه هنا يُسلّم الحسابَ بتاريخه لشخصٍ آخر.');

        $this->assertSame('اسمٌ جديد', $customer->fresh()->f_name,
            'لم يُحفظ الاسمُ — فالبابُ يرفض كلَّ شيءٍ لا الهاتفَ وحدَه');
    }

    /**
     * @test
     *
     * **والشاشةُ تقول ما ينقص — قبل الضغط لا بعده.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهو الجوابُ عن «ما هذا الغموض»: من لا يعرف الناقصَ يجرّب ويعيد،
     * ثمّ يظنّ العطلَ في مكانٍ آخر.
     *
     * **ويُقاس تطابقُ الجواب مع البوّابة الحقيقيّة لا وجودُ نصّ**:
     * `can_receive` تقلب مع `verifyRecipient` نفسِها. فشاشةٌ تحسب
     * الاكتمالَ بطريقتها تقول «جاهز» ويردّ المسارُ — وحارسٌ يفحص وجودَ
     * الكلمات يمرّ على ذلك كلِّه.
     * ══════════════════════════════════════════════════════════════════
     */
    public function the_screen_names_what_is_missing_and_agrees_with_the_real_gate(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $before = $this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data');

        $this->assertFalse($before['can_receive'],
            'قالت الشاشةُ «جاهز» عن حسابٍ لا يستقبل — وشاشةٌ تكذب أسوأ من غيابها');

        $this->assertNotEmpty($before['blockers'],
            'لا مانعَ مذكورٌ لحسابٍ ممنوع — «غير معروف» عُرض صفراً');

        // ══════════════════════════════════════════════════════════════
        // **ويُسمّى الناقصُ بعينه، ويُفحص بالرمز لا بالنصّ.**
        //
        // جُرّب هذا بالعكس بالنصّ فمرّ: أُسكت مانعُ «لا محافظةَ في
        // الملفّ» **فوجد الفحصُ كلمةَ «محافظة» في مانعٍ أخيه** («خارجَ
        // نطاق الخدمة») وقال «سليم». وهو نفسُ عطلِ `str_contains` الذي
        // مرّ من قبلُ على مسارٍ شقيق.
        // ══════════════════════════════════════════════════════════════
        $codes = array_column($before['blockers'], 'code');

        $this->assertContains('GOVERNORATE_MISSING', $codes,
            '**لم يُذكر غيابُ المحافظة** — وهي الخطوةُ التي إن سقطت خرج '
            . 'الحسابُ موثَّقاً وهو لا يستقبل. ولا يكفي «خارجَ النطاق» '
            . 'مكانَها: ذاك تشخيصٌ آخرُ يقود إلى فعلٍ آخر.');

        $this->assertContains('DOCUMENTS_INCOMPLETE', $codes,
            'لم تُذكر الوثائقُ الناقصة — والموظّفُ يبحث عمّا يرفع');

        $this->assertNotContains('GOVERNORATE_OUT_OF_ZONE', $codes,
            'قِيل «خارجَ نطاق الخدمة» لحسابٍ **بلا محافظةٍ أصلاً** — '
            . 'وهو حكمٌ نهائيٌّ على حالةٍ يكفيها ملءُ حقل.');

        // ولا وثيقةَ منها معتمَدة، ويُقال ذلك لكلّ نوعٍ على حدة.
        foreach ($before['documents'] as $doc) {
            $this->assertFalse($doc['usable'],
                "وثيقةٌ «{$doc['label']}» عُدّت معتمَدةً ولم تُرفع أصلاً");
        }

        // ── تُنفَّذ الخطواتُ الأربع ──
        foreach ([
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ] as $type) {
            $this->actingAs($admin, 'user')
                ->postJson("/admin/amial/hub/users/{$customer->id}/documents", [
                    'doc_type' => $type,
                    'file' => UploadedFile::fake()->image($type . '.jpg'),
                ])->assertSuccessful();
        }

        foreach (KycDocument::where('user_id', $customer->id)->get() as $doc) {
            app(\App\Services\KycDocumentService::class)->approve($doc, $admin);
        }

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile",
                ['residence_governorate' => 'YE-AD'])->assertSuccessful();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $after = $this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data');

        $this->assertTrue($after['can_receive'], sprintf(
            'بقيت الشاشةُ تقول «ممنوع» بعد الخطوات الأربع: %s',
            implode(' | ', array_column($after['blockers'], 'code'))));

        // **والقياسُ الحاسم: تطابقُ الجواب مع المسار الذي يردّ فعلاً.**
        $sender = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'phone' => '967770009011', 'zone_code' => 'SOUTH',
        ]);

        $this->assertNotEmpty(
            app(RecipientVerificationService::class)
                ->verifyRecipient('967783545525', $sender->id)['verification_token'],
            'قالت الشاشةُ «جاهز» وردّ مسارُ التحويل — وهذا أخطرُ من الصمت');
    }

    /**
     * @test
     *
     * **والمحافظةُ تُختار من القائمة — ونصٌّ حرٌّ يُردّ عند بابه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فـ`cityToZone` تطابق رمزاً أو اسماً معروفاً، وما لا يُطابَق يخرج
     * `UNKNOWN`. فقبولُ «عدن — الشيخ عثمان» يحفظ بياناً **يبدو صحيحاً**
     * ثمّ يُنتج حساباً موثَّقاً لا يستقبل، ولا خطأَ في أيّ سجلّ.
     *
     * **والقائمةُ تحمل منطقةَ كلّ محافظة** — فمن اختار شمالاً يعرف قبل
     * الحفظ أنّ الحسابَ لن يستقبل: خارجَ النطاق لا ناقصَ وثيقة.
     * ══════════════════════════════════════════════════════════════════
     */
    public function a_free_text_governorate_is_refused_and_the_list_carries_its_zone(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile",
                ['residence_governorate' => 'عدن — الشيخ عثمان'])
            ->assertStatus(422);

        $this->assertNull($customer->fresh()->residence_governorate,
            '**حُفظ نصٌّ حرٌّ كمحافظة** — فيُعتمد الحسابُ ويخرج النطاقُ '
            . 'UNKNOWN، وهي أسوأُ حالةٍ لأنّها تبدو مكتملة.');

        $list = $this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data.governorates');

        $zones = collect($list)->pluck('zone', 'code');

        $this->assertSame('SOUTH', $zones['YE-AD'] ?? null,
            'عدنُ لا تُعرض داخلَ النطاق — والقائمةُ تُضلّل من يختار');
        $this->assertNotSame('SOUTH', $zones['YE-SN'] ?? null,
            '**صنعاءُ تُعرض داخلَ النطاق** — فيُحفظ اختيارٌ ويُعتمد الحسابُ '
            . 'ثمّ لا يستقبل، ويُبحث عن العطل في مكانٍ آخر.');
    }

    /**
     * @test
     *
     * **وكلُّ حقلٍ تعرضه الشاشةُ يُقبل ويُحفظ — لا حقلَ يُرسَل ولا يصل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهو صنفُ العطل الذي وقع في هذا المشروع من قبل: `'metadata'` في
     * سبعةَ عشرَ موضعاً و`record()` تقرأ `context` — فسقط `staff_id`
     * و`branch_id` بصمت، والسجلُّ يقول «فُتحت ورديّة» ولا يقول من ولا أين.
     *
     * **فيُقاس كلُّ حقلٍ بالحفظ لا بالردّ**: نداءٌ يخرج ٢٠٠ وقد أسقط
     * الحقلَ في طريقه ليس حفظاً. (القاعدة السادسة.)
     * ══════════════════════════════════════════════════════════════════
     */
    public function every_field_the_screen_offers_actually_persists(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $samples = [
            'f_name' => 'راشد', 'l_name' => 'العرابي', 'name_en' => 'Rashed',
            'father_name' => 'سالم', 'grandfather_name' => 'أحمد',
            'residence_governorate' => 'YE-AD', 'residence_district' => 'المعلا',
            'residence_area' => 'حيّ الشهداء', 'residence_landmark' => 'قرب المستشفى',
            'occupation' => 'محاسب', 'gender' => 'male',
            'income_source' => 'salary', 'account_purpose' => 'savings',
        ];

        $hub = \App\Http\Controllers\Admin\AdminHubController::class;
        $declared = $hub::EDITABLE_PROFILE_FIELDS;   // ما تعرضه الشاشة
        $accepted = array_keys($hub::profileRules()); // ما يقبله الحفظ

        // ══════════════════════════════════════════════════════════════
        // **والمقارنةُ بين مصدرين مختلفين، في الاتّجاهين.**
        //
        // أوّلُ صياغةٍ لهذا الحارس قارنت القائمةَ المُعلَنة **بنفسها**،
        // فجُرّبت بالعكس — حُذف `residence_governorate` منها — **فمرّ
        // الحارسُ**: تقلّصت القائمةُ وتقلّص معها ما يُفحَص. وهو الصمتُ
        // بثوب نجاح، والقاعدةُ الثانية بعينها.
        // ══════════════════════════════════════════════════════════════
        sort($declared); sort($accepted);

        $this->assertSame($accepted, $declared,
            "**ما تعرضه الشاشةُ غيرُ ما يقبله الحفظ:**\n"
            . '  معروضٌ ولا يُقبل: ' . (implode('، ', array_diff($declared, $accepted)) ?: '—') . "\n"
            . '  مقبولٌ ولا يُعرض: ' . (implode('، ', array_diff($accepted, $declared)) ?: '—') . "\n\n"
            . 'والأوّلُ حقلٌ يكتبه الموظّفُ ولا يصل، والثاني بابٌ مفتوحٌ '
            . 'بلا شاشة. ولا خطأَ في أيّ سجلٍّ في الحالتين.');

        $this->assertSame([], array_diff($declared, array_keys($samples)),
            'حقلٌ مُعلَنٌ بلا قيمةِ فحصٍ — والحارسُ يفحص بعضَ القائمة ويقول «سليم»');

        $this->assertSame([], array_diff(array_keys($samples), $declared),
            'قيمةُ فحصٍ لحقلٍ لم يعد مُعلَناً — يُنظَّف الفحصُ أو يُعاد الحقل');

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile", $samples)
            ->assertSuccessful();

        $after = $customer->fresh();
        $lost = [];

        foreach ($samples as $field => $value) {
            if ((string) ($after->{$field} ?? '') !== (string) $value) {
                $lost[] = sprintf('  %-24s أُرسل «%s» وبقي «%s»',
                    $field, $value, (string) ($after->{$field} ?? ''));
            }
        }

        $this->assertSame([], $lost,
            "**حقولٌ تُرسَل ولا تصل:**\n" . implode("\n", $lost) . "\n\n"
            . 'والموظّفُ يكتبها ويرى «حُدّثت البيانات» — ولا شيءَ يحفظها، '
            . 'ولا خطأَ في أيّ سجلّ.');

        // **والشاشةُ تعرض ما يُقبل** — قائمتان تفترقان تُنتجان حقلاً معروضاً
        // بلا مستقبِل، أو مقبولاً بلا حقلٍ يملؤه.
        $shown = array_keys($this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data.profile'));

        $this->assertSame([], array_diff($declared, $shown),
            'حقلٌ يُقبل في الحفظ ولا تعرضه الشاشة: '
            . implode('، ', array_diff($declared, $shown)));
    }
}
