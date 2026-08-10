<?php

namespace App\Services\Merchant;

use App\Models\Merchant\MerchantRole;
use App\Models\Merchant\MerchantRolePermission;
use App\Models\Merchant\MerchantUserRole;
use App\Models\User;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلتان ٤ و٥ — **محرّكُ الصلاحيّة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * سؤالٌ واحدٌ يُطرح في كلّ مكان:
 *
 *     $perm->assert($user, P::FUEL_SALE_CANCEL, station: 3, amount: '250000');
 *
 * **ولا `if ($role === 'cashier')` في أيّ موضع.** فالدورُ حزمةُ صلاحيّاتٍ
 * قابلةٌ للتعديل، وشرطُه في الشيفرة يجمّده.
 *
 * **والجوابُ ثلاثيّ:** أيملك الفعل؟ أفي نطاقه؟ أتحت حدّه؟ وإن كان فوق
 * الحدّ فليس رفضاً بالضرورة — **قد يكون طلبَ اعتماد**، وهو فرقٌ يراه
 * المستعمل كطريقِ خروجٍ لا كبابٍ مغلق.
 */
class MerchantPermissionService
{
    /** الأدوارُ النشطة للمستخدم — تُقرأ مرّةً في الطلب. */
    private array $cache = [];

    /**
     * المنشأةُ التي ينتمي إليها المستخدم.
     *
     * **وتُقرأ من انتمائه لا من عمودٍ في `users`.** فالموظّفُ يُعرَف إمّا
     * بإسناد دورٍ (`merchant_user_roles`) وإمّا بكونه نقطةَ بيع
     * (`pos_users`) — وهما البنيتان القائمتان. وعمودٌ جديدٌ في `users`
     * لأجل هذا يكرّر ما هو موجود.
     */
    public function merchantIdFor(User $user): int
    {
        $viaRole = MerchantUserRole::where('user_id', $user->id)
            ->where('is_active', true)->value('merchant_user_id');

        if ($viaRole) {
            return (int) $viaRole;
        }

        $viaPos = \App\Models\PosUser::where('user_id', $user->id)
            ->value('merchant_user_id');

        return (int) ($viaPos ?: $user->id);
    }

    /**
     * **صاحبُ الحساب يملك كلَّ شيء** — لا صفَّ دورٍ له ولا يحتاج.
     *
     * وحدودُه حدودُ المنصّة: التسعيرُ والتسويةُ والحظر بيد أميال، وتلك
     * تُفحص في طبقةٍ أخرى لا هنا.
     */
    public function isOwner(User $user): bool
    {
        return $this->merchantIdFor($user) === (int) $user->id;
    }

    /**
     * @return array<int,MerchantRolePermission>
     */
    private function grantsFor(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $roleIds = MerchantUserRole::where('user_id', $user->id)
            ->where('is_active', true)->pluck('merchant_role_id');

        if ($roleIds->isEmpty()) {
            return $this->cache[$user->id] = [];
        }

        $active = MerchantRole::whereIn('id', $roleIds)
            ->where('is_active', true)->pluck('id');

        return $this->cache[$user->id] = MerchantRolePermission::whereIn('merchant_role_id', $active)
            ->get()->all();
    }

    /**
     * أيملك الفعلَ أصلاً — بصرف النظر عن النطاق والحدّ؟
     *
     * تُستعمل لبناء القائمة الجانبيّة: **زرٌّ لا صلاحيّةَ له لا يُرسم**.
     * ولا تُستعمل بديلاً عن `assert` عند التنفيذ — إخفاءُ الواجهة ليس أماناً.
     */
    public function can(User $user, string $permission): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        foreach ($this->grantsFor($user) as $g) {
            if ($g->permission_code === $permission) {
                return true;
            }
        }

        return false;
    }

    /**
     * الفحصُ الكامل — **يُنادى قبل كلّ فعلٍ خطير**.
     *
     * @param  array{station?:int,branch?:int,shift?:int,owner_user_id?:int}  $context
     * @return array{allowed:bool,approval:string,reason:?string}
     */
    public function evaluate(
        User $user,
        string $permission,
        array $context = [],
        ?string $amount = null,
    ): array {
        if (! P::exists($permission)) {
            // صلاحيّةٌ مجهولةٌ تُرفض ولا تُتجاهل: خطأُ إملاءٍ في نداءٍ
            // يجب أن يسقط، لا أن يفتح الباب صامتاً.
            throw new DomainException("صلاحية غير معروفة: {$permission}");
        }

        if ($this->isOwner($user)) {
            return ['allowed' => true, 'approval' => 'none', 'reason' => null];
        }

        $matching = array_filter(
            $this->grantsFor($user),
            static fn (MerchantRolePermission $g): bool => $g->permission_code === $permission,
        );

        if ($matching === []) {
            return ['allowed' => false, 'approval' => 'none',
                'reason' => 'لا تملك صلاحية تنفيذ هذا الإجراء'];
        }

        $bestApproval = null;
        $overLimit = null;

        foreach ($matching as $g) {
            if (! $this->scopeMatches($g, $user, $context)) {
                continue;
            }

            if ($amount !== null && $g->max_amount !== null
                && bccomp($amount, (string) $g->max_amount, 4) > 0) {
                // فوق الحدّ — يُحفظ ويُواصَل: منحةٌ أخرى قد تسع المبلغ.
                $overLimit = (string) $g->max_amount;

                continue;
            }

            // **أوسعُ منحةٍ تفوز**: بلا اعتمادٍ خيرٌ من باعتماد.
            if ($g->approval === 'none') {
                return ['allowed' => true, 'approval' => 'none', 'reason' => null];
            }

            $bestApproval ??= $g->approval;
        }

        if ($bestApproval !== null) {
            return ['allowed' => true, 'approval' => $bestApproval,
                'reason' => 'يحتاج اعتماداً قبل التنفيذ'];
        }

        if ($overLimit !== null) {
            return ['allowed' => false, 'approval' => 'none', 'reason' => sprintf(
                'المبلغ %s يتجاوز حدّك %s — اطلب من مديرك التنفيذ',
                $amount, $overLimit,
            )];
        }

        return ['allowed' => false, 'approval' => 'none',
            'reason' => 'هذا الإجراء خارج نطاقك (محطة/فرع/وردية أخرى)'];
    }

    /**
     * يرمي عند الرفض — الصيغةُ القصيرة للاستعمال في الخدمات.
     *
     * **والاعتمادُ المطلوبُ رفضٌ هنا**: تنفيذٌ بلا اعتمادٍ تجاوز. ومن
     * أراد مسارَ الاعتماد ينادي `evaluate` ويبني الطلب.
     */
    public function assert(
        User $user,
        string $permission,
        array $context = [],
        ?string $amount = null,
    ): void {
        $r = $this->evaluate($user, $permission, $context, $amount);

        if (! $r['allowed'] || $r['approval'] !== 'none') {
            throw new DomainException($r['reason'] ?? 'غير مصرّح');
        }
    }

    private function scopeMatches(
        MerchantRolePermission $g, User $user, array $context,
    ): bool {
        return match ($g->scope_type) {
            'merchant' => true,

            'station' => $g->scope_id === null
                || (int) $g->scope_id === (int) ($context['station'] ?? 0),

            'branch' => $g->scope_id === null
                || (int) $g->scope_id === (int) ($context['branch'] ?? 0),

            'shift' => true,   // ورديّةٌ يشرف عليها — يقيّدها السياق أعلاه

            // **`own` هي الأدقّ وأكثرُها إهمالاً**: من يغلق ورديّةَ زميله
            // يخرج بفرقٍ ليس فرقَه، ومن يلغي بيعةَ غيره يمحو أثرَه.
            'own' => (int) ($context['owner_user_id'] ?? 0) === (int) $user->id
                || ! array_key_exists('owner_user_id', $context),

            default => false,
        };
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأدوار
    // ══════════════════════════════════════════════════════════════════

    /**
     * تُنسخ الأدوارُ البذرةُ إلى التاجر — **مرّةً واحدة**، ثمّ يملكها.
     *
     * ولا تُكتب فوق تعديلاته: من غيّر صلاحيّاتِ «كاشير» عنده لا تعود
     * إلى الأصل عند أوّل نشرة.
     *
     * @return array<int,MerchantRole>
     */
    public function seedFuelRoles(User $merchant): array
    {
        return $this->seedRoles($merchant, P::fuelSeedRoles(), P::fuelSeedScopes());
    }

    /**
     * الأدوارُ الثمانيةُ للتجزئة — **بالمحرّك نفسِه**.
     *
     * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٨. ولا شيءَ جديدٌ هنا غير
     * القائمة: البذرُ والنطاقُ والحدُّ كلُّها من جولة الوقود.
     *
     * @return array<int,MerchantRole>
     */
    public function seedRetailRoles(User $merchant): array
    {
        return $this->seedRoles($merchant, P::retailSeedRoles(), P::retailSeedScopes());
    }

    /**
     * @param  array<string,array{name:string,permissions:array<int,string>}>  $roles
     * @param  array<string,array<string,array{scope:string,limit?:string,approval?:string}>>  $scopes
     * @return array<int,MerchantRole>
     */
    private function seedRoles(User $merchant, array $roles, array $scopes): array
    {
        $out = [];

        DB::transaction(function () use ($merchant, $roles, $scopes, &$out) {
            foreach ($roles as $code => $def) {
                $existing = MerchantRole::where('merchant_user_id', $merchant->id)
                    ->where('code', $code)->first();

                if ($existing) {
                    $out[] = $existing;

                    continue;
                }

                $role = MerchantRole::create([
                    'merchant_user_id' => $merchant->id,
                    'code' => $code,
                    'name_ar' => $def['name'],
                    'is_system' => true,
                    'is_active' => true,
                ]);

                foreach ($def['permissions'] as $perm) {
                    $s = $scopes[$code][$perm] ?? [];

                    MerchantRolePermission::create([
                        'merchant_role_id' => $role->id,
                        'permission_code' => $perm,
                        'scope_type' => $s['scope'] ?? 'merchant',
                        'scope_id' => null,
                        'max_amount' => $s['limit'] ?? null,
                        'approval' => $s['approval'] ?? 'none',
                    ]);
                }

                $out[] = $role;
            }
        });

        return $out;
    }

    /** إسنادُ دورٍ إلى موظّف. */
    public function assign(
        User $merchant, User $employee, MerchantRole $role,
        ?int $stationId = null, ?int $branchId = null,
    ): MerchantUserRole {
        if ((int) $role->merchant_user_id !== (int) $merchant->id) {
            // دورُ تاجرٍ آخرَ لا يُسنَد — والمعرّفُ يأتي من المتصفّح.
            throw new DomainException('هذا الدور لا يخصّ منشأتك');
        }

        $this->cache = [];

        return MerchantUserRole::updateOrCreate(
            ['user_id' => $employee->id, 'merchant_role_id' => $role->id],
            [
                'merchant_user_id' => $merchant->id,
                'scope_station_id' => $stationId,
                'scope_branch_id' => $branchId,
                'is_active' => true,
            ],
        );
    }

    /**
     * الصلاحيّاتُ المؤثّرة لمستخدم — **تُبنى منها القائمة الجانبيّة**.
     *
     * @return array<int,string>
     */
    public function effective(User $user): array
    {
        if ($this->isOwner($user)) {
            return P::all();
        }

        return array_values(array_unique(array_map(
            static fn (MerchantRolePermission $g): string => $g->permission_code,
            $this->grantsFor($user),
        )));
    }
}
