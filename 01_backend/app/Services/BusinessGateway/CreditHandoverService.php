<?php

namespace App\Services\BusinessGateway;

use App\Exceptions\CreditManagedInAmialBusinessException;
use App\Models\BusinessGateway\BusinessLink;
use App\Models\CreditCollection;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-BUSINESS-CREDIT-001 — تسليم دفتر الآجل لأميال أعمال، وحارس التجميد.
 *
 * المشكلة التي يحلّها: بعد ترحيل التاجر تصير ديون عملائه وموردِيه في دفترين.
 * لو بقي أميال باي يقبل بيعاً آجلاً أو سداداً عليها لاختلف الدفتران، ولأمكن
 * أن يُحصَّل الدين نفسه مرتين (مرة هنا ومرة في أميال أعمال).
 *
 * الحلّ: لحظة واحدة يصير بعدها دفتر أميال أعمال هو الوحيد.
 *   - handover() يُنادى من أميال أعمال قبل أن تُثبّت استيرادها، ومعه بصمة
 *     الأرصدة التي قرأتها. البصمة تُحسب هنا تحت قفل الرابط؛ اختلافها يعني أن
 *     رصيداً تحرّك بعد القراءة فيُرفض التسليم.
 *   - assertOpen() يُنادى من نماذج الآجل الثلاثة (حركة دين العميل · رصيد عميل
 *     الجملة · رصيد المورد) قبل أي حفظ يغيّر رصيداً. هو باب واحد يمرّ به كل
 *     مسار، فلا يُنسى مسار جديد.
 *
 * القفل: الحارس يأخذ قفلاً مشتركاً على صف الرابط داخل معاملة الحركة، والتسليم
 * يأخذ قفلاً حصرياً. فإمّا تكتمل الحركة قبل التسليم (فتدخل في البصمة)، أو
 * تنتظر حتى يكتمل فتُرفض. لا حركة تمرّ بين البصمة والتجميد.
 */
class CreditHandoverService
{
    public function __construct(
        private readonly BusinessMigrationExport $export,
        private readonly AuditService $audit,
    ) {}

    public function isHandedOver(int $merchantUserId): bool
    {
        return $this->linkFor($merchantUserId, lock: false)?->creditHandedOver() ?? false;
    }

    /** @throws CreditManagedInAmialBusinessException */
    public function assertOpen(?int $merchantUserId): void
    {
        if (!$merchantUserId) {
            return;
        }

        if ($this->linkFor($merchantUserId, lock: DB::transactionLevel() > 0)?->creditHandedOver()) {
            throw new CreditManagedInAmialBusinessException();
        }
    }

    /**
     * @return array{handed_over_at: string, reference: string, replayed: bool}
     */
    public function handover(BusinessLink $link, string $reference, string $fingerprint): array
    {
        return DB::transaction(function () use ($link, $reference, $fingerprint) {
            $locked = BusinessLink::whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($locked->creditHandedOver()) {
                if ($locked->credit_handover_reference === $reference) {
                    return $this->result($locked, replayed: true);
                }
                throw new BusinessGatewayException(
                    'CREDIT_ALREADY_HANDED_OVER',
                    'سُلّمت ديون هذا التاجر لأميال أعمال من قبل بمرجع آخر',
                    409,
                );
            }

            if (!$locked->isActive()) {
                throw new BusinessGatewayException('BUSINESS_LINK_INACTIVE', 'الرابط غير نشط', 403);
            }

            // تحصيلٌ عبر أميال بدأ ولم يُقفل: إن دُفع بعد التجميد لن يجد دفتراً
            // يُخصم منه، فيبقى مال العميل مدفوعاً ودينه قائماً. يُحسم أولاً.
            $open = CreditCollection::where('merchant_user_id', $locked->merchant_user_id)
                ->whereIn('status', ['pending', 'review'])
                ->count();
            if ($open > 0) {
                throw new BusinessGatewayException(
                    'CREDIT_COLLECTIONS_OUTSTANDING',
                    "يوجد {$open} تحصيل آجل غير محسوم في أميال باي؛ أتمّه أو ألغِه قبل التسليم",
                    409,
                );
            }

            if (!hash_equals($this->export->creditFingerprint($locked), $fingerprint)) {
                throw new BusinessGatewayException(
                    'CREDIT_CHANGED_SINCE_EXPORT',
                    'تغيّر رصيد آجل بعد قراءة الترحيل؛ أعد المعاينة ثم التنفيذ',
                    409,
                );
            }

            $locked->update([
                'credit_handed_over_at' => now(),
                'credit_handover_reference' => $reference,
                'credit_handover_fingerprint' => $fingerprint,
            ]);

            $this->audit->record([
                'actor_type' => 'system',
                'actor_user_id' => $locked->merchant_user_id,
                'subject_type' => 'business_link',
                'subject_id' => $locked->link_ulid,
                'action' => 'BUSINESS_CREDIT_HANDED_OVER',
                'decision_code' => 'CREDIT_HANDOVER_OK',
                'reason' => 'Merchant credit ledger is now owned by Amial Business',
                'severity' => 'warning',
                'context' => ['reference' => $reference, 'fingerprint' => $fingerprint],
            ]);

            return $this->result($locked->fresh(), replayed: false);
        }, 3);
    }

    /**
     * إرجاع الآجل لأميال باي — للدعم فقط، حين فشل الترحيل في أميال أعمال قبل
     * أن يُسجَّل عليه شيء. لا يُنادى من القناة.
     */
    public function release(BusinessLink $link, string $reason, ?int $adminId): void
    {
        DB::transaction(function () use ($link, $reason, $adminId) {
            $locked = BusinessLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if (!$locked->creditHandedOver()) {
                return;
            }
            $previous = $locked->credit_handover_reference;
            $locked->update([
                'credit_handed_over_at' => null,
                'credit_handover_reference' => null,
                'credit_handover_fingerprint' => null,
            ]);
            $this->audit->record([
                'actor_type' => $adminId ? 'admin' : 'system',
                'actor_user_id' => $adminId ?? $locked->merchant_user_id,
                'subject_type' => 'business_link',
                'subject_id' => $locked->link_ulid,
                'action' => 'BUSINESS_CREDIT_RELEASED',
                'decision_code' => 'CREDIT_RELEASED',
                'reason' => $reason,
                'severity' => 'critical',
                'context' => ['previous_reference' => $previous],
            ]);
        });
    }

    private function linkFor(int $merchantUserId, bool $lock): ?BusinessLink
    {
        $query = BusinessLink::where('merchant_user_id', $merchantUserId);

        return ($lock ? $query->sharedLock() : $query)->first();
    }

    private function result(BusinessLink $link, bool $replayed): array
    {
        return [
            'handed_over_at' => $link->credit_handed_over_at->toIso8601String(),
            'reference' => (string) $link->credit_handover_reference,
            'replayed' => $replayed,
        ];
    }
}
