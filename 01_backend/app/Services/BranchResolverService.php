<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Merchant\PosDevice;
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
     * نطاق التشغيل المالي للكاشير والوردية.
     *
     * `resolve()` أعلاه متسامح عمداً مع شاشات القراءة القديمة. أمّا البيع
     * ودرج النقد فلا يحتملان التخمين: فرعٌ مزوّر لا يعود إلى الافتراضي
     * صامتاً، وموظف POS لا يختار فرعاً غير الفرع المسند إليه.
     *
     * null مسموح فقط لمنشأة قديمة لا تملك أي فرع بعد (تشغيل فرع واحد).
     * فور وجود فرع، يصبح النطاق حقيقة إلزامية لكل حركة تشغيلية جديدة.
     *
     * @throws \LogicException
     */
    public function resolveOperational(Request $request, User $merchant, ?PosUser $pos = null): ?Branch
    {
        $active = Branch::where('merchant_user_id', $merchant->id)
            ->where('is_active', true);
        $activeCount = (clone $active)->count();

        if ($pos !== null) {
            if ($pos->merchant_user_id !== $merchant->id || ! $pos->is_active) {
                throw new \LogicException('موظف نقطة البيع غير صالح لهذه المنشأة.');
            }
            if ($activeCount === 0) {
                return null; // منشأة قديمة أحادية الفرع، بلا تخمينٍ لفرع غير موجود.
            }
            if (! $pos->branch_id) {
                throw new \LogicException('موظف نقطة البيع غير مسند إلى فرع نشط.');
            }

            $branch = (clone $active)->whereKey($pos->branch_id)->first();
            if (! $branch) {
                throw new \LogicException('فرع موظف نقطة البيع غير نشط أو لا يتبع المنشأة.');
            }

            $claimed = $request->header('X-Amial-Branch-ID');
            if ($claimed !== null && (! ctype_digit((string) $claimed)
                || (int) $claimed !== (int) $branch->id)) {
                throw new \LogicException('لا يستطيع موظف نقطة البيع تغيير فرعه من الطلب.');
            }

            return $branch;
        }

        $claimed = $request->header('X-Amial-Branch-ID');
        if ($claimed !== null && $claimed !== '') {
            if (! ctype_digit((string) $claimed)) {
                throw new \LogicException('معرّف الفرع غير صالح.');
            }
            $branch = (clone $active)->whereKey((int) $claimed)->first();
            if (! $branch) {
                throw new \LogicException('الفرع غير نشط أو لا يتبع المنشأة.');
            }

            return $branch;
        }

        if ($activeCount === 0) {
            return null;
        }

        $default = (clone $active)->where('is_default', true)->first();
        if ($default) {
            return $default;
        }
        if ($activeCount === 1) {
            return (clone $active)->first();
        }

        throw new \LogicException('اختر فرعاً نشطاً قبل بدء عملية الكاشير.');
    }

    /**
     * الجهاز مقعد للفرع، لا ترويسة يختار بها المستخدم موقع البيع.
     *
     * @throws \LogicException
     */
    public function assertDeviceMatches(?PosDevice $device, User $merchant, ?Branch $branch): void
    {
        if ($device === null) {
            return;
        }
        if ((int) $device->merchant_user_id !== (int) $merchant->id
            || ! $device->is_active || $device->revoked_at !== null) {
            throw new \LogicException('جهاز نقطة البيع غير صالح لهذه المنشأة.');
        }

        if ($branch === null) {
            if ($device->branch_id !== null) {
                throw new \LogicException('هذا الجهاز مرتبط بفرع، ولا يوجد نطاق فرع للعملية.');
            }
            return;
        }

        if ((int) $device->branch_id !== (int) $branch->id) {
            throw new \LogicException('جهاز نقطة البيع لا يتبع الفرع التشغيلي الحالي.');
        }
    }

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
