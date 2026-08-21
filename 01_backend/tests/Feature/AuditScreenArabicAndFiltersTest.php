<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\User;
use App\Services\AuditService;
use App\Support\AuditVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-ARABIC-001 — **سجلُّ التدقيق يُقرأ، ويُصفّى، ولا يكذب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** أرسل صاحبُ المشروع صورةَ الشاشة وقال: «اريد جعله
 * كله بالعربي و اضافة الفلاتر اللازمة و اضافة تفاصيل اكثر».
 *
 * وقِيست الشاشةُ قبل تعديلها فخرجت **أربعةُ أعطالٍ لا عطلُ ترجمة**:
 *
 *   ① `metadata` تُكتب في سبعةَ عشرَ موضعاً و`record()` تقرأ `context` —
 *      فكلُّ سياق الوكيل يُسقَط بصمت. السجلُّ يقول «فُتحت ورديّة» ولا
 *      يقول من ولا في أيّ فرعٍ ولا بكم عهدة.
 *   ② `transaction_id` مرشِّحٌ مبنيٌّ في المتحكّم **بلا حقلٍ في النموذج**
 *      — القاعدة الثانية عشرة: مبنيٌّ ولا يُوصَل إليه.
 *   ③ اللافتةُ الحمراء «عُبث بالسجلّ» على صفوفٍ **لم تُوقَّع قطّ**، لأنّ
 *      عمودَي البصمة أُضيفا بعد إنشاء الجدول بشهرٍ ونصف.
 *   ④ مرشِّحُ الفعل `like %…%` — فـ`hold` تلتقط `..._BY_HOLD`.
 */
class AuditScreenArabicAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function auditor(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);

        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الفقدُ الصامت
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function metadata_reaches_the_context_column_instead_of_vanishing(): void
    {
        app(AuditService::class)->record([
            'actor_type' => 'agent',
            'action' => 'agent.shift.open',
            'subject_type' => 'agent_shift',
            'subject_id' => '7',
            'metadata' => ['staff_id' => 3, 'branch_id' => 9, 'opening_float' => '5000.0000'],
        ]);

        $row = AuditDecision::where('action', 'agent.shift.open')->firstOrFail();
        $ctx = json_decode((string) $row->getRawOriginal('context'), true);

        $this->assertIsArray($ctx,
            'حمولةُ `metadata` أُسقطت — وهي سبعةَ عشرَ موضعاً في جانب الوكيل. '
            . 'فالسجلُّ يقول «فُتحت ورديّة» ولا يقول من ولا أين ولا بكم.');

        $this->assertSame(3, $ctx['staff_id'] ?? null);
        $this->assertSame(9, $ctx['branch_id'] ?? null);
        $this->assertSame('5000.0000', $ctx['opening_float'] ?? null);
    }

    /** @test */
    public function context_still_wins_when_both_names_are_passed(): void
    {
        app(AuditService::class)->record([
            'action' => 'TERMS_ACCEPTED',
            'context' => ['source' => 'context'],
            'metadata' => ['source' => 'metadata'],
        ]);

        $ctx = json_decode((string) AuditDecision::where('action', 'TERMS_ACCEPTED')
            ->firstOrFail()->getRawOriginal('context'), true);

        $this->assertSame('context', $ctx['source'] ?? null,
            'الاسمُ الأصليُّ يسبق المرادفَ — وإلّا انقلب المعنى على من كتب الاثنين.');
    }

    /** @test */
    public function an_unknown_payload_key_never_costs_the_whole_audit_row(): void
    {
        // **ولا يُرمى استثناءٌ على مفتاحٍ مجهول.** `record()` كلُّها داخل
        // `try`، فرميٌ يُبتلع في `catch` **فيُفقَد السطرُ كلُّه** — عقوبةٌ
        // على خطأٍ صغيرٍ بفقدِ الأثر نفسِه.
        $id = app(AuditService::class)->record([
            'action' => 'PIN_CHANGED',
            'subject_id' => '4',
            'this_key_does_not_exist' => 'شيءٌ ما',
        ]);

        $this->assertNotNull($id, 'مفتاحٌ مجهولٌ أسقط سطرَ التدقيق كلَّه');
        $this->assertDatabaseHas('audit_decisions', ['action' => 'PIN_CHANGED']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② المعجم — ولا يُخترَع معنى
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function known_codes_translate_and_unknown_ones_declare_themselves(): void
    {
        $known = V::action('CHARITY_SETTLEMENT_GENERATED');
        $this->assertTrue($known['translated']);
        $this->assertSame('أُنشئت تسويةُ جهةٍ خيريّة', $known['label']);
        $this->assertSame('charity', $known['domain']);

        // مركَّبٌ بالمقاطع
        $dotted = V::action('agent.teller.customer_viewed');
        $this->assertTrue($dotted['translated']);
        $this->assertStringContainsString('الوكيل', $dotted['label']);
        $this->assertStringContainsString('الشبّاك', $dotted['label']);

        // **والمجهولُ يُقال مجهولاً ولا يُخمَّن.**
        $unknown = V::action('SOME_FUTURE_CODE_XYZ');
        $this->assertFalse($unknown['translated'],
            'رمزٌ لا ترجمةَ له ادُّعيت له ترجمة — وترجمةٌ مخترَعةٌ في سجلّ '
            . 'تدقيقٍ أسوأ من رمزٍ إنجليزيّ: تُمرّر القارئَ واثقاً من معنىً '
            . 'لم يقصده أحد.');
        $this->assertSame('SOME_FUTURE_CODE_XYZ', $unknown['label']);
    }

    /** @test */
    public function a_dotted_code_with_one_unknown_segment_is_not_half_translated(): void
    {
        $partial = V::action('agent.shift.reopen_forced_by_supervisor');

        $this->assertFalse($partial['translated'],
            'رُكّبت ترجمةٌ جزئيّة — و«الوكيل ← الورديّة ← reopen_forced» '
            . 'تُقرأ ترجمةً كاملةً وليست كذلك.');
    }

    /** @test */
    public function an_observation_without_a_decision_is_not_called_unknown(): void
    {
        // `AuditService` تضع `UNKNOWN` حين لا يُمرَّر رمزُ قرار — وهي حالُ
        // كلِّ حدثٍ مرصود. و«مجهول» تُقرأ عطلاً وليست عطلاً.
        $this->assertSame('حدثٌ مرصود — لا قرار', V::decisionCode('UNKNOWN')['label']);
        $this->assertSame('حدثٌ مرصود — لا قرار', V::decisionCode(null)['label']);

        $this->assertSame('محجوب', V::decisionCode('TX_ZONE_BLOCKED')['label']);
        $this->assertSame('danger', V::decisionCode('TX_ZONE_BLOCKED')['tone']);
    }

    /** @test */
    public function severities_and_types_read_in_arabic(): void
    {
        $this->assertSame('حرِج', V::severity('critical')['label']);
        $this->assertSame('تحذير', V::severity('warning')['label']);
        $this->assertSame('دفعٌ إلكترونيّ', V::subjectType('e_payment'));
        $this->assertSame('حساب', V::subjectType('user'));
        $this->assertSame('وكيل', V::actorType('agent'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الشاشة
    // ══════════════════════════════════════════════════════════════════

    private function seedRow(array $over = []): AuditDecision
    {
        return AuditDecision::create($over + [
            'decision_id' => (string) \Illuminate\Support\Str::ulid(),
            'actor_type' => 'admin',
            'actor_user_id' => null,
            'subject_type' => 'user',
            'subject_id' => '1',
            'action' => 'WITHDRAW_APPROVED',
            'decision_code' => 'OK',
            'reason' => 'سببٌ ما',
            'severity' => 'critical',
            'created_at' => now(),
        ]);
    }

    /** @test */
    public function the_screen_shows_arabic_not_raw_codes(): void
    {
        $this->seedRow();

        $r = $this->actingAs($this->auditor(), 'user')->get(route('admin.amial.audit.index'));

        $r->assertOk();
        $r->assertSee('سجلّ تدقيق النظام', false);
        $r->assertSee('سحبٌ اعتُمد', false);
        $r->assertSee('حرِج', false);

        // ولا يبقى رمزٌ خامٌّ **وحدَه** في عمود الدرجة.
        $r->assertDontSee('>critical<', false);
    }

    /** @test */
    public function the_transaction_filter_finally_has_a_door(): void
    {
        // **مبنيٌّ ولا يُوصَل إليه** — `filtered()` تدعمه منذ كُتب، ولم يكن
        // له حقلٌ في النموذج ولا هو في `$filters` فيعود بعد الفلترة.
        // **والتمييزُ بنصّ السبب لا بتسمية الفعل.** فتسميةُ الفعل تظهر
        // أيضاً في قائمة المرشِّح المنسدلة — وهي تسرد كلَّ فعلٍ موجودٍ في
        // الجدول — فتأكيدٌ عليها يقرأ القائمةَ ويظنّها صفّاً.
        $this->seedRow(['transaction_id' => 'TX-ALPHA-1', 'reason' => 'صفُّ ألفا']);
        $this->seedRow(['transaction_id' => 'TX-BETA-2', 'action' => 'WITHDRAW_DENIED',
            'reason' => 'صفُّ بيتا']);

        $admin = $this->auditor();

        $form = $this->actingAs($admin, 'user')->get(route('admin.amial.audit.index'));
        $form->assertSee('data-testid="audit-tx-filter"', false);
        $form->assertSee('رقمُ المعاملة', false);

        $r = $this->actingAs($admin, 'user')
            ->get(route('admin.amial.audit.index', ['transaction_id' => 'TX-ALPHA-1']));

        $r->assertOk();
        $r->assertSee('صفُّ ألفا', false);
        $r->assertDontSee('صفُّ بيتا', false);
        // والقيمةُ تعود في الحقل — وإلّا بدا المرشِّحُ وكأنّه لم يعمل.
        $r->assertSee('value="TX-ALPHA-1"', false);
    }

    /** @test */
    public function the_action_filter_matches_exactly_and_does_not_swallow_neighbours(): void
    {
        $this->seedRow(['action' => 'hold', 'reason' => 'حجزٌ صريح']);
        $this->seedRow(['action' => 'TRANSACTION_BLOCKED_BY_HOLD',
            'decision_code' => 'BLOCKED', 'reason' => 'جارٌ لا يُلتقَط']);

        $r = $this->actingAs($this->auditor(), 'user')
            ->get(route('admin.amial.audit.index', ['action' => 'hold']));

        $r->assertOk();
        $r->assertSee('حجزٌ صريح', false);
        $r->assertDontSee('جارٌ لا يُلتقَط', false);
    }

    /** @test */
    public function an_unknown_domain_returns_nothing_rather_than_everything(): void
    {
        $this->seedRow();

        $r = $this->actingAs($this->auditor(), 'user')
            ->get(route('admin.amial.audit.index', ['domain' => 'لا-مجالَ-بهذا-الاسم']));

        $r->assertOk();
        // **تسريبُ نطاقٍ بصمتٍ** هو ما وقع في بحث الدفتر من قبل: مرشِّحٌ
        // يُسقَط فيُعرَض الجدولُ كلُّه، ومن يقرأ يظنّ أنّ بحثَه عمل.
        $r->assertDontSee('سببٌ ما', false);
        $r->assertSee('لا قرارات تطابق المرشِّحات', false);
    }

    /** @test */
    public function the_free_search_finds_a_word_in_the_reason(): void
    {
        $this->seedRow(['reason' => 'خارج النطاق المسموح']);
        $this->seedRow(['action' => 'WITHDRAW_DENIED', 'reason' => 'رصيدٌ غيرُ كافٍ لهذا']);

        $r = $this->actingAs($this->auditor(), 'user')
            ->get(route('admin.amial.audit.index', ['q' => 'خارج النطاق']));

        $r->assertOk();
        $r->assertSee('خارج النطاق المسموح', false);
        $r->assertDontSee('رصيدٌ غيرُ كافٍ لهذا', false);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ السلسلة — «لم يُوقَّع» ليست «عُبث به»
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function rows_older_than_the_chain_are_not_reported_as_tampering(): void
    {
        // صفوفٌ كُتبت قبل هجرة `2026_07_05_120000` — عمودا البصمة `nullable`
        // فهما فارغان. والحسابُ القديم كان يعدّ كلَّ واحدٍ منها **مكسوراً
        // مرّتين**، فتخرج اللافتةُ الحمراء على سجلٍّ لم يمسّه أحد.
        for ($i = 0; $i < 5; $i++) {
            $this->seedRow(['prev_hash' => null, 'entry_hash' => null]);
        }

        $r = $this->actingAs($this->auditor(), 'user')->get(route('admin.amial.audit.index'));

        $r->assertOk();
        $r->assertDontSee('عُبث بالسجلّ', false);
        $r->assertSee('لا عبثَ', false);
        $r->assertSee('قبل إنشاء السلسلة', false);
    }

    /** @test */
    public function a_row_edited_after_it_was_written_is_still_caught(): void
    {
        // **وهذا شرطُ صحّة التصحيح كلِّه**: لو اكتفينا بإسكات اللافتة على
        // غير الموقَّع لصار العبثُ الحقيقيُّ صامتاً معه.
        app(AuditService::class)->record([
            'action' => 'ADMIN_WALLET_TRANSFER',
            'decision_code' => 'OK',
            'reason' => 'مبلغٌ صغير',
            'subject_id' => '1',
        ]);

        $row = AuditDecision::firstOrFail();

        // تعديلٌ مباشرٌ في القاعدة — يتجاوز حارسَ append-only في النموذج،
        // وهو بالضبط ما تُبنى السلسلةُ لكشفه.
        DB::table('audit_decisions')->where('id', $row->id)
            ->update(['reason' => 'مبلغٌ كبيرٌ جدّاً']);

        $r = $this->actingAs($this->auditor(), 'user')->get(route('admin.amial.audit.index'));

        $r->assertOk();
        $r->assertSee('عُبث بالسجلّ', false);
        $r->assertSee('ولا تفسيرَ تقنيّاً له', false);
    }

    /** @test */
    public function a_column_our_own_migration_rewrote_is_not_called_tampering(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **الثمن الذي دُفع:** فتح صاحبُ المشروع الشاشةَ فرأى «عُبث
        // بالسجلّ — ٤٢ موضعاً» وسأل عمّا يفعل. و«البصمةُ لا تطابق» تقول
        // إنّ **شيئاً** تغيّر ولا تقول **ماذا** — فيقف القارئُ بين
        // جريمةٍ داخليّةٍ وهجرةٍ من هجراتنا، ولا سبيلَ له بينهما.
        //
        // و`down()` في هجرة `2026_07_05_110000` تكتب `subject_type` =
        // `'user'` على كلّ صفٍّ خارج التعداد — أي أنّ **تراجُعَ هجراتٍ
        // على خادمِ تجربةٍ يُعيد كتابةَ عمودٍ مبصومٍ عليه**.
        // ══════════════════════════════════════════════════════════════
        app(AuditService::class)->record([
            'action' => 'DONATION_COMPLETED',
            'decision_code' => 'COMPLETED',
            'subject_type' => 'donation',
            'subject_id' => '55',
            'reason' => 'تبرّعٌ اكتمل',
        ]);

        DB::table('audit_decisions')->update(['subject_type' => 'user']);

        $r = $this->actingAs($this->auditor(), 'user')->get(route('admin.amial.audit.index'));

        $r->assertOk();
        $r->assertDontSee('عُبث بالسجلّ', false);
        $r->assertSee('والسببُ معروفٌ وليس عبثاً', false);
        $r->assertSee('تراجُع هجرة التعداد', false);
    }

    /** @test */
    public function an_unexplainable_change_is_still_raised_as_tampering(): void
    {
        // **وهذا شرطُ صحّة التفسير كلِّه.** لو ابتلع المفسِّرُ كلَّ اختلافٍ
        // لصار كلُّ عبثٍ «هجرةً» — وحارسٌ يفسّر كلَّ شيءٍ لا يحرس شيئاً.
        app(AuditService::class)->record([
            'action' => 'ADMIN_WALLET_TRANSFER',
            'decision_code' => 'OK',
            'subject_type' => 'user',
            'subject_id' => '3',
            'reason' => 'تحويلٌ بمئة',
        ]);

        DB::table('audit_decisions')->update(['reason' => 'تحويلٌ بمليون']);

        $r = $this->actingAs($this->auditor(), 'user')->get(route('admin.amial.audit.index'));

        $r->assertOk();
        $r->assertSee('عُبث بالسجلّ', false);
        $r->assertSee('ولا تفسيرَ تقنيّاً له', false);
    }

    /** @test */
    public function the_suspicious_only_filter_shows_the_tampered_row_alone(): void
    {
        app(AuditService::class)->record([
            'action' => 'WITHDRAW_APPROVED', 'decision_code' => 'OK', 'subject_id' => '1',
        ]);
        app(AuditService::class)->record([
            'action' => 'WITHDRAW_DENIED', 'decision_code' => 'DENIED', 'subject_id' => '2',
        ]);

        $victim = AuditDecision::where('action', 'WITHDRAW_APPROVED')->firstOrFail();

        DB::table('audit_decisions')->where('id', $victim->id)
            ->update(['reason' => 'غُيّر']);

        $r = $this->actingAs($this->auditor(), 'user')
            ->get(route('admin.amial.audit.index', ['integrity' => 'broken']));

        $r->assertOk();
        $r->assertSee('سحبٌ اعتُمد', false);
        // **ويُقال إنّ المرشِّح على نافذة** — لا يُترك يبدو شاملاً.
        $r->assertSee('قرارٍ مفحوصٍ فقط', false);
    }

    /** @test */
    public function an_empty_log_is_not_called_healthy(): void
    {
        // القاعدة السابعة: «غير معروف» ليس صفراً، وجدولٌ فارغٌ ليس «سليماً».
        $r = $this->actingAs($this->auditor(), 'user')->get(route('admin.amial.audit.index'));

        $r->assertOk();
        $r->assertSee('لا قرارات لتُفحص', false);
        $r->assertDontSee('سلسلةُ التدقيق سليمة', false);
    }

    /** @test */
    public function a_real_decision_carries_a_real_code_not_unknown(): void
    {
        // **عمودُ «القرار» كان فارغَ المعنى على كلّ صفٍّ من جانب الوكيل.**
        //
        // ولا يُمرَّر رمزٌ في أيٍّ من نداءاتها، فتضع `AuditService` قيمتَها
        // الافتراضيّة `UNKNOWN`. وهي صادقةٌ على حدثٍ مرصود («عُرضت بطاقةُ
        // عميل» ليس قبولاً ولا رفضاً) — **وكاذبةٌ على قرار**: اعتمادُ
        // تسويةٍ يوميّةٍ قرارٌ بمالٍ يُقفَل، وهو ما يُبحث عنه في التحقيق.
        $decisionSites = [
            'app/Services/AgentDailySettlementService.php' => [
                "'action' => 'agent.daily_settlement.accept'" => "'decision_code' => 'ACCEPTED'",
                "'action' => 'agent.daily_settlement.reject'" => "'decision_code' => 'REJECTED'",
            ],
            'app/Services/AgentTellerRequestService.php' => [
                "'action' => 'agent.teller_request.'" => "'decision_code' => \$approve ? 'APPROVED' : 'REJECTED'",
            ],
        ];

        foreach ($decisionSites as $file => $pairs) {
            // **تُنزع أسطرُ التعليق قبل البحث.** فنافذةٌ ثابتةٌ من المحارف
            // يبتلعها تعليقٌ عربيٌّ من ثلاثة أسطر، فيسقط الحارسُ على
            // شيفرةٍ سليمة — وحارسٌ يسقط على الصواب يُحذَف بعد أسبوع.
            $src = preg_replace('~^\s*//.*$~m', '',
                (string) file_get_contents(base_path($file)));

            foreach ($pairs as $action => $expected) {
                $at = strpos($src, $action);

                $this->assertNotFalse($at, "لم يُعثر على {$action} في {$file}");

                $window = substr($src, $at, 260);

                $this->assertStringContainsString($expected, $window,
                    "قرارٌ حقيقيٌّ في {$file} يُكتب بلا رمزِ قرار، فيُقرأ في "
                    . 'الشاشة «حدثٌ مرصود — لا قرار» وهو قرارٌ بمالٍ يُقفَل.');
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ التصدير
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_export_carries_arabic_and_the_code_together(): void
    {
        $this->seedRow(['transaction_id' => 'TX-9']);

        $csv = $this->actingAs($this->auditor(), 'user')
            ->get(route('admin.amial.audit.export'))->streamedContent();

        // العربيّةُ وحدَها لا تُبحث في نظامٍ آخر، والرمزُ وحدَه لا يقرؤه
        // من يفتح الملفّ. والمدقّقُ الخارجيُّ لا يعرف رموزنا.
        $this->assertStringContainsString('سحبٌ اعتُمد', $csv);
        $this->assertStringContainsString('WITHDRAW_APPROVED', $csv);
        $this->assertStringContainsString('حرِج', $csv);
        $this->assertStringContainsString('TX-9', $csv);
    }

    /** @test */
    public function the_export_obeys_the_filters_on_screen(): void
    {
        $this->seedRow(['transaction_id' => 'TX-KEEP']);
        $this->seedRow(['transaction_id' => 'TX-DROP', 'action' => 'WITHDRAW_DENIED']);

        $csv = $this->actingAs($this->auditor(), 'user')
            ->get(route('admin.amial.audit.export', ['transaction_id' => 'TX-KEEP']))
            ->streamedContent();

        $this->assertStringContainsString('TX-KEEP', $csv);
        $this->assertStringNotContainsString('TX-DROP', $csv);
    }
}
