<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Merchant\PosDevice;
use App\Models\PosUser;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Services\Retail\StockService;
use Illuminate\Support\Facades\DB;

/**
 * P1-BRANCHES — خدمة إدارة الفروع.
 *
 * المسؤوليات:
 *   1. CRUD الفروع مع فحص حدّ الخطّة.
 *   2. إنشاء الفرع الافتراضي عند الترقية.
 *   3. حماية الفرع الافتراضي من الحذف.
 *   4. منع تكرار اسم الفرع لنفس التاجر.
 */
class BranchService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * إنشاء فرع جديد مع فحص حدّ الخطّة.
     *
     * @throws UsageLimitExceededException إن تجاوز حدّ الخطّة.
     * @throws \InvalidArgumentException إن البيانات غير صالحة.
     */
    public function create(User $merchant, array $data): Branch
    {
        $this->ensureWithinPlanLimit($merchant);

        // تحقّق من عدم التكرار
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('اسم الفرع مطلوب');
        }
        if (Branch::where('merchant_user_id', $merchant->id)
            ->where('name', $name)->exists()) {
            throw new \InvalidArgumentException('فرع بنفس الاسم موجود مسبقاً');
        }

        return DB::transaction(function () use ($merchant, $data, $name) {
            // هل هذا أوّل فرع؟ → اجعله الافتراضي
            $hasAny = Branch::where('merchant_user_id', $merchant->id)->exists();

            $branch = Branch::create([
                'merchant_user_id' => $merchant->id,
                'name' => $name,
                'code' => $data['code'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
                'is_default' => !$hasAny, // الأوّل يصبح افتراضياً
                'settings' => $data['settings'] ?? null,
            ]);

            $this->syncInventoryLocation($merchant, $branch);

            return $branch->fresh();
        });
    }

    /**
     * إنشاء الفرع الافتراضي تلقائياً (يُستدعى عند الترقية لخطّة تدعم فروعاً).
     * idempotent: لا يُنشئ ثانية إن وُجد.
     */
    public function ensureDefaultBranch(User $merchant): ?Branch
    {
        $plan = $this->planFor($merchant);
        if (A::maxBranches($plan) === 0) return null;

        return DB::transaction(function () use ($merchant) {
            $branch = Branch::where('merchant_user_id', $merchant->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();

            if ($branch === null) {
                $branch = Branch::create([
                    'merchant_user_id' => $merchant->id,
                    'name' => 'الفرع الرئيسي',
                    'is_active' => true,
                    'is_default' => true,
                ]);
            }

            // ترقية تاجر من «أعمال» (بلا فروع) إلى «مؤسسة» لا يجوز أن
            // تقطع يومه التشغيلي. قبل وجود الفروع كانت null تعني المنشأة
            // الرئيسية؛ بعد إنشاء الفرع الافتراضي نحول *الحالة الحية*
            // إلى المعنى الصريح نفسه:
            //
            //   موظف POS بلا فرع  -> الفرع الرئيسي
            //   جهاز POS بلا فرع  -> الفرع الرئيسي
            //   وردية مفتوحة      -> الفرع الرئيسي
            //
            // لا نعيد كتابة التاريخ المالي كله داخل ترقية الاشتراك؛
            // المبيعات القديمة ذات branch_id=null تبقى «إرث المنشأة
            // الرئيسية»، لكن الوردية الجارية تستمر بلا مطالبة الكاشير
            // بفتح وردية ثانية ولا يصبح الجهاز فجأة «بلا فرع».
            PosUser::where('merchant_user_id', $merchant->id)
                ->where('is_active', true)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch->id]);

            PosDevice::where('merchant_user_id', $merchant->id)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch->id]);

            CashierShift::where('merchant_user_id', $merchant->id)
                ->where('status', 'open')
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch->id]);

            $this->syncInventoryLocation($merchant, $branch);

            return $branch->fresh();
        });
    }

    public function update(Branch $branch, array $data): Branch
    {
        // منع التكرار
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($name === '') throw new \InvalidArgumentException('اسم الفرع مطلوب');
            $conflict = Branch::where('merchant_user_id', $branch->merchant_user_id)
                ->where('name', $name)
                ->where('id', '!=', $branch->id)
                ->exists();
            if ($conflict) throw new \InvalidArgumentException('اسم مستخدم لفرع آخر');
        }

        $branch->fill(array_intersect_key($data, array_flip([
            'name', 'code', 'address', 'city', 'phone',
            'manager_pos_user_id', 'is_active', 'settings',
        ])));
        $branch->save();

        $merchant = User::find($branch->merchant_user_id);
        if ($merchant) {
            $this->syncInventoryLocation($merchant, $branch);
        }

        return $branch->fresh();
    }

    /**
     * حذف فرع (soft delete).
     * يرفض حذف الـ default branch.
     */
    public function delete(Branch $branch): bool
    {
        if ($branch->is_default) {
            throw new \LogicException('لا يمكن حذف الفرع الافتراضي');
        }
        // ملاحظة: لاحقاً نضيف فحص أنّ الفرع لا يحوي عمليات نشطة
        $deleted = (bool) $branch->delete();

        if ($deleted && $this->usesSharedRetailStock((int) $branch->merchant_user_id)) {
            $this->stock->deactivateBranchLocation($branch);
        }

        return $deleted;
    }

    /**
     * نقل صفة الـ default إلى فرع آخر.
     */
    public function setAsDefault(Branch $branch): Branch
    {
        if (!$branch->is_active) {
            throw new \LogicException('لا يمكن جعل فرع غير نشط افتراضياً');
        }
        return DB::transaction(function () use ($branch) {
            // إزالة الـ default من كل فروع نفس التاجر
            Branch::where('merchant_user_id', $branch->merchant_user_id)
                ->where('id', '!=', $branch->id)
                ->update(['is_default' => false]);
            $branch->is_default = true;
            $branch->save();

            $merchant = User::find($branch->merchant_user_id);
            if ($merchant) {
                $this->syncInventoryLocation($merchant, $branch);
            }

            return $branch->fresh();
        });
    }

    public function listForMerchant(User $merchant, bool $activeOnly = false): \Illuminate\Support\Collection
    {
        $q = Branch::where('merchant_user_id', $merchant->id)->orderBy('is_default', 'desc')->orderBy('name');
        if ($activeOnly) $q->where('is_active', true);
        return $q->get();
    }

    // ============ Plan Limits ============

    /**
     * يتحقّق من حدّ الخطّة قبل إنشاء فرع.
     * @throws UsageLimitExceededException
     */
    private function ensureWithinPlanLimit(User $merchant): void
    {
        $plan = $this->planFor($merchant);
        $max = A::maxBranches($plan);

        if ($max === 0) {
            throw new UsageLimitExceededException(
                limitType: 'branches', currentValue: 0, maxValue: 0,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }
        if ($max < 0) return; // غير محدود

        $current = Branch::where('merchant_user_id', $merchant->id)->count();
        if ($current >= $max) {
            throw new UsageLimitExceededException(
                limitType: 'branches', currentValue: $current, maxValue: $max,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }
    }

    // ============ Helpers ============

    /**
     * التجزئة والبيع السريع والمطعم تشترك في MerchantProduct/StockService.
     * القطاعات ذات محركات مخزون مستقلة لا ننشئ لها مواقع مخزون موازية.
     */
    private function usesSharedRetailStock(int $merchantUserId): bool
    {
        $vertical = MerchantProfile::where('user_id', $merchantUserId)
            ->value('business_type');

        return in_array($vertical, [
            A::BIZ_RETAIL,
            A::BIZ_QUICK_SALE,
            A::BIZ_RESTAURANT,
        ], true);
    }

    private function syncInventoryLocation(User $merchant, Branch $branch): void
    {
        if (! $this->usesSharedRetailStock($merchant->id)) {
            return;
        }

        $this->stock->syncBranchLocation($branch);
    }

    private function planFor(User $merchant): string
    {
        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (!$profile) return A::PLAN_FREE;
        $plan = A::canonicalPlan($profile->subscription_plan);
        if ($plan !== A::PLAN_FREE
            && $profile->subscription_expires_at !== null
            && $profile->subscription_expires_at->isPast()) {
            return A::PLAN_FREE;
        }
        return $plan;
    }
}
