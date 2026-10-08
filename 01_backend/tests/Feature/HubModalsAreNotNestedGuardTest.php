<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-HUB-MODAL-NEST-001 — **`</div>` واحدٌ قتل تسعةَ أبواب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:** «كنت تدّعي سابقاً زرّ اعتماد
 * وتعديل يعمل. جرّبتهم لا يعملو».
 *
 * **وقِيس بمتصفّحٍ حقيقيّ لا بقراءة شيفرة:**
 *
 *   DIV#modal-edit.modal.fade  display=block  pos=fixed  w=0  h=0
 *   DIV#modal-add.modal.fade   display=none                  ← أبوه!
 *
 * `<div class="accordion" id="opening-dossier-sections">` فُتح في
 * `hub/users.blade.php` **ولم يُغلَق**. فابتلع `#modal-add` كلَّ ما
 * بعده: `#modal-transfer` و`#modal-edit` و`#modal-tx` صارت **أبناءً
 * له** — وهو مخفيٌّ ما لم يُفتَح، وابنُ المخفيّ لا يُرسَم مهما فُتح.
 *
 * فتُضغط «✏️ تعديل» فتُضاف `.show` إلى عنصرٍ **مقاسُه صفرٌ في صفر**:
 * لا نافذة، ولا زرَّ حفظ، **ولا خطأ في أيّ سجلّ ولا طلبَ يُردّ**. وهو
 * أخبثُ صور القاعدة التاسعة: الزرُّ يُضغَط ولا يحدث شيء.
 *
 * **وثلاثُ نوافذَ × ثلاثِ صفحاتٍ (عملاء · وكلاء · تجّار) = تسعةُ أبواب.**
 *
 * **ولمَ لم يمسكه ما هو قائم:** `click-check.mjs` يبني القالبَ في مِعمَلٍ
 * خاصٍّ به ويسأل «هل فُتحت النافذة؟» — أي **هل أُضيف الصنف `show`**.
 * وقد أُضيف فعلاً. **فالحارسُ كان يقيس الصنفَ لا المقاس.**
 *
 * فهذا يقيس **البنية**: أنّ نافذةً ليست ابنةَ نافذة. وهو ما لا يتغيّر
 * بمتصفّحٍ ولا بمقاسِ شاشة.
 */
class HubModalsAreNotNestedGuardTest extends TestCase
{
    use RefreshDatabase;

    /** النوافذُ التي ابتلعها `#modal-add`، وما يموت بموتِ كلٍّ منها. */
    private const MODALS = [
        'modal-transfer' => 'زرّ «تحويل رصيد / إعادة مبلغ»',
        'modal-edit' => 'زرّ «✏️ تعديل» — ومنه تُرفع الوثائق وتُحفظ البيانات',
        'modal-tx' => 'زرّ «التفاصيل / الحركات»',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009922',
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        $this->assertNotNull($roleId, 'دور «مدير المنصّة» غير موجود');

        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $this->admin->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * **① لا نافذةَ ابنةُ نافذة — في الصفحات الثلاث.**
     *
     * ويُقاس على **HTML المُصيَّر** لا على القالب: القالبُ فيه `@if`
     * تُغيّر البنيةَ بالصفحة، فعدُّ الأقواس في النصّ يمرّ حيث تسقط
     * الصفحة.
     */
    /** @test */
    public function no_hub_modal_is_nested_inside_another(): void
    {
        foreach (['customers', 'agents', 'merchants'] as $slug) {
            $html = $this->actingAs($this->admin)
                ->get("/admin/amial/hub/{$slug}")->assertOk()->getContent();

            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xp = new \DOMXPath($doc);

            foreach (self::MODALS as $id => $what) {
                $node = $xp->query("//*[@id='{$id}']")->item(0);

                $this->assertNotNull($node, "النافذة #{$id} غائبةٌ عن /{$slug}");

                for ($p = $node->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                    $classes = (string) $p->getAttribute('class');

                    $this->assertStringNotContainsString('modal', $classes,
                        "#{$id} ابنةُ نافذةٍ أخرى (#{$p->getAttribute('id')}) في /{$slug} — "
                        . "فتُفتح بمقاس صفرٍ في صفر ولا تُرى. ويموت بها {$what}.");
                }
            }
        }
    }

    /**
     * **② وعناصرُ الصفحة متوازنة** — والفرقُ واحدٌ يكفي لابتلاع الباقي.
     *
     * ويُقاس بمُحلِّل HTML لا بعدّ نصّيّ: المتصفّحُ يُصلح ما يستطيع
     * ويُعشّش ما لا يستطيع، **والتعشيشُ هو الضرر**.
     */
    /** @test */
    public function the_add_modal_closes_itself(): void
    {
        $html = $this->actingAs($this->admin)
            ->get('/admin/amial/hub/merchants')->assertOk()->getContent();

        $start = strpos($html, 'id="modal-add"');
        $next = strpos($html, 'id="modal-transfer"');

        $this->assertNotFalse($start);
        $this->assertNotFalse($next);

        $segment = substr($html, $start, $next - $start);
        $opens = preg_match_all('~<div\b~i', $segment);
        $closes = preg_match_all('~</div>~i', $segment);

        $this->assertSame($opens, $closes,
            "بين «#modal-add» و«#modal-transfer»: <div>={$opens} و</div>={$closes}. "
            . 'الفرقُ يجعل النوافذَ التاليةَ أبناءَ نافذةٍ مخفيّة.');
    }

    /**
     * **③ ولوحةُ التحقّق تُرسل محافظةَ السكن.**
     *
     * `kycStatus` يردّ ٤٢٢ (`MISSING_RESIDENCE_GOVERNORATE`) بلا محافظة،
     * **والنقطةُ تقبل `governorate` منذ بُنيت ولا مرسِلَ لها**. فيضغط
     * المراجعُ «اعتماد» فيُردّ، ولا يجد باباً يُصلحه منه: الرسالةُ تحيله
     * إلى «طابور مراجعة الهوية» بلا رابط.
     */
    /** @test */
    public function the_verification_screen_can_set_the_governorate(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/hub/verification.blade.php'));

        $this->assertStringContainsString('data-gov-for', $view,
            'لا مُنتقيَ محافظةٍ في بطاقة التحقّق — والاعتمادُ لا يتمّ بدونها');
        $this->assertStringContainsString('governorate: gov', $view,
            'المحافظةُ لا تُرسَل مع طلب الاعتماد');
    }

    /**
     * **④ والرفضُ يُقرأ على الصفحة لا في نافذة متصفّح.**
     *
     * قِيس: الضغطةُ تُخرج طلباً، والخادمُ يردّ ٤٢٢ برسالةٍ صحيحة،
     * **والرسالةُ تُسلَّم بـ`alert()` وحدَها**. ومن أشّر يوماً على «امنع
     * هذه الصفحة من إنشاء مربّعات حوار إضافية» — وهي خانةٌ يعرضها
     * المتصفّحُ بعد أوّل تنبيه — صار كلُّ رفضٍ صامتاً، ويُقرأ «الزرّ لا
     * يعمل».
     */
    /** @test */
    public function refusals_are_shown_on_the_page(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/hub/verification.blade.php'));

        $this->assertStringContainsString('verify-banner', $view,
            'لا لافتةَ في الصفحة — والرفضُ يذهب مع نافذة المتصفّح');

        // **ولا يُكتفى بوجود اللافتة**: بقاءُ `alert` في مسار القرار يعني
        // أنّ نصفَ الرسائل ما زال يذهب حيث لا يُرى.
        $code = preg_replace('~^\s*(//|/\*|\*).*$~m', '', $view);

        $this->assertStringNotContainsString('alert(j.message)', $code,
            'ما زال قرارُ الاعتماد يُبلَّغ بنافذة متصفّح');
        $this->assertStringNotContainsString('alert(err.message)', $code,
            'ما زال الرفضُ يُبلَّغ بنافذة متصفّح');
    }
}
