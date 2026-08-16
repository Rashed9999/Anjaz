<?php

namespace Tests\Feature;

use App\Services\AuditService;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-JSON-001 — **سلسلةُ التدقيق لا تتعلّق بمحرّك القاعدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — كشفته بوّابةُ GitHub في أوّل تشغيلٍ حقيقيٍّ لها:**
 *
 *   AdminCommandCenterGuardTest → «بصمةُ قرارٍ سليمٍ لا تطابق»
 *   SafePaymentAdminAuditTest   → amial:audit-verify يخرج بـ١ بدل ٠
 *
 * الاختباران يمرّان محلّيّاً على MariaDB ويسقطان في CI على MySQL 8.
 * **والفرقُ في نوع العمود لا في الشيفرة:**
 *
 *   `$table->json('context')`
 *     · MariaDB : مرادفٌ لـ`LONGTEXT` — النصُّ يُخزَّن حرفاً بحرف.
 *     · MySQL 8 : نوعٌ أصليّ — **يُعيد ترتيبَ المفاتيح ويحذف الفراغات**.
 *
 * والبصمةُ تُحسب **قبل** الحفظ من نصّ PHP وتُقارَن **بعد** القراءة من
 * نصّ المحرّك.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وليس عطلَ اختبار.** `docker-compose.prod.yml` يستعمل `mysql:8.0`،
 * وقاعدةُ الإنتاج على Coolify مزوّدُها `mysql`. فسلسلةُ التدقيق هناك
 * **تُبلّغ عن كلّ سجلٍّ سليمٍ أنّه معبوثٌ به** — وحارسٌ يصرخ على كلّ
 * شيءٍ لا يصدّقه أحدٌ حين يصرخ على الحقّ.
 *
 * **ولا يحتاج هذا الحارسُ MySQL ليكشفه:** يُحاكى ما يفعله المحرّكُ
 * (إعادةُ ترتيبٍ وحذفُ فراغات) على النصّ نفسِه. فيمسك العطلَ على أيّ
 * قاعدة — وهو الفرقُ بين حارسٍ يعمل وحارسٍ ينتظر بيئةً بعينها.
 */
class AuditHashPortabilityGuardTest extends TestCase
{
    /** @return array<string,mixed> صفٌّ نموذجيٌّ بسياقٍ متعدّد المفاتيح */
    private function row(string $context): array
    {
        return [
            'decision_id' => '01JABCDEF0123456789ABCDEFG',
            'actor_type' => 'admin',
            'actor_user_id' => 7,
            'subject_type' => 'user',
            'subject_id' => '42',
            'action' => 'SAFE_PAYMENT_ADMIN_RELEASE',
            'decision_code' => 'APPROVED',
            'reason' => 'أدلّة البائع كافية',
            'context' => $context,
            'transaction_id' => 'TX-1',
            'zone_code' => 'SOUTH',
            'severity' => 'notice',
        ];
    }

    /**
     * @test
     *
     * **ترتيبُ المفاتيح لا يغيّر البصمة.**
     *
     * وهو ما يفعله MySQL 8 بالضبط: يخزّن `{"b":1,"a":2}` فيعيدها
     * `{"a": 2, "b": 1}`.
     */
    public function key_order_does_not_change_the_hash(): void
    {
        $written = '{"zone":"SOUTH","amount":500,"ip":"10.0.0.1"}';
        $readBack = '{"amount": 500, "ip": "10.0.0.1", "zone": "SOUTH"}';

        $this->assertSame(
            AuditService::computeEntryHash('PREV', $this->row($written)),
            AuditService::computeEntryHash('PREV', $this->row($readBack)),
            'ترتيبُ مفاتيح JSON يغيّر البصمة — فكلُّ سجلٍّ سليمٍ على MySQL 8 '
            . 'يُقرأ «معبوثاً به»');
    }

    /**
     * @test
     *
     * **والتعشيقُ العميقُ كذلك** — المحرّكُ يرتّب كلَّ مستوى.
     */
    public function nested_key_order_does_not_change_the_hash_either(): void
    {
        $written = '{"b":{"y":2,"x":1},"a":[{"n":2,"m":1}]}';
        $readBack = '{"a": [{"m": 1, "n": 2}], "b": {"x": 1, "y": 2}}';

        $this->assertSame(
            AuditService::computeEntryHash('PREV', $this->row($written)),
            AuditService::computeEntryHash('PREV', $this->row($readBack)),
            'الترتيبُ في العمق يغيّر البصمة — والمحرّكُ يرتّب كلَّ مستوى');
    }

    /**
     * @test
     *
     * **والعبثُ يبقى مكشوفاً** — وهذا نصفُ الحارس الذي يُنسى.
     *
     * توحيدُ الصيغة يجب ألّا يُذيب فرقاً حقيقيّاً. فتغييرُ **قيمة** —
     * لا ترتيبِ مفتاح — يجب أن يكسر البصمة.
     */
    public function a_changed_value_still_breaks_the_hash(): void
    {
        $original = '{"amount":500,"zone":"SOUTH"}';
        $tampered = '{"amount":900,"zone":"SOUTH"}';

        $this->assertNotSame(
            AuditService::computeEntryHash('PREV', $this->row($original)),
            AuditService::computeEntryHash('PREV', $this->row($tampered)),
            'تغييرُ مبلغٍ لا يكسر البصمة — فالسلسلةُ لا تحرس شيئاً');
    }

    /**
     * @test
     *
     * **وتعديلُ حقلٍ خارج السياق يكسرها أيضاً.**
     */
    public function a_changed_field_outside_context_still_breaks_the_hash(): void
    {
        $a = $this->row('{"amount":500}');
        $b = $a;
        $b['reason'] = 'سببٌ آخرُ كُتب لاحقاً';

        $this->assertNotSame(
            AuditService::computeEntryHash('PREV', $a),
            AuditService::computeEntryHash('PREV', $b),
            'تعديلُ السبب بعد الكتابة لا يُكشف');
    }

    /**
     * @test
     *
     * **والسجلّاتُ القديمةُ تبقى مقبولة.**
     *
     * ما كُتب قبل هذا الإصلاح بُصم بالنصّ الخام. ورفضُه الآن يجعل كلَّ
     * تاريخٍ سابقٍ «معبوثاً به» — وهو العطلُ نفسُه مقلوباً.
     */
    public function records_written_before_the_fix_still_verify(): void
    {
        // بصمةٌ قديمةٌ: حُسبت بالنصّ الخام غيرِ المرتَّب.
        $raw = '{"zone":"SOUTH","amount":500}';
        $legacyHash = AuditService::computeEntryHash('PREV', $this->row($raw), legacy: true);

        $this->assertTrue(
            AuditService::hashMatches('PREV', $this->row($raw), $legacyHash),
            'سجلٌّ قديمٌ سليمٌ يُرفض — فيُقرأ التاريخُ كلُّه «معبوثاً به»');
    }

    /**
     * @test
     *
     * **ولا يُقبل العبثُ بحجّة التراجع.**
     *
     * التراجعُ إلى الصيغة القديمة بابٌ — فيُقاس أنّه لا يفتح للعبث.
     */
    public function the_legacy_fallback_does_not_excuse_tampering(): void
    {
        $original = $this->row('{"amount":500}');
        $hash = AuditService::computeEntryHash('PREV', $original);

        $tampered = $this->row('{"amount":900}');

        $this->assertFalse(
            AuditService::hashMatches('PREV', $tampered, $hash),
            'العبثُ يمرّ من باب التراجع — فالسلسلةُ بلا قيمة');
    }

    /**
     * @test
     *
     * **وسياقٌ ليس JSON صالحاً يُترك كما هو.**
     *
     * فلا تُغيَّر بصمةُ نصٍّ حرٍّ ولا تُكسَر سجلّاتٌ تحمله.
     */
    public function a_non_json_context_is_left_untouched(): void
    {
        $this->assertSame('نصٌّ حرٌّ ليس JSON',
            AuditService::canonicalContext('نصٌّ حرٌّ ليس JSON'));

        $this->assertSame('', AuditService::canonicalContext(null));
    }
}
