<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-RECON-CASE-001 — فرقٌ ماليٌّ له قضية، لا صفٌ أحمر يختفي عند التحديث.
 *
 * لا يُغلق هذا الكيان تلقائياً: اختفاء الفرق في تشغيلٍ واحد ينقله إلى
 * «verifying»، أمّا الإغلاق فيتطلب سبباً ومراجعاً وقيد تصحيح إن وُجد.
 */
class ReconciliationCase extends Model
{
    public const OPEN_STATUSES = [
        'detected', 'triaged', 'investigating', 'root_cause_found',
        'correction_pending', 'pending_approval', 'corrected', 'verifying',
    ];

    protected $fillable = [
        'case_ulid', 'case_type', 'source', 'subject_user_id', 'ledger_account_id',
        'branch_id', 'till_id', 'shift_id', 'expected_amount', 'actual_amount', 'difference',
        'currency', 'status', 'severity', 'first_detected_at', 'last_detected_at',
        'detection_count', 'assigned_team', 'assigned_user_id', 'root_cause',
        'evidence', 'linked_transaction_ids', 'linked_journal_ids',
        'linked_settlement_ids', 'linked_cash_movement_ids', 'action_taken',
        'maker_admin_id', 'checker_admin_id', 'resolution_journal_entry_id',
        'resolved_at',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:4',
        'actual_amount' => 'decimal:4',
        'difference' => 'decimal:4',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'detection_count' => 'integer',
        'evidence' => 'array',
        'linked_transaction_ids' => 'array',
        'linked_journal_ids' => 'array',
        'linked_settlement_ids' => 'array',
        'linked_cash_movement_ids' => 'array',
    ];

    /**
     * رمزُ حساب الدفتر الذي تخصّه القضيّة — **قابلاً للقراءة**.
     *
     * AMIAL-LEDGER-DRIFT-CASE-001 · والمحورُ ٢٤ من وثيقة مركز الدفتر
     * (`Human-readable Account Identity`): مراجعٌ يقرأ `#417` لا يعرف ما
     * الحساب، ويفتح ملفَّ كلّ رقمٍ ليعرف. **والرمزُ هنا أقلُّ كشفاً وأكثرُ
     * إفادة** — كما في `pendingQueue` لمستندات الهويّة.
     */
    public function ledgerAccountCode(): ?string
    {
        if ($this->ledger_account_id === null) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('ledger_accounts')
            ->where('id', $this->ledger_account_id)
            ->value('account_code');
    }
}
