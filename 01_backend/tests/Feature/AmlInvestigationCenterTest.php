<?php

namespace Tests\Feature;

use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlInvestigationEvent;
use App\Models\Aml\AmlRegulatoryReport;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AmlInvestigationService;
use App\Services\AmlRegulatoryReportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AML-INVESTIGATION-001 / AMIAL-AML-REGREPORT-001 —
 * الفصل ١٠ من الوثيقة: التبويبان ٧ و٨.
 *
 * **ما كان ناقصاً قبل هذا:** النظام يرصد ويعلّق ويُنبّه ثمّ يقف. لا قضيّة
 * لها رقمٌ وضابطٌ وأدلّة وقرار، ولا بلاغ للمنظّم.
 *
 * ومنصّةٌ ترصد ولا تُبلّغ تكون قد جمعت الدليل على المخالفة واحتفظت به
 * لنفسها — وهو أمام المنظّم أسوأ من ألّا ترصد أصلاً.
 */
class AmlInvestigationCenterTest extends TestCase
{
    use RefreshDatabase;

    private AmlInvestigationService $inv;
    private AmlRegulatoryReportService $rep;
    private User $officer;
    private User $secondOfficer;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inv = app(AmlInvestigationService::class);
        $this->rep = app(AmlRegulatoryReportService::class);

        $this->officer = User::factory()->create(['type' => 0, 'role' => 'super_admin', 'phone' => '967770008801']);
        $this->secondOfficer = User::factory()->create(['type' => 0, 'role' => 'super_admin', 'phone' => '967770008802']);

        $this->customer = User::factory()->create(['phone' => '770008803', 'zone_code' => 'SOUTH']);
        EMoney::updateOrCreate(['user_id' => $this->customer->id],
            ['current_balance' => '5000000', 'zone_code' => 'SOUTH']);
    }

    private function openCase(): AmlInvestigation
    {
        return $this->inv->open($this->customer->id, $this->officer);
    }

    // ── أرقام القضايا ───────────────────────────────────────────────────

    /** @test */
    public function case_numbers_are_sequential_not_random(): void
    {
        // المنظّم يشير إلى القضايا بأرقامها، والفجوة في التسلسل سؤالٌ يجب أن
        // يكون له جواب. ورمزٌ عشوائيّ يجعل «كم قضية فتحتم هذا العام؟» استعلاماً
        // بدل أن يُقرأ من آخر رقم.
        $a = $this->openCase();
        $b = $this->inv->open($this->customer->id, $this->officer);

        $year = now()->year;
        $this->assertSame("INV-{$year}-000001", $a->case_number);
        $this->assertSame("INV-{$year}-000002", $b->case_number);
    }

    /** @test */
    public function twenty_alerts_on_one_customer_do_not_become_twenty_files(): void
    {
        // النمط لا يُرى إن تفرّق على عشرين ملفّاً يُغلق كلٌّ منها على حدة.
        $ulid = str_repeat('A', 26);

        $first = $this->inv->open($this->customer->id, $this->officer, 'alert', $ulid);
        $again = $this->inv->open($this->customer->id, $this->officer, 'alert', $ulid);

        $this->assertSame($first->id, $again->id, 'فُتحت قضيّةٌ ثانية للمصدر نفسه');
        $this->assertSame(1, AmlInvestigation::count());
    }

    // ── الخطّ الزمنيّ ───────────────────────────────────────────────────

    /** @test */
    public function the_timeline_is_append_only_and_records_who_did_what(): void
    {
        $case = $this->openCase();
        $this->inv->addEvidence($case, $this->officer, 'كشف حساب الشهر يُظهر ١٤ تحويلاً متقارباً');

        $events = AmlInvestigationEvent::where('investigation_id', $case->id)->get();

        $this->assertCount(2, $events, 'الفتح والدليل كلاهما يجب أن يُسجَّل');
        $this->assertSame(AmlInvestigationEvent::TYPE_OPENED, $events[0]->event_type);
        $this->assertSame(AmlInvestigationEvent::TYPE_EVIDENCE, $events[1]->event_type);
        $this->assertSame($this->officer->id, (int) $events[1]->actor_user_id);

        // لا عمود تعديل: صفٌّ يُكتب مرّة. تحقيقٌ يمكن تحرير تاريخه لا يصلح دليلاً.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('aml_investigation_events', 'updated_at'),
            'الخطّ الزمنيّ قابل للتعديل — فلا يصلح دليلاً',
        );
    }

    /** @test */
    public function evidence_must_say_something(): void
    {
        // دليلٌ بسطرٍ غامض لا يفيد من يراجع القرار بعد سنة.
        $case = $this->openCase();

        $this->expectException(DomainException::class);
        $this->inv->addEvidence($case, $this->officer, 'شيء');
    }

    // ── فصل المهام ──────────────────────────────────────────────────────

    /** @test */
    public function nobody_investigates_a_case_opened_against_themselves(): void
    {
        // موظّفو المنصّة عملاء فيها ولهم محافظ وحدود. ومن يُسنَد إليه التحقيق
        // في نشاطه هو يكتب براءته بيده.
        $case = $this->inv->open($this->officer->id, $this->secondOfficer);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/FOUR_EYES_VIOLATION/');
        $this->inv->assign($case, $this->officer, $this->secondOfficer);
    }

    // ── الإغلاق ─────────────────────────────────────────────────────────

    /** @test */
    public function closing_requires_a_named_decision_and_a_reason(): void
    {
        // «أُغلقت» بلا قرارٍ مسمّى تعني أنّ أحداً لم يقرّر — وهو أسوأ من قرارٍ
        // خاطئ لأنّه لا يُراجَع ولا يُتعلَّم منه.
        $case = $this->openCase();

        try {
            $this->inv->close($case, $this->officer, 'حاجة_ما', 'سبب طويل بما يكفي ليتجاوز الحدّ الأدنى');
            $this->fail('قُبل قرارٌ غير معروف');
        } catch (DomainException $e) {
            $this->assertStringContainsString('قرار غير معروف', $e->getMessage());
        }

        // وسببٌ قصير يُرفض: هو ما يُراجَع لا القرار وحده.
        $this->expectException(DomainException::class);
        $this->inv->close($case, $this->officer, AmlInvestigation::DECISION_NO_ACTION, 'عادي');
    }

    /** @test */
    public function a_case_cannot_claim_an_str_was_filed_when_none_exists(): void
    {
        // هذا هو الضابط الذي يمنع أخطر ادّعاء في الملفّ: تُغلق القضية بأنّ
        // بلاغاً رُفع، ولا بلاغ في النظام — ويُكتشف ذلك يوم يطلبه المنظّم.
        $case = $this->openCase();

        try {
            $this->inv->close($case, $this->officer, AmlInvestigation::DECISION_STR_FILED,
                'نشاط مشبوه ورُفع بلاغ اشتباه إلى الجهة المختصّة');
            $this->fail('أُغلقت القضية بادّعاء بلاغٍ لا وجود له');
        } catch (DomainException $e) {
            $this->assertStringContainsString('لا بلاغ اشتباه', $e->getMessage());
        }

        // وبعد توليد البلاغ يُقبل القرار نفسه.
        $this->rep->generateStr($case, $this->officer,
            'تحويلات متقاربة متكرّرة خلال أسبوع من حساب حديث بلا نشاط تجاريّ معروف، '
            . 'ومجموعها يتجاوز حدّ العميل الشهريّ المعتاد بأضعاف.');

        $closed = $this->inv->close($case, $this->officer, AmlInvestigation::DECISION_STR_FILED,
            'نشاط مشبوه ورُفع بلاغ اشتباه إلى الجهة المختصّة');

        $this->assertSame(AmlInvestigation::STATUS_CLOSED, $closed->status);
        $this->assertSame(AmlInvestigation::DECISION_STR_FILED, $closed->decision);
    }

    /** @test */
    public function reopening_keeps_the_previous_decision_in_the_timeline(): void
    {
        // أن تُغلَق قضيّةٌ بـ«لا إجراء» ثمّ تُفتح ويُرفع بلاغ هو نفسه معلومةٌ
        // تخصّ المنظّم — ومحوُها يُخفي أنّ الحكم الأوّل كان خاطئاً.
        $case = $this->openCase();
        $this->inv->close($case, $this->officer, AmlInvestigation::DECISION_NO_ACTION,
            'راجعتُ الكشف ووجدتُ النشاط متوافقاً مع نشاط العميل التجاريّ');

        $reopened = $this->inv->reopen($case->fresh(), $this->secondOfficer,
            'ورد بلاغ جديد يناقض الخلاصة الأولى');

        $this->assertSame(AmlInvestigation::STATUS_REOPENED, $reopened->status);
        $this->assertNull($reopened->decision, 'بقي قرارٌ نافذ على قضيّةٍ أُعيد فتحها');

        $event = AmlInvestigationEvent::where('investigation_id', $case->id)
            ->where('event_type', AmlInvestigationEvent::TYPE_REOPENED)->first();

        $this->assertSame(AmlInvestigation::DECISION_NO_ACTION,
            $event->metadata['previous_decision'] ?? null,
            'ضاع القرار الأوّل — فلا يظهر أنّ الحكم السابق كان خاطئاً');
    }

    // ── إجراءات الامتثال ────────────────────────────────────────────────

    /** @test */
    public function a_compliance_action_actually_happens_it_is_not_only_logged(): void
    {
        // إجراءٌ يُكتب في الخطّ الزمنيّ ولا يقع أخطر من عدمه: من يقرأ الملفّ
        // يظنّ الحساب مجمَّداً وهو يعمل.
        $case = $this->openCase();

        $this->inv->takeAction($case, $this->officer, 'blacklist',
            'ثبت استعمال الحساب وسيطاً لتحويلات لا تخصّ صاحبه');

        $profile = AmlUserRiskProfile::find($this->customer->id);

        $this->assertNotNull($profile, 'لم يُنشأ ملفّ خطر للعميل');
        $this->assertSame('blacklist', $profile->manual_override,
            'سُجّل الإدراج في القائمة السوداء ولم يقع فعلاً');
    }

    /** @test */
    public function an_aml_kyc_request_uses_the_same_financial_restriction_as_support(): void
    {
        $this->customer->forceFill(['is_kyc_verified' => 1, 'kyc_tier' => 2])->save();
        $case = $this->openCase();

        $this->inv->takeAction($case, $this->officer, 'request_kyc',
            'توجد مؤشرات تستلزم إعادة التحقق من هوية صاحب الحساب');

        $fresh = $this->customer->fresh();
        $this->assertSame(0, (int) $fresh->is_kyc_verified);
        $this->assertSame(0, (int) $fresh->kyc_tier);
        $this->assertSame(1, (int) $fresh->kyc_update_required);
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $this->customer->id,
            'type' => 'kyc_update_required',
        ]);
    }

    // ── بلاغ الاشتباه ───────────────────────────────────────────────────

    /** @test */
    public function an_str_without_a_narrative_is_refused(): void
    {
        // بلاغٌ بلا سردٍ يشرح لماذا اشتُبه لا يقرؤه المنظّم إلّا كإشعارٍ فارغ،
        // ويُعاد إلينا طلباً للتفصيل.
        $case = $this->openCase();

        $this->expectException(DomainException::class);
        $this->rep->generateStr($case, $this->officer, 'مشبوه');
    }

    /** @test */
    public function the_str_freezes_the_timeline_as_it_was_when_filed(): void
    {
        // إعادةُ بناء المتن بعد سنة تُنتج نصّاً من بياناتٍ تغيّرت فلا يطابق ما
        // أُرسل. فيُحفَظ كاملاً وقت التوليد.
        $case = $this->openCase();
        $this->inv->addEvidence($case, $this->officer, 'أوّل ما وُجد: أربعة عشر تحويلاً متقارباً');

        $report = $this->rep->generateStr($case->fresh(), $this->officer,
            'حساب حديث تلقّى تحويلات متقاربة من أطراف غير مترابطة ثمّ أُخرجت نقداً '
            . 'خلال ساعات، بنمطٍ لا يتّفق مع نشاطه المُعلن.');

        $this->assertCount(2, $report->content['timeline'],
            'لم يُجمَّد الخطّ الزمنيّ في متن البلاغ');

        // وحدثٌ يُضاف بعد التوليد لا يغيّر ما أُرسل.
        $this->inv->addEvidence($case->fresh(), $this->officer, 'دليل أُضيف بعد توليد البلاغ');

        $this->assertCount(2, $report->fresh()->content['timeline'],
            'تغيّر متن البلاغ بعد توليده — فلن يطابق نسخة المنظّم');
    }

    // ── بلاغ العملة ─────────────────────────────────────────────────────

    /** @test */
    public function a_ctr_is_mandatory_above_the_threshold_and_meaningless_below_it(): void
    {
        // الفرق الجوهريّ عن بلاغ الاشتباه: هذا **غير تقديريّ**. ولا يوجد
        // مسارٌ لإلغائه — وإتاحةُ الإلغاء هي ما يجعل الحدّ بلا معنى.
        $threshold = $this->rep->ctrThreshold();

        $report = $this->rep->generateCtr($this->customer->id,
            bcadd($threshold, '1', 4), $this->officer);

        $this->assertSame(AmlRegulatoryReport::TYPE_CTR, $report->report_type);
        $this->assertSame(AmlRegulatoryReport::STATUS_PENDING, $report->status,
            'بلاغ العملة يُولَّد جاهزاً للإرسال لا مسودّة — لا شيء فيه يحتاج رأياً');

        $this->expectException(DomainException::class);
        $this->rep->generateCtr($this->customer->id, bcsub($threshold, '1', 4), $this->officer);
    }

    /** @test */
    public function one_transaction_is_reported_once_not_twice(): void
    {
        // بلاغان عن عمليةٍ واحدة يظهران لدى المنظّم كعمليتين — فيُضخَّم الحجم
        // المُبلَّغ بلا سبب.
        $ulid = str_repeat('B', 26);
        $amount = bcadd($this->rep->ctrThreshold(), '5000', 4);

        $a = $this->rep->generateCtr($this->customer->id, $amount, $this->officer, $ulid);
        $b = $this->rep->generateCtr($this->customer->id, $amount, $this->officer, $ulid);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, AmlRegulatoryReport::where('transaction_ulid', $ulid)->count());
    }

    /** @test */
    public function report_numbers_are_sequential_per_type(): void
    {
        $year = now()->year;
        $amount = bcadd($this->rep->ctrThreshold(), '1', 4);

        $c1 = $this->rep->generateCtr($this->customer->id, $amount, $this->officer, str_repeat('C', 26));
        $c2 = $this->rep->generateCtr($this->customer->id, $amount, $this->officer, str_repeat('D', 26));

        $case = $this->openCase();
        $s1 = $this->rep->generateStr($case, $this->officer,
            'نمط تحويلات متكرّر بمبالغ متقاربة من حساب حديث لا نشاط تجاريّ معروف له إطلاقاً.');

        $this->assertSame("CTR-{$year}-000001", $c1->report_number);
        $this->assertSame("CTR-{$year}-000002", $c2->report_number);
        // التسلسلان مستقلّان: خلطُهما يجعل «كم بلاغ اشتباه رفعتم؟» غير مقروء
        // من الرقم.
        $this->assertSame("STR-{$year}-000001", $s1->report_number);
    }

    // ── الإرسال ─────────────────────────────────────────────────────────

    /** @test */
    public function the_one_who_generated_a_report_cannot_confirm_its_submission(): void
    {
        // تأكيدُ الإرسال هو ما يُغلق الواجب القانونيّ. ومن يملك الخطوتين
        // يستطيع أن يُنهي الواجب على الورق بلا أن يُرسل شيئاً.
        $report = $this->rep->generateCtr($this->customer->id,
            bcadd($this->rep->ctrThreshold(), '1', 4), $this->officer);

        try {
            $this->rep->markSubmitted($report, $this->officer, 'REF-12345');
            $this->fail('أكّد المُولِّد إرسال بلاغه بنفسه');
        } catch (DomainException $e) {
            $this->assertStringContainsString('FOUR_EYES_VIOLATION', $e->getMessage());
        }

        $done = $this->rep->markSubmitted($report->fresh(), $this->secondOfficer, 'REF-12345');
        $this->assertSame(AmlRegulatoryReport::STATUS_SUBMITTED, $done->status);
        $this->assertSame($this->secondOfficer->id, (int) $done->submitted_by);
    }

    /** @test */
    public function submission_without_the_regulators_reference_is_refused(): void
    {
        // بلاغٌ «مُرسَل» بلا مرجعٍ من الجهة ادّعاءٌ لا إثبات — ويوم يُسأل
        // «متى أبلغتم؟» لا يكون في اليد ما يُبرَز.
        $report = $this->rep->generateCtr($this->customer->id,
            bcadd($this->rep->ctrThreshold(), '1', 4), $this->officer);

        $this->expectException(DomainException::class);
        $this->rep->markSubmitted($report, $this->secondOfficer, '');
    }

    /** @test */
    public function a_submitted_report_can_no_longer_be_changed(): void
    {
        // لدى المنظّم نسخة. وتعديل نسختنا يجعلهما تختلفان، فيصير سجلّنا
        // شهادةً على أنفسنا بأنّنا غيّرنا ما أرسلناه.
        $report = $this->rep->generateCtr($this->customer->id,
            bcadd($this->rep->ctrThreshold(), '1', 4), $this->officer);

        $this->rep->markSubmitted($report, $this->secondOfficer, 'REF-999');

        $this->expectException(DomainException::class);
        $this->rep->markSubmitted($report->fresh(), $this->officer, 'REF-OTHER');
    }

    /** @test */
    public function the_pending_summary_names_the_oldest_unsent_report(): void
    {
        // البلاغ المتأخّر مخالفةٌ بذاته في أغلب الأنظمة — فالعدد وحده لا يكفي.
        $this->rep->generateCtr($this->customer->id,
            bcadd($this->rep->ctrThreshold(), '1', 4), $this->officer, str_repeat('E', 26));

        $summary = $this->rep->pendingSummary();

        $this->assertSame(1, $summary['pending_total']);
        $this->assertSame(1, $summary['pending_ctr']);
        $this->assertNotNull($summary['oldest_pending_number'],
            'لا يُسمّى أقدم بلاغ لم يُرسَل — فالتأخير لا يُلاحَق');
    }
}
