<?php

namespace App\Services\Merchant;

use App\Models\Merchant\MerchantRole;
use App\Models\Merchant\MerchantRolePermission;
use App\Models\Merchant\MerchantUserRole;
use App\Models\MerchantProfile;
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
        // ملف التاجر هو تعريفُ المالك، لا صفّ وظيفة. قد يبقى لصاحب
        // المنشأة ربطٌ قديم في `merchant_user_roles` أو `pos_users` من
        // تجربة/استيراد سابقة؛ تقديم ذلك الربط على ملفّه يجعله موظفاً في
        // منشأته بلا دور، فتُبنى لوحة الوقود فارغة رغم أنّه المالك الحقيقي.
        //
        // نتحقق منه أولاً: الموظف لا يملك MerchantProfile باسمه، أمّا
        // التاجر الذي يملك ملفاً فلا يجوز أن يفقد ملكيته بسبب ربطٍ تابع.
        if (MerchantProfile::where('user_id', $user->id)->exists()) {
            return (int) $user->id;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-MERCHANT-OWNER-001 — **وملكيّةُ المنشأة تُثبَت بسجلّاتها،
        // لا بصفِّ ملفٍّ وحدَه.**
        //
        // **الثمنُ الذي دُفع، بنصّ صاحب المشروع:** «هذا حساب مالك المحطة،
        // لماذا لا يستطيع الدخول إلى حسابه؟» — ولوحةُ المحطّة تقول له
        // **«اطلب من مالك المحطة إسناد دورٍ لحسابك»**، وهو مالكُها.
        //
        // **والحرسُ أعلاه كان نصفَ حرس.** كُتب لأنّ ربطاً تابعاً قديماً
        // يجعل المالكَ موظفاً في منشأته — وحُرس بشرطٍ واحد: **أن يوجد
        // ملفُّ تاجرٍ له**. ومن لا ملفَّ له يسقط من تحته إلى الفروع
        // التالية، **فيصير الربطُ التابعُ هو هويّتَه**.
        //
        // ومن لا ملفَّ له موجودٌ فعلاً في هذا المشروع: مسجَّلٌ في
        // `CLAUDE.md` أنّ صفحتين ردّتا ٥٠٠ على «حسابِ تاجرٍ بلا ملفّ».
        //
        // **ولمَ لا `type === MERCHANT_TYPE` وحدَه:** جُرّب فأسقط عشرةَ
        // اختباراتِ صلاحيّاتٍ قائمة — وكانت محقّة. مسارُ الإنشاء الوحيد
        // اليوم (`MerchantStaffController`) يضع للموظّف `type = 4`، لكنّ
        // حساباً **قديماً أو مستورَداً** قد يحمل `type = 3` وهو موظّفٌ
        // فعلاً. وقاعدةٌ على النوع وحدَه كانت **تمنح ذلك الكاشيرَ كلَّ
        // صلاحيّات منشأة صاحبِه** — وهو أخطرُ ألفَ مرّةٍ من شاشةٍ فارغة.
        //
        // **فيُسأل الدليلُ لا النوع** (القاعدة السادسة: الرقمُ يُحسب من
        // مصدره): أيملك هذا الحسابُ سجلّاتِ منشأةٍ باسمه؟ محطّةً، أو
        // فرعاً، أو أدواراً صُنعت له. والموظّفُ — أيّاً كان نوعُه — لا
        // يملك واحداً منها: الأدوارُ لصاحب العمل، والمحطّةُ له.
        //
        // **وقِيس بالتشغيل قبل هذا السطر** — أربعُ حالاتٍ على القاعدة:
        //
        //   ① تاجرٌ بملفّ                      → مالك · ١٠٣ صلاحيّة
        //   ② تاجرٌ بلا ملفّ                   → مالك · ١٠٣ صلاحيّة
        //   ③ تاجرٌ بلا ملفّ + `pos_users` لغيره → **لا · صفر**  ← الشاشة
        //   ④ تاجرٌ بلا ملفّ + `pos_users` لنفسِه → مالك · ١٠٣ صلاحيّة
        //
        // فالحالةُ ③ وحدَها تُنتج الشاشةَ الفارغة، وهذه الفقرةُ تُغلقها
        // **دون أن تفتح باباً لموظّف**.
        // ══════════════════════════════════════════════════════════════
        if ($this->ownsMerchantRecords((int) $user->id)) {
            return (int) $user->id;
        }

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
     * أيملك هذا الحسابُ سجلّاتِ منشأةٍ باسمه؟
     *
     * **دليلُ ملكيّةٍ لا استنتاجُ نوع.** والموظّفُ لا يملك واحداً منها
     * مهما كان نوعُ حسابه: الأدوارُ تُصنَع لصاحب العمل، والمحطّةُ والفرعُ
     * يُنشآن باسمه. فمن له صفٌّ في أيٍّ منها **هو المنشأة**، ولا يجوز أن
     * يسلبَه ذلك ربطٌ تابعٌ بقي من تجربةٍ أو استيراد.
     */
    private function ownsMerchantRecords(int $userId): bool
    {
        return \App\Models\Merchant\MerchantRole::where('merchant_user_id', $userId)->exists()
            || \App\Models\FuelStation::where('merchant_user_id', $userId)->exists()
            || \App\Models\Branch::where('merchant_user_id', $userId)->exists();
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

        if (! $r['allowed']) {
            throw new DomainException($r['reason'] ?? 'غير مصرّح');
        }

        if ($r['approval'] === 'none') {
            return;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-MERCHANT-APPROVAL-002 — **الاعتمادُ صار طريقاً لا جداراً.**
        //
        // كان هنا رفضٌ مطلق، وكان التعليقُ يُحيل إلى `evaluate()` لمن أراد
        // مسارَ الاعتماد — **ولا مُنادي لها في المشروع كلِّه**. فثلاثُ منحٍ
        // مُسندةٍ في القوالب لا تُستعمَل أبداً: كاشيرٌ لا يُنشئ مرتجعاً،
        // وموظّفُ مستودعٍ لا يُسجّل هالكاً، ومشرفُ ورديّةٍ لا يُلغي بيعة.
        //
        // **ومنحةٌ لا تُستعمَل ليست فصلاً بل شلل.**
        //
        // فيُبحَث عن إذنِ مديرٍ صالحٍ ويُستهلَك. **والاستهلاكُ لحظةَ
        // التنفيذ**: إذنٌ يُقرأ ولا يُستهلَك إذنٌ دائمٌ يُنفَّذ به مرّتان.
        if (app(MerchantOverrideService::class)->consume($user, $permission, $amount)) {
            return;
        }

        // **والرفضُ يقول أيَّ رفضٍ هو.** «خارج نطاقك» تُنهي الأمر، و«يحتاج
        // اعتماداً» تفتح باباً — والخلطُ بينهما يُرسل الموظّفَ إلى الدعم
        // بدل مديره.
        throw new DomainException('يحتاج إذنَ مديرك قبل التنفيذ — '
            . 'اطلب الإذن ثمّ أعد المحاولة', self::APPROVAL_REQUIRED);
    }

    /**
     * رمزُ الاستثناء حين يكون الفعلُ مملوكاً ويحتاج إذناً.
     *
     * **ويُميَّز برمزٍ لا بنصّ**: متحكّمٌ يقارن الرسالةَ حرفاً بحرفٍ ينكسر
     * عند أوّل تحسينِ صياغة — وقد وقع ذلك في هذا المشروع اليوم.
     */
    public const APPROVAL_REQUIRED = 4030;

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
     * **الصيدليّةُ ترث أدوارَ التجزئة — وذاك ما كان.**
     *
     * AMIAL-VERTICAL-RBAC-001. فمالكُ صيدليّةٍ يفتح شاشةَ الأدوار فيجد
     * «مدير متجر · موظّف مستودع · مندوب مبيعات»، وليس فيها صيدليٌّ ولا
     * فنّيّ. **والصيدليُّ ليس الكاشير**: هو وحدَه يوثّق الوصفةَ ويقرأ
     * الملفَّ الطبّيّ.
     *
     * @return array<int,MerchantRole>
     */
    public function seedPharmacyRoles(User $merchant): array
    {
        return $this->seedRoles($merchant, P::pharmacySeedRoles(), P::pharmacySeedScopes());
    }

    /**
     * **والجملةُ ديونٌ قبل أن تكون بضاعة.**
     *
     * فالفصلُ الحاكم: من يبيع لا يُحصّل، ومن يُحصّل لا يُبطل. وثلاثتُها
     * في يدٍ واحدةٍ تُخفي اختلاساً بلا أثر — يُسجَّل بيعٌ ويُقبَض نقداً
     * ثمّ تُبطَل الفاتورة، فيختفي الدَّينُ والمقبوض معاً.
     *
     * @return array<int,MerchantRole>
     */
    public function seedWholesaleRoles(User $merchant): array
    {
        return $this->seedRoles($merchant, P::wholesaleSeedRoles(), P::wholesaleSeedScopes());
    }

    /**
     * **والطلبُ في المطعم يمرّ بأيدٍ ثلاث** — نادلٌ ومطبخٌ وكاشير.
     * وطاهٍ يُغلق طلباً يُثبت مبلغاً لم يُقبَض.
     *
     * @return array<int,MerchantRole>
     */
    public function seedRestaurantRoles(User $merchant): array
    {
        return $this->seedRoles($merchant, P::restaurantSeedRoles(), P::restaurantSeedScopes());
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
