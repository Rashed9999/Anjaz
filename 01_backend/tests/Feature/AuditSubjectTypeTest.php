<?php

namespace Tests\Feature;

use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-FIX(AUDIT-ENUM) — كانت subject_type/actor_type أعمدة enum ضيّقة بينما
 * الكود يكتب قيماً كثيرة خارجها (pending_transfer, customer…). على MySQL
 * strict كان الإدراج يفشل بـ«Data truncated» فينهار التحويل داخل معاملته.
 * هذه الاختبارات تثبّت العقد الجديد: أي قيمة معقولة تُحفظ كما هي.
 */
class AuditSubjectTypeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function persists_pending_transfer_subject_type_verbatim(): void
    {
        $id = app(AuditService::class)->record([
            'actor_type' => 'user',
            'actor_user_id' => 1,
            'subject_type' => 'pending_transfer',
            'subject_id' => '01KXR8ABFEZ67G1FBAMQZHM0WM',
            'action' => 'TRANSFER_INITIATED',
            'decision_code' => 'HOLDING',
            'severity' => 'info',
        ]);

        $this->assertNotNull($id);
        $row = DB::table('audit_decisions')->where('decision_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('pending_transfer', $row->subject_type);
    }

    /** @test */
    public function persists_customer_actor_type_verbatim(): void
    {
        $id = app(AuditService::class)->record([
            'actor_type' => 'customer',
            'actor_user_id' => 1,
            'subject_type' => 'safe_payment',
            'action' => 'WITHDRAW_REQUEST',
            'decision_code' => 'WITHDRAW_PENDING',
            'severity' => 'info',
        ]);

        $this->assertNotNull($id);
        $row = DB::table('audit_decisions')->where('decision_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('customer', $row->actor_type);
        $this->assertSame('safe_payment', $row->subject_type);
    }
}
