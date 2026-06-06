<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * P1-BRANCHES — حلّ الفرع النشط لكل request.
 *
 * مصادر الفرع بالأولوية:
 *   1. PosUser.branch_id (موظف ينتمي لفرع → فرعه فقط)
 *   2. Request header: X-Amial-Branch-ID (التاجر يختار)
 *   3. الفرع الافتراضي للتاجر
 *   4. null (الخطّة لا تدعم فروع)
 *
 * يتأكّد أنّ:
 *   - الفرع ينتمي للتاجر (security).
 *   - الفرع نشط.
 *   - POS user لا يستطيع تجاوز فرعه.
 */
class BranchResolverService
{
    /**
     * يحلّ الفرع للـ request الحالي.
     * يُرجع Branch | null (null = لا فروع، أو الخطّة لا تدعم).
     */
    public function resolve(Request $request, User $merchant, ?User $authUser = null): ?Branch
    {
        $authUser ??= $request->user();

        // 1) إن كان POS user → فرعه ثابت (security)
        if ($authUser) {
            $pos = PosUser::where('user_id', $authUser->id)
                ->where('merchant_user_id', $merchant->id)
                ->where('is_active', true)
                ->first();
            if ($pos && $pos->branch_id) {
                return Branch::where('id', $pos->branch_id)
                    ->where('merchant_user_id', $merchant->id)
                    ->where('is_active', true)
                    ->first();
            }
        }

        // 2) Header (التاجر/المدير العام يختار)
        $requestedId = $request->header('X-Amial-Branch-ID');
        if ($requestedId && is_numeric($requestedId)) {
            $branch = Branch::where('id', (int)$requestedId)
                ->where('merchant_user_id', $merchant->id)
                ->where('is_active', true)
                ->first();
            if ($branch) return $branch;
            // لو غير صالح، نتجاهل ونذهب للافتراضي
        }

        // 3) الفرع الافتراضي
        return Branch::where('merchant_user_id', $merchant->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * نسخة "أو يفشل": لـ endpoints التي تتطلّب فرعاً صراحةً.
     *
     * @throws \LogicException إن لم يوجد فرع.
     */
    public function resolveOrFail(Request $request, User $merchant, ?User $authUser = null): Branch
    {
        $branch = $this->resolve($request, $merchant, $authUser);
        if (!$branch) {
            throw new \LogicException(
                'لا يوجد فرع نشط — الخطّة الحالية لا تدعم الفروع أو لا فروع مُنشأة.'
            );
        }
        return $branch;
    }

    /**
     * يُرجع branch_id من resolve(). null = آمن (الكود الموجود يستمرّ).
     */
    public function resolveBranchId(Request $request, User $merchant, ?User $authUser = null): ?int
    {
        return $this->resolve($request, $merchant, $authUser)?->id;
    }
}
