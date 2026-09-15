<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;

/**
 * سياق المنشأة الواحد لواجهة التاجر.
 *
 * لا ينشئ نظاماً مالياً أو مخزوناً جديداً: يجمع فقط مصادر الحقيقة الموجودة
 * (التاجر، ملف الاشتراك، الفاعل، جهاز POS) في عقد واحد. وبه لا تختار كل
 * شاشة اسمها أو نوعها أو باقتها من سجل مختلف.
 */
final class MerchantContextService
{
    /** @param array<string,mixed> $access ناتج FeatureAccessService::accessFor */
    public function for(User $user, array $access): array
    {
        $ownerId = (int) ($access['merchant_user_id'] ?? $user->id);
        $merchant = Merchant::query()->where('user_id', $ownerId)->first();
        $profile = MerchantProfile::query()->where('user_id', $ownerId)->first();
        $owner = $ownerId === (int) $user->id ? $user : User::query()->find($ownerId);

        $ownerName = trim((string) (($owner?->f_name ?? '') . ' ' . ($owner?->l_name ?? '')));
        $storeName = trim((string) ($merchant?->store_name ?? ''));
        $businessType = $access['business_type'] ?? $profile?->business_type;
        $plan = (string) ($access['subscription_plan'] ?? A::PLAN_FREE);
        $actor = (string) ($access['actor'] ?? 'customer');
        $pos = is_array($access['pos'] ?? null) ? $access['pos'] : null;

        return [
            'schema_version' => 1,
            'business' => [
                'merchant_id' => $merchant?->id,
                'owner_user_id' => $ownerId,
                // اسم المنشأة هو الهوية الوحيدة لواجهات المالك والموظف وPOS.
                'name' => $storeName !== '' ? $storeName : ($ownerName !== '' ? $ownerName : 'تاجر أميال باي'),
                'merchant_number' => $merchant?->merchant_number,
                'business_type' => $businessType,
                'business_type_label' => $businessType
                    ? (\App\Domain\Verticals\VerticalRegistry::labels()[$businessType] ?? $businessType)
                    : null,
            ],
            'subscription' => [
                'plan' => $plan,
                'label' => A::PLAN_LABELS[$plan] ?? $plan,
                'price_monthly_sar' => A::PLAN_PRICES_SAR[$plan] ?? 0,
                'expires_at' => $access['subscription_expires_at'] ?? null,
                'limits' => $access['limits'] ?? [],
            ],
            'actor' => [
                'kind' => $actor,
                'user_id' => $user->id,
                'is_owner' => $actor === 'owner',
                'display_name' => trim((string) (($user->f_name ?? '') . ' ' . ($user->l_name ?? ''))),
            ],
            // الجهاز منفصل عن الحساب؛ لا ينسب المال أو هوية المتجر للموظف.
            'device' => [
                'is_pos_session' => $actor === 'pos',
                'id' => $pos['id'] ?? null,
                'number' => $pos['pos_number'] ?? null,
                'label' => $pos['display_name'] ?? null,
            ],
            // التخزين UTC؛ كل الواجهات المالية تعرض مكة / 24 ساعة.
            'clock' => [
                'timezone' => config('amial.time.display_timezone', 'Asia/Riyadh'),
                'format' => 'HH:mm',
            ],
            // القرار التفصيلي للشاشات يبقى في /me/entitlements وCapabilityRegistry.
            'entitlements_endpoint' => '/api/v1/amial/me/entitlements',
        ];
    }
}
