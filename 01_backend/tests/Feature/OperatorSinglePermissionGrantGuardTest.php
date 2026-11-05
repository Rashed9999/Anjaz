<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformTabAccessService;
use App\Support\PlatformAccessTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-GRAIN-002 — **صلاحيّةٌ واحدةٌ تُمنَح وحدَها، وتفتح ما لها
 * وحدَه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قاله صاحب المشروع أمام الشاشة، بنصّه:**
 *
 *     «ليس هذا ما أردته، أردتُ جعل الأمر أكثر تفصيل. مثلاً في الصورة
 *      الامتثال والمخاطر قد أحتاج إلى موظّف يرى **سجلّ التدقيق فقط** من
 *      هذه القائمة، بينما في الصلاحيات يمكنك فقط اختيار **القائمة
 *      كاملة**. ثمّ كتابةٌ وقراءةٌ هل تعمل حقّاً؟»
 *
 * وسؤالُه الأخير هو ما يقيسه هذا الحارس **بالتشغيل لا بالجدول**: يُمنح
 * موظّفٌ سجلَّ التدقيق وحدَه، ثمّ **يُفتح البابان**: بابُه فيُقبل، وبابُ
 * جاره فيُردّ.
 *
 * **ولا يُكتفى بقراءة الجدول**: منحٌ يُكتب صحيحاً في القاعدة ويُقرأ خطأً
 * في الوسيط يمرّ على كلّ فحصٍ للجدول. (القاعدة السادسة.)
 */
class OperatorSinglePermissionGrantGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $phone = '967770008801'): User
    {
        return User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'staff', 'phone' => $phone,
            'is_active' => 1,
        ]);
    }

    /**
     * @test
     *
     * **سجلُّ التدقيق وحدَه — ولا تُفتح معه مراجعةُ الهويّة.**
     */
    public function granting_only_the_audit_log_does_not_open_its_neighbours(): void
    {
        $operator = $this->operator();
        $granter = $this->operator('967770008802');

        app(PlatformTabAccessService::class)->sync(
            $operator, [], $granter->id, ['platform.audit.view']);

        $fresh = $operator->fresh();

        $this->assertTrue($fresh->hasPlatformPermission('platform.audit.view'),
            'لم يُمنح ما اختير — والمنحُ ردّ نجاحاً ولم يفعل شيئاً');

        // ══════════════════════════════════════════════════════════════
        // **وجاراه في التبويب نفسه يبقيان مغلقين.**
        //
        // وهذا هو الطلبُ بعينه: `compliance:read` كانت تمنح الثلاثةَ
        // دفعةً واحدة، فمن أراد مدقّقاً يقرأ السجلَّ **فتح له ملفّاتِ
        // العملاء والهويّاتِ معه**. وأقلُّ امتيازٍ ممكن مبدأٌ مكتوبٌ في
        // مهارة الصلاحيّات، وكانت الشاشةُ تمنعه.
        // ══════════════════════════════════════════════════════════════
        foreach ([
            'platform.customers.kyc.view' => 'مراجعة وثائق الهويّة',
            'platform.registrations.view' => 'ملفّات فتح الحسابات',
        ] as $neighbour => $label) {
            $this->assertFalse($fresh->hasPlatformPermission($neighbour), sprintf(
                '**فُتحت «%s» مع سجلّ التدقيق** — والمطلوبُ سجلٌّ وحدَه. '
                . 'وهو ما شُكي منه بالنصّ.', $label));
        }
    }

    /**
     * @test
     *
     * **والقراءةُ لا تفتح الكتابة — وهو جوابُ «هل تعمل حقّاً».**
     */
    public function a_read_grant_does_not_carry_a_write_permission(): void
    {
        $operator = $this->operator();
        $granter = $this->operator('967770008803');

        app(PlatformTabAccessService::class)->sync(
            $operator, [], $granter->id, ['platform.customers.kyc.view']);

        $fresh = $operator->fresh();

        $this->assertTrue($fresh->hasPlatformPermission('platform.customers.kyc.view'));

        $this->assertFalse($fresh->hasPlatformPermission('platform.approvals.decide'),
            '**قراءةُ الهويّة فتحت اعتمادَها** — فمن يراجع يعتمد بنفسه، '
            . 'وتسقط الرقابةُ التي بُنيت لفصلهما.');
    }

    /**
     * @test
     *
     * **ويُقاس بالباب لا بالجدول** — الوسيطُ يُسأل فعلاً.
     *
     * فمنحٌ يُكتب صحيحاً في القاعدة ويُقرأ خطأً في الوسيط يمرّ على كلّ
     * فحصٍ للجدول، ويظهر يومَ يضغط الموظّفُ الزرّ.
     */
    public function the_middleware_itself_honours_the_single_grant(): void
    {
        $operator = $this->operator();
        $granter = $this->operator('967770008804');

        app(PlatformTabAccessService::class)->sync(
            $operator, [], $granter->id, ['platform.audit.view']);

        $this->actingAs($operator->fresh(), 'user')
            ->get('/admin/amial/audit')
            ->assertSuccessful();

        $this->actingAs($operator->fresh(), 'user')
            ->get('/admin/amial/kyc')
            ->assertForbidden();
    }

    /**
     * @test
     *
     * **واختصارُ «الكلّ» يبقى — فحارسٌ يمنع منحاً سليماً عطل.**
     */
    public function granting_a_whole_group_still_works(): void
    {
        $operator = $this->operator();
        $granter = $this->operator('967770008805');

        app(PlatformTabAccessService::class)->sync(
            $operator, ['compliance' => ['read' => 1]], $granter->id);

        $fresh = $operator->fresh();

        foreach (array_keys(PlatformAccessTabs::all()['compliance']['read']) as $code) {
            $this->assertTrue($fresh->hasPlatformPermission($code),
                "اختصارُ المجموعة لم يمنح «{$code}» — فالمنحُ الجماعيُّ انكسر");
        }

        $this->assertFalse($fresh->hasPlatformPermission('platform.approvals.decide'),
            '«الكلّ قراءة» منح كتابةً — والقراءةُ لا تقرّر');
    }

    /**
     * @test
     *
     * **ورمزٌ لم تعرضه شاشةٌ قطّ يُردّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * النموذجُ يُعدَّل في المتصفّح، وقيمةُ `permissions[]` نصٌّ يُرسله
     * من يفتح أدوات المطوّر. **وكتابتُه كما جاء تصعيدُ امتيازٍ من حقلٍ
     * مخفيّ**: يمنح موظّفُ الدعم نفسَه `platform.money.move` بسطر.
     *
     * (القاعدة الثامنة: ما يأتي من المتصفّح يُفحَص، وما لا يُقبل من
     * الطلب لا يحتاج فحصاً بعدُ.)
     * ══════════════════════════════════════════════════════════════════
     */
    public function a_permission_no_screen_offers_is_refused(): void
    {
        $operator = $this->operator();
        $granter = $this->operator('967770008806');

        app(PlatformTabAccessService::class)->sync(
            $operator, [], $granter->id,
            ['platform.audit.view', 'platform.super.everything']);

        $fresh = $operator->fresh();

        $this->assertTrue($fresh->hasPlatformPermission('platform.audit.view'),
            'رُدَّ المنحُ كلُّه بسبب رمزٍ دخيل — والصحيحُ يُقبل والدخيلُ يُصفّى');

        $this->assertFalse($fresh->hasPlatformPermission('platform.super.everything'),
            '**كُتب رمزٌ لم تعرضه شاشةٌ قطّ** — فيُمنح ما لم يُصمَّم منحُه، '
            . 'وهو تصعيدُ امتيازٍ من حقلٍ مخفيّ.');
    }
}
