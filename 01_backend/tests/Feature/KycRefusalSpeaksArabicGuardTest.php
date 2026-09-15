<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-KYC-SAY-001 — **رفضٌ صحيحٌ بلغةِ آلة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** أرسل صاحبُ المشروع صورةَ نافذةٍ في لوحة الإدارة
 * تقول حرفيّاً:
 *
 *     رسالة من أميال باي
 *     KYC_DOCUMENTS_INCOMPLETE: national_id_front, national_id_back, selfie
 *
 * وسأل: **«هذا الزرّ لا يعمل، وإذا عمل هل يعمل بشكل صحيح؟»**
 *
 * **والجوابُ المقيس: الزرُّ عمل، والرفضُ أصاب** — الحسابُ ينقصه ثلاثةُ
 * مستندات، والمنعُ في محلّه. **وأخفق في أن يُفهِم**: رمزٌ إنجليزيٌّ
 * وأسماءُ حقولٍ خام، ولا كلمةَ عمّا يُفعَل.
 *
 * **والمعجمُ العربيُّ كان موجوداً ولا يُستعمَل** — `KycDocument::
 * TYPE_LABELS` فيه أسماءُ الخمسة كلِّها منذ كُتب الصنف. مبنيٌّ ولا
 * يُوصَل إليه، وهو نمطُ العطل الأكثرُ تكراراً في أميال باي.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ورمزٌ بلا ترجمةٍ يُعرَض خاماً ويُوسَم، ولا يُخترَع له معنى.**
 * فترجمةٌ مخترَعةٌ في شاشة امتثالٍ تُمرّر القارئَ واثقاً من معنىً لم
 * يقصده أحد؛ والرمزُ الخامُ يُوقفه ليسأل. (القاعدة السابعة.)
 */
class KycRefusalSpeaksArabicGuardTest extends TestCase
{
    use RefreshDatabase;

    private function refusal(): string
    {
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH', 'is_kyc_verified' => 0,
        ]);

        $reviewer = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770001111',
        ]);

        try {
            app(KycDocumentService::class)->decideAccountVerification($customer, $reviewer, true, 2, null);
        } catch (DomainException $e) {
            return $e->getMessage();
        }

        $this->fail('اعتُمد حسابٌ بلا مستندات — والمنعُ نفسُه سقط.');
    }

    /**
     * **① الرفضُ عربيٌّ، ولا رمزَ آلةٍ فيه.**
     */
    /** @test */
    public function the_refusal_carries_no_machine_code(): void
    {
        $msg = $this->refusal();

        // **الرمزُ يبقى علامةً في الذيل** — سبعةُ مواضعَ تعتمده، ونزعُه
        // كسر اثني عشرَ اختباراً. فالمفحوصُ ألّا يتصدّر الرسالةَ وحدَه،
        // وألّا تظهر أسماءُ المستندات خاماً.
        $this->assertStringStartsWith('لا يُعتمد', $msg,
            '**تتصدّر الرسالةَ لغةُ آلة** — والقارئُ إنسانٌ يعمل بالعربيّة.');

        foreach (['national_id_front', 'national_id_back', 'selfie'] as $code) {
            $this->assertStringNotContainsString($code, $msg, sprintf(
                "**رمزُ آلةٍ في وجه موظّفٍ يعمل بالعربيّة: «%s».**\n"
                .'والمعجمُ موجودٌ في `KycDocument::TYPE_LABELS` منذ كُتب '
                .'الصنف — مبنيٌّ ولا يُوصَل إليه.',
                $code));
        }
    }

    /**
     * **② ويسمّي الناقصَ بعينه — لا «الملفّ ناقص».**
     *
     * فرفضٌ مجملٌ يُرسل المراجعَ يبحث ويُنتج تذكرةَ دعمٍ لا إجراءً.
     */
    /** @test */
    public function it_names_each_missing_document_in_arabic(): void
    {
        $msg = $this->refusal();

        $missing = [];

        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE] as $code) {
            $label = KycDocument::TYPE_LABELS[$code];

            if (! str_contains($msg, $label)) {
                $missing[] = $label;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**مستنداتٌ ناقصةٌ لم تُسمَّ في الرفض:**\n  %s\n\nوالرسالةُ: %s",
            implode('، ', $missing), $msg));
    }

    /**
     * **③ ويقول ماذا يُفعَل — لا «لا يمكن» وحدَها.**
     *
     * فمن قرأ منعاً بلا مخرجٍ ضغط الزرَّ ثانيةً وثالثة.
     */
    /** @test */
    public function it_names_the_next_step(): void
    {
        $msg = $this->refusal();

        $this->assertStringContainsString('تعديل', $msg,
            '**قيل «لا يُعتمد» ولم يُقل «ارفعها من هنا»** — وبابٌ مغلقٌ '
            .'بلا مخرجٍ يُنتج اتّصالاً بالدعم لا إجراءً.');
    }

    /**
     * **④ ورمزٌ لا ترجمةَ له يُوسَم ولا يُخترَع له اسم.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا شرطُ صحّة العلاج كلِّه: معجمٌ يخترع أسماءً لما لا يعرفه أسوأ
     * من رمزٍ إنجليزيّ — الإنجليزيُّ يُوقف القارئَ ليسأل، والمخترَعُ
     * يُمرّره واثقاً من معنىً لم يقصده أحد.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function an_untranslated_code_is_shown_raw_and_flagged(): void
    {
        $svc = app(KycDocumentService::class);

        $m = new \ReflectionMethod($svc, 'sayMissing');
        $m->setAccessible(true);

        $out = $m->invoke($svc, 'MARK', 'ينقص', ['code_with_no_label'],
            KycDocument::TYPE_LABELS, 'افعل كذا.');

        $this->assertStringContainsString('code_with_no_label', $out,
            'رمزٌ مجهولٌ اختفى من الرسالة — فلا يعرف القارئُ ما ينقص أصلاً.');

        $this->assertStringContainsString('بلا ترجمة', $out,
            '**رمزٌ مجهولٌ عُرض بلا وسم** — فيُقرأ كأنّه اسمٌ مقصود.');
    }
}
