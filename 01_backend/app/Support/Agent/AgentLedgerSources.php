<?php

namespace App\Support\Agent;

use App\Models\WithdrawalRequest;

/**
 * AGT-GAP-001 — مصدرُ الحقيقة الوحيد لأنواع قيود شباك الوكيل.
 *
 * السحبُ الحيّ برمز العميل يُرحَّل بـ`cash_out_executed` ومرجعُه رقمُ الطلب، لا
 * `agent_withdraw` (المسارُ المغلق 410) ومرجعُه رمزُ العملية. فكانت محرّكاتُ
 * التسوية والتقارير الخمسة تبحث عن النوع الميّت وحده فلا ترى أيَّ سحبٍ حيّ.
 * وهنا يُجمَع النوعان ويُترجَم رمزُ العملية إلى رقم الطلب فيُقرأ القيدان معاً.
 */
final class AgentLedgerSources
{
    public const DEPOSIT = 'agent_deposit';

    public const LEGACY_WITHDRAW = 'agent_withdraw';

    public const LIVE_WITHDRAW = 'cash_out_executed';

    /** كلُّ أنواع قيود الإيداع والسحب في الشباك. */
    public const ALL = [self::DEPOSIT, self::LEGACY_WITHDRAW, self::LIVE_WITHDRAW];

    public const WITHDRAWALS = [self::LEGACY_WITHDRAW, self::LIVE_WITHDRAW];

    public static function isWithdraw(?string $sourceType): bool
    {
        return in_array($sourceType, self::WITHDRAWALS, true);
    }

    /**
     * يوسّع مراجعَ الحركات (رمزُ العملية) بأرقام الطلبات المقابلة لها، فيطابق
     * `whereIn('source_id', …)` القيدَ القديمَ (رمز) والحيَّ (رقم الطلب) معاً.
     *
     * @param  array<int, string>  $references
     * @return array<int, string>
     */
    public static function expandReferences(array $references): array
    {
        $references = array_values(array_unique(array_filter(array_map('strval', $references))));

        if ($references === []) {
            return [];
        }

        $ids = WithdrawalRequest::whereIn('op_code', $references)->pluck('id')
            ->map(fn ($id) => (string) $id)->all();

        return array_values(array_unique(array_merge($references, $ids)));
    }
}
