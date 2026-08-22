<?php

namespace Tests\Feature;

use App\Services\AuditService;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-LEGACY-JSON-002
 *
 * قبل canonicalContext كانت البصمة تُحسب من ترتيب مفاتيح PHP، بينما
 * MySQL 8 قد يعيد ترتيب JSON عند التخزين. يجب أن نصنف هذه الحالة كتغير
 * تاريخي مفسر إذا أمكن إثبات نفس القيم، وألا نستخدمها لتبرير تغير قيمة.
 */
class AuditLegacyJsonMismatchGuardTest extends TestCase
{
    private function row(string $context): array
    {
        return [
            'decision_id' => '01M01XFDDDND324WE5SYA60B8P',
            'actor_type' => 'user',
            'actor_user_id' => 1,
            'subject_type' => 'donation',
            'subject_id' => '1',
            'action' => 'DONATION_COMPLETED',
            'decision_code' => 'OK',
            'reason' => null,
            'context' => $context,
            'transaction_id' => null,
            'zone_code' => null,
            'severity' => 'info',
        ];
    }

    /** @test */
    public function historical_mysql_key_reordering_is_explained_without_rewriting_the_hash(): void
    {
        // ترتيب PHP وقت إنشاء تبرع تاريخي.
        $written = '{"campaign_id":1,"org_id":1,"amount":"10000.0000","is_anonymous":false}';
        $storedHash = AuditService::computeEntryHash('PREVIOUS', $this->row($written), legacy: true);

        // نفس القيم بعد أن أعاد محرك JSON ترتيب المفاتيح.
        $readBack = '{"amount":"10000.0000","org_id":1,"campaign_id":1,"is_anonymous":false}';
        $row = $this->row($readBack);

        $this->assertFalse(
            AuditService::hashMatches('PREVIOUS', $row, $storedHash),
            'الاختبار يجب أن يحاكي السجل التاريخي الذي لا يطابق مباشرة'
        );

        $why = AuditService::explainMismatch('PREVIOUS', $row, $storedHash);

        $this->assertNotNull($why);
        $this->assertTrue($why['benign']);
        $this->assertSame('legacy_json_key_order', $why['code'] ?? null);
        $this->assertStringContainsString('القيم نفسها', $why['cause']);
    }

    /** @test */
    public function changing_a_value_is_never_excused_as_json_key_order(): void
    {
        $written = '{"campaign_id":1,"org_id":1,"amount":"10000.0000","is_anonymous":false}';
        $storedHash = AuditService::computeEntryHash('PREVIOUS', $this->row($written), legacy: true);

        $changed = '{"amount":"15000.0000","org_id":1,"campaign_id":1,"is_anonymous":false}';
        $why = AuditService::explainMismatch('PREVIOUS', $this->row($changed), $storedHash);

        $this->assertFalse(
            ($why['benign'] ?? false) && (($why['code'] ?? null) === 'legacy_json_key_order'),
            'تغيير قيمة مالية حقيقية صُنّف خطأً كاختلاف ترتيب JSON'
        );
    }

    /** @test */
    public function the_new_canonical_hash_is_independent_of_object_key_order(): void
    {
        $a = $this->row('{"campaign_id":1,"org_id":1,"amount":"10000.0000","is_anonymous":false}');
        $b = $this->row('{"amount":"10000.0000","is_anonymous":false,"org_id":1,"campaign_id":1}');

        $this->assertSame(
            AuditService::computeEntryHash('PREVIOUS', $a),
            AuditService::computeEntryHash('PREVIOUS', $b),
            'البصمات الجديدة يجب ألا تعتمد على ترتيب مفاتيح JSON'
        );
    }
}
