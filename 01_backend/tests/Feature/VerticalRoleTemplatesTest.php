<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-RBAC-001 — **قالبٌ بلا أثر أسوأ من غيابه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس قبل سطرٍ واحدٍ من الشيفرة:**
 *
 *   الوقود    ٢٤ فعلاً  →  `guarded()` على كلٍّ
 *   التجزئة   ٤٣ فعلاً  →  `guarded()` على كلٍّ
 *   الصيدليّة ١٧ فعلاً  →  **صفر**
 *   الجملة    ٢٥ فعلاً  →  **صفر**
 *   المطعم    ١١ فعلاً  →  **صفر**
 *
 * فثلاثةٌ وخمسون فعلاً — فيها **إبطالُ فاتورة** و**تسجيلُ تحصيل** و**قراءةُ
 * السجلّ الطبّيّ** — مفتوحةٌ لكلّ صفٍّ نشِطٍ في `pos_users`.
 *
 * **والقالبُ الذي كان يُزرع لها أدوارُ تجزئة**: «مدير متجر · موظّف
 * مستودع · مندوب مبيعات». وفيه عطلان لا واحد:
 *
 *   ① أسماءُ وظائفَ لا وجودَ لها في صيدليّةٍ ولا مطعم.
 *   ② وصلاحيّاتٌ **لا يقرؤها متحكّمٌ واحد** — فإسنادُها لا يغيّر شيئاً.
 *
 * **وذاك أسوأ من غياب القالب**: من يرى «كاشير» مسنَداً يظنّ الوصولَ
 * مضبوطاً، فلا يبحث. والغيابُ يُرى، والوهمُ لا يُرى.
 */
class VerticalRoleTemplatesTest extends TestCase
{
    use RefreshDatabase;

    /** ملفّاتُ المتحكّمات الثلاثة التي كانت بلا حارس. */
    private const CONTROLLERS = [
        'PharmacyController' => 'الصيدليّة',
        'WholesaleController' => 'الجملة',
        'RestaurantController' => 'المطعم',
    ];

    /**
     * **أفعالٌ بلا صلاحيّةٍ عن قصد — ويُقال السبب.**
     *
     * قراءةُ ملفّ المنشأة نفسِه يحتاجها كلُّ من يفتح أيَّ شاشة: التطبيقُ
     * ينادي `getPharmacy` قبل أن يعرف دورَ الفاتح. **وحارسٌ عليها يشلّ
     * الدخولَ كلَّه** ولا يحمي شيئاً — الاسمُ والمدينةُ ورقمُ الترخيص،
     * ولا رقمَ مالاً فيها.
     *
     * @var array<string,string>
     */
    private const OPEN_BY_DESIGN = [
        'PharmacyController::getPharmacy' => 'ملفُّ المنشأة — يفتحه كلُّ من دخل، ولا مالَ فيه',
        'WholesaleController::getBusiness' => 'ملفُّ المنشأة — يفتحه كلُّ من دخل، ولا مالَ فيه',
    ];

    private function merchantOf(string $vertical): User
    {
        $m = User::factory()->create(['type' => 3]);

        MerchantProfile::create([
            'user_id' => $m->id,
            'business_name' => 'منشأة اختبار',
            'business_type' => $vertical,
        ]);

        app(VerticalBootstrapService::class)->ensureFor($m);

        return $m->refresh();
    }

    /** موظّفٌ في المنشأة بدورٍ برمزه. */
    private function staffOf(User $merchant, string $roleCode): User
    {
        $u = User::factory()->create(['type' => 3]);

        PosUser::create([
            'merchant_user_id' => $merchant->id,
            'user_id' => $u->id,
            'pos_number' => 'POS-' . $u->id,
            'is_active' => true,
        ]);

        $role = DB::table('merchant_roles')
            ->where('merchant_user_id', $merchant->id)
            ->where('code', $roleCode)->first();

        $this->assertNotNull($role, "الدورُ «{$roleCode}» لم يُزرع للمنشأة");

        DB::table('merchant_user_roles')->insert([
            'merchant_user_id' => $merchant->id,
            'user_id' => $u->id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① لا فعلَ بلا حارس — ويُقاس من المصدر لا من عدٍّ مكتوب
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_action_in_the_three_verticals_asks_for_a_permission(): void
    {
        $offences = [];
        $seen = 0;

        foreach (array_keys(self::CONTROLLERS) as $class) {
            $path = app_path("Http/Controllers/Api/V1/Amial/{$class}.php");
            $src = file_get_contents($path);

            // **يُقسَّم على توقيعات الدوالّ العامّة** — لا تعبيرٌ نمطيٌّ
            // جشعٌ يبتلع أقواساً. (القاعدة الخامسة.)
            $parts = preg_split('~\n    public function (\w+)\(~', $src, -1,
                PREG_SPLIT_DELIM_CAPTURE);

            for ($i = 1; $i < count($parts); $i += 2) {
                $name = $parts[$i];
                $body = $parts[$i + 1];

                if ($name === '__construct') {
                    continue;
                }

                $seen++;
                $key = "{$class}::{$name}";

                if (array_key_exists($key, self::OPEN_BY_DESIGN)) {
                    continue;
                }

                if (! str_contains($body, '$this->guard(')) {
                    $offences[] = $key;
                }
            }
        }

        // **ومُطابِقٌ عمي يخرج أخضرَ على صفر.** فيُشترط حدٌّ أدنى.
        $this->assertGreaterThan(45, $seen,
            "لم يُقرأ إلّا {$seen} فعلاً — المرشِّحُ لا يرى المتحكّمات.");

        sort($offences);

        $this->assertSame([], $offences,
            "أفعالٌ بلا فحصِ صلاحيّة:\n  " . implode("\n  ", $offences) . "\n\n"
            . 'وإخفاءُ الزرّ في الواجهة ليس أماناً: من يعرف المسار ينادي بلا زرّ.');
    }

    /** @test */
    public function the_permissions_those_actions_ask_for_all_exist(): void
    {
        // **وصلاحيّةٌ لا وجودَ لها في الفهرس تُرفض دائماً** — فيصير الحارسُ
        // شللاً كاملاً على الفعل، وهو عطلٌ لا حماية.
        $missing = [];

        foreach (array_keys(self::CONTROLLERS) as $class) {
            $src = file_get_contents(app_path("Http/Controllers/Api/V1/Amial/{$class}.php"));

            preg_match_all('~P::([A-Z_]+)~', $src, $m);

            foreach (array_unique($m[1]) as $const) {
                $code = constant(P::class . '::' . $const);

                if (! P::exists($code)) {
                    $missing[] = "{$class} → {$const} ({$code})";
                }
            }
        }

        $this->assertSame([], $missing,
            "صلاحيّاتٌ يطلبها متحكّمٌ ولا وجودَ لها في الفهرس:\n  "
            . implode("\n  ", $missing));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② ولكلّ قطاعٍ قالبُه — بأسماء وظائفه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_pharmacy_is_no_longer_seeded_with_shop_roles(): void
    {
        $m = $this->merchantOf(A::BIZ_PHARMACY);

        $codes = DB::table('merchant_roles')
            ->where('merchant_user_id', $m->id)->pluck('code')->all();

        sort($codes);

        $this->assertSame(
            ['accountant', 'cashier', 'custom', 'inventory_clerk', 'owner',
                'pharmacist', 'pharmacy_technician'],
            $codes,
            'الصيدليّةُ ما زالت ترث أدوارَ التجزئة');

        // **ولا «مدير متجر» ولا «موظّف مستودع»** — وظائفُ لا وجودَ لها.
        $this->assertNotContains('store_manager', $codes);
        $this->assertNotContains('warehouse_staff', $codes);
    }

    /** @test */
    public function a_wholesale_business_and_a_restaurant_get_their_own_roles(): void
    {
        $w = DB::table('merchant_roles')
            ->where('merchant_user_id', $this->merchantOf(A::BIZ_WHOLESALE)->id)
            ->pluck('code')->all();

        foreach (['sales_manager', 'sales_rep', 'collector', 'accountant'] as $c) {
            $this->assertContains($c, $w, "الجملةُ بلا دور «{$c}»");
        }

        $r = DB::table('merchant_roles')
            ->where('merchant_user_id', $this->merchantOf(A::BIZ_RESTAURANT)->id)
            ->pluck('code')->all();

        foreach (['restaurant_manager', 'waiter', 'kitchen_staff', 'cashier'] as $c) {
            $this->assertContains($c, $r, "المطعمُ بلا دور «{$c}»");
        }
    }

    /** @test */
    public function the_seeded_roles_are_not_empty_and_not_everything(): void
    {
        // **دورٌ بلا صلاحيّةٍ ليس دوراً، ودورٌ بكلّ الصلاحيّات ليس فصلاً.**
        // و`custom` فارغٌ عن قصدٍ فيُستثنى، و`owner` يملك الكلَّ عن قصد.
        foreach ([A::BIZ_PHARMACY, A::BIZ_WHOLESALE, A::BIZ_RESTAURANT] as $v) {
            $m = $this->merchantOf($v);

            $rows = DB::table('merchant_roles')
                ->where('merchant_user_id', $m->id)->get();

            foreach ($rows as $role) {
                $n = DB::table('merchant_role_permissions')
                    ->where('merchant_role_id', $role->id)->count();

                if ($role->code === 'custom') {
                    $this->assertSame(0, $n, 'الدورُ المخصَّص يجب أن يُولد فارغاً');

                    continue;
                }

                $this->assertGreaterThan(0, $n, "{$v}/{$role->code} دورٌ بلا صلاحيّة");

                if ($role->code !== 'owner') {
                    $this->assertLessThan(count(P::all()), $n,
                        "{$v}/{$role->code} يملك كلَّ شيء — وذاك ليس دوراً");
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والفصلُ يُقاس بالطلب لا بالجدول
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_pharmacy_cashier_cannot_read_the_patient_medical_file(): void
    {
        $m = $this->merchantOf(A::BIZ_PHARMACY);
        $cashier = $this->staffOf($m, 'cashier');
        $perm = app(MerchantPermissionService::class);

        $this->assertTrue($perm->can($cashier, P::PHARMACY_SALE_CREATE),
            'كاشيرُ الصيدليّة لا يبيع — وذاك شللٌ لا ضبط');

        foreach ([P::PHARMACY_PATIENT_VIEW, P::PHARMACY_PATIENT_MANAGE,
            P::PHARMACY_PRESCRIPTION_RECORD, P::PHARMACY_BATCH_RECORD,
            P::PHARMACY_ALERT_DISMISS] as $code) {
            $this->assertFalse($perm->can($cashier, $code),
                "كاشيرُ الصيدليّة يملك {$code}");
        }
    }

    /** @test */
    public function only_the_pharmacist_documents_a_prescription(): void
    {
        $m = $this->merchantOf(A::BIZ_PHARMACY);
        $perm = app(MerchantPermissionService::class);

        $this->assertTrue($perm->can($this->staffOf($m, 'pharmacist'),
            P::PHARMACY_PRESCRIPTION_RECORD));

        // **والفنّيُّ يبيع ولا يوثّق** — وهو فصلُ الفعل المرخَّص عن غيره.
        $tech = $this->staffOf($m, 'pharmacy_technician');
        $this->assertTrue($perm->can($tech, P::PHARMACY_SALE_CREATE));
        $this->assertFalse($perm->can($tech, P::PHARMACY_PRESCRIPTION_RECORD));
        $this->assertFalse($perm->can($tech, P::PHARMACY_PATIENT_VIEW));

        // **والمحاسبُ يدقّق أرقاماً لا أمراضاً.**
        $this->assertFalse($perm->can($this->staffOf($m, 'accountant'),
            P::PHARMACY_PATIENT_VIEW));
    }

    /** @test */
    public function in_wholesale_whoever_sells_does_not_void_and_whoever_collects_does_not_sell(): void
    {
        // **وثلاثتُها في يدٍ واحدةٍ تُخفي اختلاساً بلا أثر:** يُسجَّل بيعٌ،
        // ويُقبَض نقداً، ثمّ تُبطَل الفاتورة — فيختفي الدَّينُ والمقبوض
        // معاً، ويتوازن الدفترُ على نقصٍ لا يظهر.
        $m = $this->merchantOf(A::BIZ_WHOLESALE);
        $perm = app(MerchantPermissionService::class);

        $rep = $this->staffOf($m, 'sales_rep');
        $this->assertTrue($perm->can($rep, P::WHOLESALE_INVOICE_CREATE));
        $this->assertFalse($perm->can($rep, P::WHOLESALE_INVOICE_VOID),
            'المندوبُ يبيع ويُبطل — وذاك محوُ الدَّين بيدِ من أنشأه');

        $col = $this->staffOf($m, 'collector');
        $this->assertTrue($perm->can($col, P::WHOLESALE_COLLECTION_RECORD));
        $this->assertFalse($perm->can($col, P::WHOLESALE_INVOICE_CREATE));
        $this->assertFalse($perm->can($col, P::WHOLESALE_INVOICE_VOID));

        $acc = $this->staffOf($m, 'accountant');
        $this->assertTrue($perm->can($acc, P::WHOLESALE_INVOICE_VOID));
        $this->assertFalse($perm->can($acc, P::WHOLESALE_INVOICE_CREATE));
        $this->assertFalse($perm->can($acc, P::WHOLESALE_COLLECTION_RECORD));

        // **ولا أحدَ غير المالك يُعدّل المخزونَ مباشرة** — بابٌ يُنقص
        // البضاعةَ بلا فاتورة، فلا يظهر عجز.
        foreach (['sales_manager', 'sales_rep', 'collector', 'accountant',
            'warehouse_staff'] as $code) {
            $this->assertFalse($perm->can($this->staffOf($m, $code), P::WHOLESALE_STOCK_ADJUST),
                "«{$code}» يُعدّل مخزونَ الجملة مباشرة");
        }
    }

    /** @test */
    public function a_cook_cannot_close_an_order(): void
    {
        // **والإغلاقُ قبضٌ** — يُنهي الطلبَ ويُثبت مبلغَه.
        $m = $this->merchantOf(A::BIZ_RESTAURANT);
        $perm = app(MerchantPermissionService::class);

        $cook = $this->staffOf($m, 'kitchen_staff');
        $this->assertTrue($perm->can($cook, P::RESTAURANT_ORDER_STATUS));
        $this->assertFalse($perm->can($cook, P::RESTAURANT_ORDER_CLOSE));
        $this->assertFalse($perm->can($cook, P::RESTAURANT_TABLE_MANAGE));
        $this->assertFalse($perm->can($cook, P::RESTAURANT_ORDER_UPDATE));

        // والنادلُ يفتح ويُعدّل ولا يقبض.
        $waiter = $this->staffOf($m, 'waiter');
        $this->assertTrue($perm->can($waiter, P::RESTAURANT_ORDER_OPEN));
        $this->assertFalse($perm->can($waiter, P::RESTAURANT_ORDER_CLOSE));

        // والكاشيرُ يقبض ولا يطبخ الحالة.
        $cashier = $this->staffOf($m, 'cashier');
        $this->assertTrue($perm->can($cashier, P::RESTAURANT_ORDER_CLOSE));
        $this->assertFalse($perm->can($cashier, P::RESTAURANT_ORDER_STATUS));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ ويُقاس من الطلب نفسِه — لا من الجدول وحدَه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_denial_reaches_the_endpoint_and_says_why(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وأوّلُ صيغةٍ لهذا المقياس كانت تمرّ لسببٍ غير الذي تدّعيه.**
        //
        // كانت تكتفي بـ`assertStatus(403)` — فجُرّبت بالعكس (نُزع حارسُ
        // `voidInvoice`) **فبقيت خضراء**: الردُّ ٤٠٣ يأتي من
        // `EnsurePosDevice` — موظّفُ نقطة بيعٍ بلا مقعدٍ مربوط — لا من
        // الصلاحيّة. **وحارسٌ يمرّ والعطلُ قائم أسوأ من غيابه.**
        //
        // فيُعزَل حاجزُ المقعد، ويُقاس **الفرقُ** بين من يملك ومن لا يملك:
        // رقمُ الحالة وحدَه لا يميّز بابين مغلقين لسببين.
        config()->set('amial.pos_devices.enforce_session_binding', false);

        $m = $this->merchantOf(A::BIZ_WHOLESALE);
        $rep = $this->staffOf($m, 'sales_rep');
        $acc = $this->staffOf($m, 'accountant');

        $path = '/api/v1/amial/merchant/wholesale/invoices/1/void';

        $denied = $this->actingAs($rep, 'api')->postJson($path, ['reason' => 'تجربة']);

        $denied->assertStatus(403);
        // ══════════════════════════════════════════════════════════════
        // **والرمزُ صار أدقَّ لا أضعف.** كان `FORBIDDEN` عامّاً، وصار
        // `WHOLESALE_PERMISSION_REQUIRED` من `EnforceWholesaleAccessPolicy`
        // وحدَها — وهو **مصدرٌ واحدٌ مقيسٌ في الشيفرة**، فيخدم نيّةَ هذا
        // الحارس (أن يكون الرفضُ من محرّك الصلاحيّات لا من حاجزٍ آخر)
        // خدمةً أوثقَ من العامّ الذي قد يصدر من عشرة مواضع.
        // ══════════════════════════════════════════════════════════════
        $this->assertSame('WHOLESALE_PERMISSION_REQUIRED', $denied->json('code'),
            'الرفضُ ليس من محرّك صلاحيّات الجملة — فقد يكون من حاجزٍ آخر، '
            . 'والمقياسُ حينئذٍ يقيس غيرَ ما يدّعي');
        $this->assertNotSame('', (string) $denied->json('message'),
            'رفضٌ بلا رسالةٍ يُرسل الموظّفَ إلى الدعم بلا معلومة');

        // **ومن يملكها يمضي إلى ما بعد الحارس.** والفاتورةُ ١ لا وجودَ
        // لها، فالمتوقَّعُ ٤٠٤ — وهو إثباتُ أنّه **تجاوز** الحارس، لا أنّ
        // البابَ مفتوحٌ للجميع.
        $passed = $this->actingAs($acc, 'api')->postJson($path, ['reason' => 'تجربة']);

        $this->assertNotSame(403, $passed->status(),
            'المحاسبُ يملك الإبطالَ ومُنع — وحاجزٌ يشلّ عملاً سليماً '
            . 'أسوأ من ثغرةٍ تُكتشَف بتدقيق');
        $passed->assertStatus(404);
    }

    /**
     * **منحٌ باعتمادٍ قائمةٌ اليوم، ولكلٍّ أثرُها المقيس.**
     *
     * لا تُخبَّأ في قائمة تجاوزٍ صامتة: **الصمتُ لا يُراجَع، والمكتوبُ
     * يُراجَع.** وهي من جولاتٍ سابقة، ولا تُنزَع من هنا: نزعُها **يوسّع**
     * وصولاً في الإنتاج — وذاك قرارُ صاحب المشروع لا قرارُ إصلاحٍ عابر.
     *
     * @var array<string,string>
     */
    private const DEAD_GRANTS_ALREADY_SHIPPED = [
        'retail/cashier/retail.return.create' =>
            'كاشيرُ التجزئة لا يستطيع إنشاء مرتجعٍ إطلاقاً',
        'retail/warehouse_staff/retail.waste.record' =>
            'موظّفُ المستودع لا يستطيع تسجيل هالكٍ إطلاقاً',
        'fuel/supervisor/fuel.sale.cancel' =>
            'مشرفُ الورديّة لا يستطيع إلغاء بيعةٍ إطلاقاً',
    ];

    /** @test */
    public function no_seeded_grant_requires_an_approval_flow_that_does_not_exist(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **عمودٌ يُكتب ولا يقرؤه أحد.**
        //
        // `assert()` تعُدّ «يحتاج اعتماداً» رفضاً، وتُحيل من أراد المسارَ
        // إلى `evaluate()`. **وقِيس فلا مُنادي لها في المشروع كلِّه** —
        // فمنحةٌ باعتمادٍ منحةٌ ميّتة: تُعرَض في الشاشة، وتُسنَد، ولا
        // تفتح باباً. وذاك «مبنيٌّ ولا يُوصَل إليه» في صورةٍ أخبث، لأنّ
        // الشاشةَ تقول إنّه ممنوح.
        $sources = [
            A::BIZ_FUEL => P::fuelSeedScopes(),
            A::BIZ_RETAIL => P::retailSeedScopes(),
            A::BIZ_PHARMACY => P::pharmacySeedScopes(),
            A::BIZ_WHOLESALE => P::wholesaleSeedScopes(),
            A::BIZ_RESTAURANT => P::restaurantSeedScopes(),
        ];

        $fresh = [];

        foreach ($sources as $vertical => $scopes) {
            foreach ($scopes as $role => $perms) {
                foreach ($perms as $code => $rule) {
                    if (($rule['approval'] ?? 'none') === 'none') {
                        continue;
                    }

                    $key = "{$vertical}/{$role}/{$code}";

                    if (! array_key_exists($key, self::DEAD_GRANTS_ALREADY_SHIPPED)) {
                        $fresh[] = $key;
                    }
                }
            }
        }

        sort($fresh);

        $this->assertSame([], $fresh,
            "منحٌ جديدةٌ باعتمادٍ لا مسارَ له:\n  " . implode("\n  ", $fresh) . "\n\n"
            . "فإمّا يُبنى المسارُ (نداءُ `evaluate` وبناءُ طلبِ اعتماد)،\n"
            . 'وإمّا يُحذف `approval` — ومنحةٌ لا تُستعمَل ليست فصلاً بل شلل.');
    }

    /** @test */
    public function the_dead_grants_are_still_dead_and_not_quietly_forgotten(): void
    {
        // **واستثناءٌ لا يُراجَع يصير ثقباً.** فيُثبَت أنّ الثلاثةَ ما
        // زالت ميّتةً فعلاً — فإن بُني مسارُ الاعتماد يوماً سقط هذا
        // المقياسُ ونُبِّه إلى تحديث القائمة، ولم يبقَ استثناءٌ لعطلٍ
        // انتهى.
        $probes = [
            [A::BIZ_RETAIL, 'cashier', P::RETAIL_RETURN_CREATE],
            [A::BIZ_RETAIL, 'warehouse_staff', P::RETAIL_WASTE_RECORD],
            [A::BIZ_FUEL, 'supervisor', P::FUEL_SALE_CANCEL],
        ];

        $perm = app(MerchantPermissionService::class);
        $alive = [];

        foreach ($probes as [$vertical, $role, $code]) {
            $staff = $this->staffOf($this->merchantOf($vertical), $role);

            // `can` تقول «يملكها»، و`assert` تقول «يستطيعها». **والفرقُ
            // بينهما هو العطل بعينه.**
            $this->assertTrue($perm->can($staff, $code),
                "{$vertical}/{$role} لم يعد يملك {$code} — تغيّر القالب");

            try {
                $perm->assert($staff, $code);
                $alive[] = "{$vertical}/{$role}/{$code}";
            } catch (\DomainException) {
                // ما زالت ميّتة — وهو المتوقَّع اليوم.
            }
        }

        $this->assertSame([], $alive,
            "منحٌ كانت ميّتةً وصارت حيّة:\n  " . implode("\n  ", $alive) . "\n\n"
            . 'يُحدَّث `DEAD_GRANTS_ALREADY_SHIPPED` — واستثناءٌ لعطلٍ انتهى ثقبٌ.');
    }

    /** @test */
    public function the_owner_still_reaches_everything_in_their_own_shop(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرةٍ تُكتشَف بتدقيق.**
        $m = $this->merchantOf(A::BIZ_PHARMACY);
        $perm = app(MerchantPermissionService::class);

        $this->assertTrue($perm->isOwner($m));

        foreach ([P::PHARMACY_PRODUCT_MANAGE, P::PHARMACY_PATIENT_VIEW,
            P::PHARMACY_ALERT_DISMISS, P::PHARMACY_PRESCRIPTION_RECORD] as $code) {
            $this->assertTrue($perm->can($m, $code),
                "المالكُ لا يملك {$code} في صيدليّته");
        }

        $this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/pharmacy/products')
            ->assertOk();
    }
}
