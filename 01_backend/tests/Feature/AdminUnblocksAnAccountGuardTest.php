<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\RecipientVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-EDIT-001 — **المديرُ يفكّ حساباً مقفلاً، من اللوحة، كاملاً.**
 *
 * الحساب المقفل لا يعود صالحاً لمجرد رفع الصور. التسلسل الحقيقي الآن:
 * رفع المستندات ← اعتمادها ← إقرار رقم الهوية/إثبات الملكية ← محافظة السكن
 * ← قرار الحساب. ويُقاس النجاح من باب التحويل نفسه لا من رسالة الواجهة.
 */
class AdminUnblocksAnAccountGuardTest extends TestCase
{
    private int $imageSeq = 0;

    private function distinctImage(string $name): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()
            ->image($name, 600, 400 + (++$this->imageSeq));
    }

    use RefreshDatabase;
    use EstablishesKycEvidence;

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

    /** @test */
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

    /** @test */
    public function an_admin_can_take_a_blocked_account_all_the_way_to_working(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        foreach ([
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ] as $type) {
            $this->actingAs($admin, 'user')
                ->postJson("/admin/amial/hub/users/{$customer->id}/documents", [
                    'doc_type' => $type,
                    'file' => $this->distinctImage($type . '.jpg'),
                ])
                ->assertSuccessful();
        }

        $this->assertSame(3, KycDocument::where('user_id', $customer->id)->count(),
            'لم تُحفظ الوثائق — فالرفعُ ردّ نجاحاً ولم يفعل شيئاً');

        $docs = KycDocument::where('user_id', $customer->id)->get();
        foreach ($docs as $doc) {
            app(\App\Services\KycDocumentService::class)->approve($doc, $admin);
        }

        // الصور المعتمدة ليست إثبات ملكية وحدها. المراجع يقر رقم الهوية
        // من وثيقة معتمدة قبل أن يصبح القرار النهائي قابلاً للنجاح.
        $this->establishKycOwnership($customer->fresh(), $admin);

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile", [
                'residence_governorate' => 'YE-AD',
            ])
            ->assertSuccessful();

        $this->assertSame('YE-AD', $customer->fresh()->residence_governorate,
            'لم تُحفظ المحافظة — والتعديلُ ردّ نجاحاً ولم يفعل شيئاً');

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $after = $customer->fresh();
        $this->assertSame(1, (int) $after->is_kyc_verified, 'لم يُعتمد الحساب');
        $this->assertSame('SOUTH', $after->zone_code,
            '**اعتُمد الحسابُ وبقي خارجَ النطاق** — موثَّقٌ ولا يعمل');

        $sender = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'phone' => '967770009010', 'zone_code' => 'SOUTH',
        ]);

        $result = app(RecipientVerificationService::class)
            ->verifyRecipient('967783545525', $sender->id);

        $this->assertNotEmpty($result['verification_token'],
            'الحسابُ ما زال لا يستقبل بعد مسار فك القفل كاملاً');
    }

    /** @test */
    public function uploading_does_not_silently_verify_the_account(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/documents", [
                'doc_type' => KycDocument::TYPE_ID_FRONT,
                'file' => $this->distinctImage('front.jpg'),
            ])
            ->assertSuccessful();

        $this->assertSame(0, (int) $customer->fresh()->is_kyc_verified,
            'وُثّق الحسابُ بمجرّد رفع صورة — بلا مراجعةٍ ولا قرار');

        $this->assertNotSame(KycDocument::STATUS_APPROVED,
            KycDocument::where('user_id', $customer->id)->value('status'),
            'اعتُمد المستندُ لحظةَ رفعه — والاعتمادُ قرارٌ له شاشتُه وسجلُّه');
    }

    /** @test */
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
            '**تغيّر الهاتفُ من شاشة التعديل** — وهو مفتاحُ الدخول ومعرّفُ التحويل');

        $this->assertSame('اسمٌ جديد', $customer->fresh()->f_name,
            'لم يُحفظ الاسمُ — فالبابُ يرفض كلَّ شيءٍ لا الهاتفَ وحدَه');
    }

    /** @test */
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

        $codes = array_column($before['blockers'], 'code');
        $this->assertContains('GOVERNORATE_MISSING', $codes,
            'لم يُذكر غياب المحافظة');
        $this->assertContains('DOCUMENTS_INCOMPLETE', $codes,
            'لم تُذكر الوثائق الناقصة');
        $this->assertNotContains('GOVERNORATE_OUT_OF_ZONE', $codes,
            'قيل خارج النطاق لحساب بلا محافظة أصلاً');

        foreach ($before['documents'] as $doc) {
            $this->assertFalse($doc['usable'],
                "وثيقةٌ «{$doc['label']}» عُدّت معتمَدةً ولم تُرفع أصلاً");
        }

        foreach ([
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ] as $type) {
            $this->actingAs($admin, 'user')
                ->postJson("/admin/amial/hub/users/{$customer->id}/documents", [
                    'doc_type' => $type,
                    'file' => $this->distinctImage($type . '.jpg'),
                ])->assertSuccessful();
        }

        foreach (KycDocument::where('user_id', $customer->id)->get() as $doc) {
            app(\App\Services\KycDocumentService::class)->approve($doc, $admin);
        }

        $this->establishKycOwnership($customer->fresh(), $admin);

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
            'بقيت الشاشةُ تقول «ممنوع» بعد المسار الكامل: %s',
            implode(' | ', array_column($after['blockers'], 'code'))));

        $sender = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'phone' => '967770009011', 'zone_code' => 'SOUTH',
        ]);

        $this->assertNotEmpty(
            app(RecipientVerificationService::class)
                ->verifyRecipient('967783545525', $sender->id)['verification_token'],
            'قالت الشاشةُ «جاهز» وردّ مسارُ التحويل');
    }

    /** @test */
    public function a_free_text_governorate_is_refused_and_the_list_carries_its_zone(): void
    {
        $admin = $this->admin();
        $customer = $this->lockedCustomer();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/profile",
                ['residence_governorate' => 'عدن — الشيخ عثمان'])
            ->assertStatus(422);

        $this->assertNull($customer->fresh()->residence_governorate,
            '**حُفظ نصٌّ حرٌّ كمحافظة** — فيُعتمد الحسابُ ويخرج النطاقُ UNKNOWN');

        $list = $this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data.governorates');

        $zones = collect($list)->pluck('zone', 'code');
        $this->assertSame('SOUTH', $zones['YE-AD'] ?? null,
            'عدنُ لا تُعرض داخلَ النطاق — والقائمةُ تُضلّل من يختار');
        $this->assertNotSame('SOUTH', $zones['YE-SN'] ?? null,
            '**صنعاءُ تُعرض داخلَ النطاق**');
    }

    /** @test */
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
        $declared = $hub::EDITABLE_PROFILE_FIELDS;
        $accepted = array_keys($hub::profileRules());
        sort($declared); sort($accepted);

        $this->assertSame($accepted, $declared,
            "ما تعرضه الشاشة غير ما يقبله الحفظ:\n"
            . 'معروض ولا يقبل: ' . (implode('، ', array_diff($declared, $accepted)) ?: '—') . "\n"
            . 'مقبول ولا يعرض: ' . (implode('، ', array_diff($accepted, $declared)) ?: '—'));

        $this->assertSame([], array_diff($declared, array_keys($samples)),
            'حقلٌ مُعلَنٌ بلا قيمةِ فحص');
        $this->assertSame([], array_diff(array_keys($samples), $declared),
            'قيمةُ فحصٍ لحقلٍ لم يعد مُعلَناً');

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
            "حقولٌ تُرسَل ولا تصل:\n" . implode("\n", $lost));

        $shown = array_keys($this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data.profile'));

        $this->assertSame([], array_diff($declared, $shown),
            'حقلٌ يُقبل في الحفظ ولا تعرضه الشاشة: '
            . implode('، ', array_diff($declared, $shown)));
    }
}
