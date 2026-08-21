<?php

namespace App\Services;

use App\Models\Aml\AmlInvestigation;
use App\Models\EMoney;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فحص إغلاق الحساب وتنفيذه.
 *
 * الإغلاق ليس تغيير لونٍ في ملف المستخدم: لا يجوز إغلاق رصيد، مال محجوز،
 * طلب أموال حي، أو تحقيق AML مفتوح ثم الادعاء بأن الحساب سُوّي.
 */
class AccountClosureService
{
    /** @return array{allowed: bool, blockers: list<array{code:string,message:string}>} */
    public function preflight(User $customer): array
    {
        $blockers = [];
        $wallet = EMoney::where('user_id', $customer->id)->first();

        foreach ([
            'current_balance' => 'الرصيد الحالي ليس صفراً',
            'held_balance' => 'يوجد رصيد محجوز',
            'pending_balance' => 'يوجد رصيد معلّق',
        ] as $field => $message) {
            if ($wallet && bccomp((string) ($wallet->{$field} ?? '0'), '0', 4) !== 0) {
                $blockers[] = ['code' => 'WALLET_' . strtoupper($field), 'message' => $message];
            }
        }

        if (Schema::hasTable('payment_requests') && DB::table('payment_requests')
            ->where(fn ($q) => $q->where('requester_user_id', $customer->id)
                ->orWhere('recipient_user_id', $customer->id))
            ->whereIn('status', ['pending', 'accepted', 'processing'])->exists()) {
            $blockers[] = ['code' => 'PENDING_PAYMENT_REQUEST', 'message' => 'توجد طلبات أموال غير محسومة'];
        }

        if (Schema::hasTable('aml_investigations')
            && AmlInvestigation::where('subject_user_id', $customer->id)->open()->exists()) {
            $blockers[] = ['code' => 'OPEN_AML_CASE', 'message' => 'يوجد تحقيق مخاطر مفتوح'];
        }

        if (Schema::hasColumn('users', 'security_hold_until') && $customer->security_hold_until?->isFuture()) {
            $blockers[] = ['code' => 'LEGAL_OR_SECURITY_HOLD', 'message' => 'يوجد قيد أمني/قانوني فعّال'];
        }

        return ['allowed' => $blockers === [], 'blockers' => $blockers];
    }

    public function close(User $customer, string $reason): array
    {
        $check = $this->preflight($customer);
        if (! $check['allowed']) {
            throw new DomainException('CLOSURE_PREFLIGHT_FAILED: ' . implode('؛ ', array_column($check['blockers'], 'message')));
        }

        $customer->forceFill([
            'lifecycle_state' => 'closed',
            'lifecycle_changed_at' => now(),
            'lifecycle_reason' => mb_substr($reason, 0, 1000),
            'is_temp_blocked' => 1,
        ])->save();

        return ['message' => 'أُغلق الحساب بعد اجتياز فحص التسوية', 'context' => ['preflight' => 'passed']];
    }
}
