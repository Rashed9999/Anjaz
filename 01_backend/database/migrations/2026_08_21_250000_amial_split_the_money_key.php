<?php

/**
 * AMIAL-MONEY-KEY-SPLIT-001 — **تسعةٌ وأربعون مساراً خلف مفتاحٍ واحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *   platform.money.move   →  ٤٩ مساراً  →  platform_admin وحدَه
 *   platform.fees.view    →                platform_admin وحدَه
 *   platform.fees.update  →                platform_admin وحدَه
 *
 *   platform_finance      →  ٦ صلاحيّاتٍ ليس فيها واحدةٌ من الثلاث
 *
 * **ففريقُ المالية لا يقرأ تقريرَ ربحٍ ولا يعتمد تسويةً ولا يشحن وكيلاً.**
 * ومن يفعل ذلك كلَّه هو **مديرُ المنصّة** — أي أنّ من يدير النظامَ
 * تقنيّاً وإداريّاً هو صاحبُ مفتاح تحريك المال.
 *
 * **وقد ضاعفتُ هذا التركيزَ بنفسي في هذه الجلسة**: حرستُ `hub/transfer`
 * و`agents/credit` و`agents/daily/*` بـ`money.move` — فأغلقتُ أبواباً
 * وزدتُ ما يفتحه المفتاحُ الواحد. **إغلاقُ ثغرةٍ بمفتاحٍ عامٍّ يُنشئ
 * مشكلةً أخرى.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والوجهُ الثاني للتركيز، وهو الأخفى:**
 *
 * تسعَ عشرةَ **قراءةً** كانت خلف `money.move` — لوحةُ المركز الماليّ،
 * وقوائمُ التسويات، والفواتيرُ وتصديرُها، ومصاريفُ المنصّة. فمن احتاج
 * **قراءةَ** تقريرٍ وجب منحُه **مفتاحَ تحريك المال**.
 *
 * فالمفتاحُ العامُّ لا يمنع غيرَ المخوَّل فحسب — **بل يُجبر على توسيع من
 * يحمله**، فيتسرّب بابُ التحريك مع كلّ منحِ قراءة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القسمة — بالفعل لا بالشاشة:**
 *
 * | الصلاحيّة | ما تفتح | كم مساراً |
 * |---|---|---|
 * | `money.view` | **قراءةُ** الشاشات الماليّة كلِّها | ١٩ |
 * | `settlements.decide` | اعتمادُ تسويةٍ ورفضُها وإقفالُ يوم | ٩ |
 * | `treasury.issue` | **خلقُ رصيدٍ وشحنُه وتحويلُه** | ٤ |
 * | `disputes.decide` (قائمةٌ سلفاً) | حسمُ الدفع الآمن | ٣ |
 * | `settings.update` | حدودُ واتساب — ليست مالاً أصلاً | ٣ |
 * | `money.move` | ما تبقّى | ١٢ |
 *
 * **ولمَ `treasury.issue` مفردةٌ عن `settlements.decide`:** اعتمادُ
 * تسويةٍ فعلٌ يوميٌّ متكرّر، وخلقُ رصيدٍ فعلٌ نادرٌ يزيد المعروضَ في
 * المنصّة. وجمعُهما يجعل منحَ الأوّل منحاً للثاني — وهو ما يقع دائماً
 * حين تُخلط الحبّات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما لم يُفعل هنا، ويُقال لئلّا يُظنّ مفعولاً:**
 *
 * **لم تُنزَع `money.move` من `platform_admin`.** ونزعُها قرارُ صاحب
 * المشروع لا قرارُ شيفرة: يُوقف عملاً قائماً اليوم إن لم يكن هناك من
 * يحمل البديل. **والقسمةُ تجعل النزعَ ممكناً** — وقبلها كان مستحيلاً.
 *
 * **والمُعِدُّ والمعتمِدُ على التسويات لم يُفصَلا بعد.** النمطُ مبنيٌّ في
 * `PlatformTreasuryService::requestIssuance()` (أربعُ عيونٍ وعتبةٌ
 * و`hasAnotherApprover`) — وتوسعتُه إلى التسويات عملٌ قائمٌ بذاته.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string,array{0:string,1:string}> */
    private const PERMISSIONS = [
        'platform.money.view' => [
            'قراءة الشاشات المالية (بلا تحريك)', 'platform_read',
        ],
        'platform.settlements.decide' => [
            'اعتماد التسويات ورفضها وإقفال اليوم', 'platform_money',
        ],
        'platform.treasury.issue' => [
            'إصدار رصيد المنصّة وشحنه وتحويله', 'platform_money',
        ],
    ];

    /** @var array<string,list<string>> */
    private const GRANTS = [
        'platform_admin' => [
            'platform.money.view', 'platform.settlements.decide',
            'platform.treasury.issue',
        ],
        // **وهذا جوهرُ الهجرة**: فريقُ المالية يعمل عملَ المالية.
        'platform_finance' => [
            'platform.money.view', 'platform.settlements.decide',
            'platform.treasury.issue', 'platform.fees.view',
        ],
        // يقرؤون ولا يقرّرون.
        'platform_supervisor' => ['platform.money.view', 'platform.fees.view'],
        'platform_compliance' => ['platform.money.view'],
        'platform_risk' => ['platform.money.view'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $code => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label_ar' => $label, 'category' => $category,
                    'created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')
                ->whereNull('merchant_user_id')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($codes as $code) {
                $permId = DB::table('permissions')->where('code', $code)->value('id');

                if ($permId === null) {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
