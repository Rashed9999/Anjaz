<?php

namespace App\Models;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RECEIPTS-001
 */
class Receipt extends Model
{
    protected $table = 'receipts';

    protected $fillable = [
        'receipt_number',
        'verification_code',
        'receipt_type',
        'user_id',
        'counterparty_user_id',
        'reference_transaction_id',
        'reference_type',
        'reference_id',
        'amount',
        'fee',
        'net_amount',
        'direction',
        'status',
        'pdf_storage_path',
        'metadata',
        'zone_code',
        'issued_at',
        'pdf_generated_at',
        'download_count',
        'last_downloaded_at',
    ];

    /**
     * AMIAL-OP-STATUS-001 — نُلحِق حالة العملية الحقيقية بالمخرجات.
     * حالة العمود `status` تخصّ توليد ملفّ الـPDF فقط، لا العملية المالية.
     * والإيصال لا يُصدَر إلا بعد اكتمال العملية وترحيلها (post-commit)، لذا
     * فحالة العملية = «مكتملة» دائماً، إلا الملغى (voided) → «ملغية».
     *
     * AMIAL-TXN-NO-001 — و`transaction_no` رقم العملية الرسمي (انظر أدناه).
     */
    protected $appends = ['op_status', 'transaction_no'];

    protected $casts = [
        'user_id' => 'integer',
        'counterparty_user_id' => 'integer',
        'reference_id' => 'integer',
        'amount' => 'decimal:4',
        'fee' => 'decimal:4',
        'net_amount' => 'decimal:4',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
        'download_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * AMIAL-TXN-NO-001: رقم العملية الرسمي (15 خانة ببادئة حسب النوع) يُصدَّر
     * مع الإيصال. كان غائباً عن الـAPI فاضطرّ التطبيق لتوليد رقم مرجع محلّي
     * ملفّق — وهو خطأ في منتج مالي: الرقم المعروض يجب أن يكون رقم النظام نفسه.
     */
    public function getTransactionNoAttribute(): ?string
    {
        $ref = $this->reference_transaction_id;
        if (empty($ref)) {
            return null;
        }
        return Transaction::where('transaction_id', $ref)->value('transaction_no');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_user_id');
    }

    // Scopes

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }

    public function scopeReady(Builder $q): Builder
    {
        return $q->where('status', 'pdf_generated');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending_pdf', 'pdf_failed']);
    }

    /** يتم استدعاؤه من ReceiptController عند التحميل لتسجيل الإحصاء */
    public function incrementDownloadCount(): void
    {
        $this->update([
            'download_count' => $this->download_count + 1,
            'last_downloaded_at' => now(),
        ]);
    }

    /** هل الإيصال جاهز للتحميل؟ */
    public function isReady(): bool
    {
        return $this->status === 'pdf_generated' && !empty($this->pdf_storage_path);
    }

    /**
     * AMIAL-OP-STATUS-001 — حالة العملية المالية (لا حالة الـPDF).
     * القيم: completed | cancelled | under_review | pending
     * يقرؤها التطبيق ليعرض شارة موحّدة: مكتملة/ملغية/قيد المراجعة/قيد التحضير.
     */
    public function getOpStatusAttribute(): string
    {
        // صرّحت الحالة صراحةً في الميتاداتا؟ (سحب قيد المراجعة، طلب قيد التحضير…)
        $meta = is_array($this->metadata) ? $this->metadata : [];
        $declared = $meta['op_status'] ?? null;
        if (is_string($declared) && in_array($declared, ['completed', 'cancelled', 'under_review', 'pending'], true)) {
            return $declared;
        }

        return $this->status === 'voided' ? 'cancelled' : 'completed';
    }
}
