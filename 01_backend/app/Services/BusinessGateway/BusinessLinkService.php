<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — إنشاء رابط التاجر بأميال أعمال وقراءة حالته.
 */
class BusinessLinkService
{
    public function __construct(
        private readonly BusinessOutbox $outbox,
        private readonly AuditService $audit,
    ) {}

    /**
     * يربط تاجراً موجوداً. متكرر الأمان: الربط الثاني يعيد الرابط نفسه ولا
     * يكتب حدثاً جديداً.
     */
    public function link(User $merchant, ?int $adminId = null): BusinessLink
    {
        if ((int) $merchant->type !== MERCHANT_TYPE) {
            throw new BusinessGatewayException('MERCHANT_REQUIRED', 'الحساب ليس حساب تاجر', 422);
        }

        $existing = BusinessLink::where('merchant_user_id', $merchant->id)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($merchant, $adminId) {
                $link = BusinessLink::create([
                    'link_ulid' => (string) Str::ulid(),
                    'merchant_user_id' => $merchant->id,
                    'status' => BusinessLink::STATUS_ACTIVE,
                    'created_by_admin_id' => $adminId,
                    'linked_at' => now(),
                    'status_changed_at' => now(),
                ]);

                // جهة اتصال المالك تُرسل في حدث الإنشاء وحده — لتصله دعوة تفعيل منشأته —
                // ولا تُعرض في GET /link.
                $this->outbox->record($link, 'business_link.created', $this->summary($link) + [
                    'owner' => [
                        'name' => trim(($merchant->f_name ?? '').' '.($merchant->l_name ?? '')),
                        'email' => $merchant->email,
                        'phone' => $merchant->phone,
                    ],
                ]);

                $this->audit->record([
                    'actor_type' => $adminId ? 'admin' : 'system',
                    'actor_user_id' => $adminId,
                    'subject_type' => 'business_link',
                    'subject_id' => $link->link_ulid,
                    'action' => 'BUSINESS_LINK_CREATED',
                    'decision_code' => 'LINK_OK',
                    'reason' => 'merchant linked to Amial Business',
                    'severity' => 'info',
                    'context' => ['merchant_user_id' => $merchant->id],
                ]);

                return $link;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // ربطٌ متزامن سبقني: أُعيد ما أنشأه.
            return BusinessLink::where('merchant_user_id', $merchant->id)->firstOrFail();
        }
    }

    /** ما تراه أميال أعمال عن التاجر — بلا أرصدة ولا بيانات هوية. */
    public function summary(BusinessLink $link): array
    {
        $merchant = User::find($link->merchant_user_id);
        $store = Merchant::where('user_id', $link->merchant_user_id)->first();
        $profile = MerchantProfile::where('user_id', $link->merchant_user_id)->first();

        return [
            'link_id' => $link->link_ulid,
            'link_status' => $link->status,
            'merchant_number' => $store?->merchant_number,
            'store_name' => $store?->store_name,
            'business_type' => $profile?->business_type,
            'verification_status' => $profile?->verification_status ?? 'unverified',
            'account_active' => (bool) ($merchant?->is_active ?? false),
            'can_receive_payments' => $this->canReceive($link),
            'wallet_currency' => (string) config('amial_business.wallet_currency', 'YER'),
        ];
    }

    /**
     * يقرّر هل يجوز إنشاء طلب دفع لهذا التاجر الآن. الحدود والامتثال تُفحص
     * لاحقاً عند الدفع نفسه في settle()؛ هذا فحص الأهلية الأساسية فقط.
     */
    public function assertCanReceive(BusinessLink $link): User
    {
        if (!$link->isActive()) {
            throw new BusinessGatewayException('BUSINESS_LINK_INACTIVE', 'ربط المنشأة بأميال باي موقوف', 403);
        }

        $merchant = User::find($link->merchant_user_id);
        if (!$merchant || (int) $merchant->type !== MERCHANT_TYPE) {
            throw new BusinessGatewayException('MERCHANT_NOT_FOUND', 'حساب التاجر غير موجود', 404);
        }
        if (!(bool) $merchant->is_active) {
            throw new BusinessGatewayException('ACCOUNT_RESTRICTED', 'حساب التاجر في أميال باي موقوف', 403);
        }

        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (($profile?->verification_status) !== 'verified') {
            throw new BusinessGatewayException('KYC_REQUIRED', 'لم يكتمل توثيق التاجر في أميال باي', 403);
        }

        return $merchant;
    }

    private function canReceive(BusinessLink $link): bool
    {
        try {
            $this->assertCanReceive($link);

            return true;
        } catch (BusinessGatewayException) {
            return false;
        }
    }
}
