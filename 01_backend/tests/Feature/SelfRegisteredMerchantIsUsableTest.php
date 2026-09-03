<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-SELFREG-USABLE-001 — **الحسابُ الذي يُنشئه صاحبُه بيده يجب أن
 * يَعمل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * ثلاثُ شكاوى وصلت من الاستعمال الحقيقيّ، وكلُّها على حسابٍ سجّل ذاتيّاً
 * من التطبيق (وهو المعالجُ الوحيد لكلّ القطاعات: عميل · تاجر · وكيل):
 *
 *   ١. **زرُّ الاعتماد في لوحة التحقّق لا يعمل.**
 *   ٢. **محفظةُ التاجر تقول «غير متاح الآن».**
 *   ٣. **صفحةُ المصروفات تقول «خطأ في الشبكة».**
 *
 * **ولا يمسك واحدةً منها اختبارٌ قائم**، لأنّ كلَّ اختبارات التاجر تصنع
 * المستخدمَ بـ`factory()` وتضبط بيدها ما ينقصه التسجيلُ الحقيقيّ. فالمسارُ
 * المختبَرُ ليس المسارَ الذي يسلكه المستعمل. (القاعدة الرابعة: ميزةٌ لها
 * مدخلان تُختبَر من مدخليها — وهذا مدخلٌ لم يُختبَر قطّ.)
 *
 * فهذا الحارسُ **يسجّل من نقطة النهاية نفسِها التي يناديها التطبيق**، ثمّ
 * يطرق الأبوابَ الثلاثة كما يطرقها.
 */
class SelfRegisteredMerchantIsUsableTest extends TestCase
{
    use RefreshDatabase;

    /** يسجّل تاجراً بنفس الحمولة التي يرسلها معالجُ التطبيق. */
    private function registerMerchant(): User
    {
        Storage::fake('local');

        $r = $this->post('/api/v1/customer/auth/register', [
            'f_name' => 'صادق',
            'l_name' => 'علي عبدالله ميطان',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => '777444999',
            'password' => '1234',
            'otp' => '123456',
            'identification_number' => '24549449',
            'identification_type' => 'passport',
            'date_of_birth' => '1990-01-01',
            'residence_governorate' => 'YE-AD',
            'origin_governorate' => 'YE-AD',
            'address' => 'عدن',
            'kin_name' => 'سالم',
            'kin_phone' => '777999699',
            'kin_relation' => 'أخ',
            'declaration_accepted' => '1',
            'account_type' => 'merchant',
            'store_name' => 'محطة بن ميطان',
            'business_type' => 'fuel',
            // AMIAL-SELFREG-KYCDOCS-001: مصنّفةٌ كما يرسلها المعالجُ الآن.
            'kyc_id_front' => UploadedFile::fake()->image('front.jpg', 600, 400),
            'kyc_id_back' => UploadedFile::fake()->image('back.jpg', 600, 400),
            'kyc_selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        ]);

        $r->assertStatus(200);

        $user = User::where('phone', 'like', '%777444999%')->first();
        $this->assertNotNull($user, 'لم يُنشأ الحسابُ أصلاً — الحارسُ يفحص فراغاً');

        return $user;
    }


    /**
     * مراجعٌ يملك `platform.approvals.decide` **فعلاً** — لا بالاسم.
     * فالدورُ وحدَه لا يمنح صلاحيّةً منصّيّة: تُقرأ من
     * `admin_user_roles → role_permissions → permissions`. وبلا هذا
     * يُردّ الطلبُ بـ٣٠٢ عند طبقة الصلاحيّة **قبل** أن يبلغ منطقَ
     * الاعتماد — فيُقاس فراغٌ ويُظَنّ عطلاً.
     */
    private function reviewer(): User
    {
        $admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'is_active' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('permissions')->updateOrInsert(
            ['code' => 'platform.approvals.decide'],
            ['label_ar' => 'اعتماد الطلبات', 'updated_at' => now(), 'created_at' => now()],
        );
        $permId = \Illuminate\Support\Facades\DB::table('permissions')
            ->where('code', 'platform.approvals.decide')->value('id');

        $roleId = \Illuminate\Support\Facades\DB::table('roles')->insertGetId([
            'code' => 'reviewer_'.uniqid(), 'label_ar' => 'مراجع',
            'is_system' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->insert(['role_id' => $roleId, 'permission_id' => $permId]);
        \Illuminate\Support\Facades\DB::table('admin_user_roles')
            ->insert(['user_id' => $admin->id, 'role_id' => $roleId]);

        return $admin;
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الدورُ يُضبط — وعليه تقوم ثلاثةَ عشرَ باباً.**
     *
     * `users.role` افتراضُه `'user'`، و**متحكّماتُ التاجر تحرس به**
     * (`$u->role !== A::ROLE_MERCHANT`). فتاجرٌ يُنشأ بـ`type=3` وحدَه
     * تُغلَق في وجهه: المصروفاتُ والولاءُ والأقساطُ وبطاقاتُ الهدايا
     * وطاقمُ الموظّفين ومفاتيحُ API والعملاتُ والتدقيقُ والنسخُ
     * الاحتياطيّ والعروضُ والحساباتُ المؤسّسيّة — **وكلُّها تردّ ٤٠٣**.
     */
    /** @test */
    public function a_self_registered_merchant_carries_the_merchant_role(): void
    {
        $user = $this->registerMerchant();

        $this->assertSame(MERCHANT_TYPE, (int) $user->type,
            'نوعُ الحساب ليس تاجراً');

        $this->assertSame(A::ROLE_MERCHANT, (string) $user->role,
            '**الدورُ لم يُضبط عند التسجيل** — فيبقى على `user` الافتراضيّ، '
            .'وتُغلق في وجه التاجر ثلاثةَ عشرَ متحكّماً تحرس بالدور: '
            .'المصروفاتُ والولاءُ والأقساطُ وبطاقاتُ الهدايا والطاقمُ وغيرُها.');
    }

    /**
     * **② وصفحةُ المصروفات تفتح.** (الشكوى الثالثة بنصّها.)
     *
     * والشاشةُ تبتلع كلَّ فشلٍ وتقول «خطأ في الشبكة» — فيُقرأ عطلُ صلاحيّةٍ
     * انقطاعَ إنترنت، ويُطارَد في المكان الخطأ.
     */
    /** @test */
    public function the_expenses_page_opens_for_a_self_registered_merchant(): void
    {
        $user = $this->registerMerchant();

        $r = $this->actingAs($user, 'api')->getJson('/api/v1/amial/merchant/expenses');

        $this->assertNotSame(403, $r->status(),
            '**المصروفاتُ تردّ ٤٠٣ «متاح للتجّار فقط» على تاجرٍ حقيقيّ** — '
            .'والشاشةُ تعرضها «خطأ في الشبكة».');

        // ٢٠٠ أو ٤٠٢ (مقفلةٌ بالباقة) كلاهما جوابٌ سليمٌ مفهوم؛ و٤٠٣ ليس.
        $this->assertContains($r->status(), [200, 402],
            'ردٌّ غيرُ مفهومٍ من المصروفات: '.$r->status());
    }

    /**
     * **③ ومحفظةُ المتجر تُعطي رصيداً.** (الشكوى الثانية.)
     *
     * الشاشةُ تكتب «غير متاح الآن» حين يعود الرصيدُ `null` — وهو **صوابٌ
     * في التصميم**: «غير معروف» ليس صفراً (القاعدة السابعة). فالعطلُ أن
     * يكون غيرَ معروفٍ أصلاً لمالكٍ يسأل عن محفظته.
     */
    /** @test */
    public function the_store_wallet_answers_with_a_balance(): void
    {
        $user = $this->registerMerchant();

        $r = $this->actingAs($user, 'api')->getJson('/api/v1/amial/merchant/daily-stats');

        $this->assertSame(200, $r->status(),
            '**محفظةُ المتجر لا تُجيب** — فتقرأ الشاشةُ «غير متاح الآن».');

        $meta = $r->json('meta') ?? [];
        $this->assertArrayHasKey('current_balance', $meta,
            '**الرصيدُ غائبٌ عن الردّ لمالك المتجر** — وحذفُه مقصورٌ على '
            .'موظّف نقطة البيع وحدَه (AMIAL-POS-SCOPE-001).');
    }

    /**
     * **④ وزرُّ الاعتماد في لوحة التحقّق يعمل.** (الشكوى الأولى.)
     *
     * التسجيلُ يحفظ صورَ الوثائق في `users.identification_image` (‏JSON)،
     * و`decideAccountVerification` يشترط صفوفاً في `kyc_documents` من
     * ثلاثة أنواع. **فهما نظامان لا يلتقيان**: الزرُّ يردّ ٤٢٢ «لا يُعتمد
     * الحسابُ قبل رفع هذه المستندات» على حسابٍ رفع وثائقَه فعلاً — ولا
     * سبيلَ في تلك الشاشة إلى رفعها.
     *
     * **ويُقاس بالأثر لا بالرسالة**: الحسابُ صار موثّقاً، وملفُّ التاجر
     * معه — وإلّا بقي القفلُ الماليُّ مطبقاً (AMIAL-MERCHANT-VERIFY-RECEIVE-001).
     */
    /** @test */
    public function registration_files_the_documents_the_reviewer_must_review(): void
    {
        $user = $this->registerMerchant();

        $types = \App\Models\KycDocument::where('user_id', $user->id)
            ->pluck('doc_type')->sort()->values()->all();

        $this->assertSame([
            \App\Models\KycDocument::TYPE_ID_BACK,
            \App\Models\KycDocument::TYPE_ID_FRONT,
            \App\Models\KycDocument::TYPE_SELFIE,
        ], $types,
            '**وثائقُ التسجيل لا تصير مستنداتٍ مصنّفة** — فطابورُ مراجعة '
            .'الهويّة يبقى فارغاً، ولا شيءَ يُراجَع، ولا حسابَ يُعتمَد أبداً. '
            .'(كانت تُحفظ في `users.identification_image` وحدَها.)');
    }

    /**
     * **④ والسلسلةُ كاملةً تُمشى: رفعٌ ← مراجعةٌ ← اعتماد.**
     *
     * وهذا هو المسارُ الصحيح: رفعُ العميل لا يوثّق نفسَه، فالمراجعُ يعتمد
     * المستنداتِ ثمّ يعتمد الحساب. **والعطلُ كان قبل أوّل خطوة** — لا
     * مستنداتٍ تُراجَع أصلاً.
     */
    /** @test */
    public function the_full_chain_registration_to_approval_completes(): void
    {
        $user = $this->registerMerchant();
        $reviewer = $this->reviewer();

        $svc = app(\App\Services\KycDocumentService::class);
        foreach (\App\Models\KycDocument::where('user_id', $user->id)->get() as $doc) {
            $svc->approve($doc, $reviewer);
        }

        $r = $this->actingAs($reviewer, 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1]);

        $this->assertSame(200, $r->status(),
            '**تعذّر اعتمادُ الحساب بعد اعتماد مستنداته**: '
            .((string) $r->json('message') ?: $r->status()));

        $this->assertSame(1, (int) $user->fresh()->is_kyc_verified,
            'قال «تمّ الاعتماد» ولم يُوثَّق الحساب');

        $this->assertSame('verified',
            MerchantProfile::where('user_id', $user->id)->value('verification_status'),
            'اعتُمد الحسابُ وبقي ملفُّ التاجر غيرَ موثّق — فالقبضُ الماليُّ يبقى مقفلاً');
    }
}
