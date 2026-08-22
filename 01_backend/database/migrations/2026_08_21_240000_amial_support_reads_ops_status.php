<?php

/**
 * AMIAL-SUPPORT-REACH-001 — **«أالنظامُ يعمل؟» ليست «أرِني المهامّ الفاشلة».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن:** الحالةُ ٣٧ في وثيقة حالات الدعم — «التطبيق متوقف، هل ما
 * زالت العمليات تنفذ؟» — والمطلوبُ فيها صريح: **«operational/system
 * status لا التخمين»**.
 *
 * وموظّفُ دعمٍ يجيب من عنده أسوأ من صمته: يقول «العمليّاتُ تعمل» فيُعيد
 * العميلُ إرسالَ تحويلٍ نُفِّذ، أو يقول «متوقّفة» فيذهب إلى وكيلٍ بلا حاجة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأوّلُ علاجٍ كتبتُه كان خطأً، وأمسكه اختبارٌ قائم.**
 *
 * منحتُ الدعمَ `platform.ops.view` — فسقط `OpsConsoleTest`:
 *
 *   > «الدعم لا يفتحها: ليست من عمله، وفتحُها يُطلعه على ما لا يخصّه.»
 *
 * **وذاك عقدٌ مقصودٌ مكتوبٌ سببُه، لا اختبارٌ كسول.** فـ`ops.view` تفتح
 * وحدةَ التشغيل كاملةً: المهامُّ الفاشلةُ بأسماء صفوفها وآثارِ أخطائها.
 * وهي أكثرُ بكثيرٍ ممّا تطلبه الحالةُ ٣٧.
 *
 * **فالحبّةُ تُفصَل ولا يُكسَر العقد** — وهو النمطُ نفسُه المتكرّر في هذه
 * الجلسة: `zones.override` عن `zones.assign`، و`analytics.view` عن
 * `transactions.view`، والآن **حالةُ التشغيل عن وحدة التشغيل**.
 *
 *   · `platform.ops.status.view` — أالطوابيرُ تسير؟ كم نُفِّذ اليوم؟
 *   · `platform.ops.view`        — وحدةُ التشغيل: المهامُّ الفاشلةُ وتفاصيلُها
 *   · `platform.ops.retry`       — إعادةُ تشغيلها
 *
 * والدعمُ يأخذ **الأولى وحدَها**.
 *
 * **ولا يفقد أحدٌ شيئاً:** كلُّ من يملك `ops.view` اليوم يُمنح الحالةَ
 * معها — فحاجزٌ أضيقُ لا يُشلّ به عملٌ قائم.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'platform.ops.status.view';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['code' => self::CODE],
            [
                'label_ar' => 'قراءة حالة التشغيل (أالنظام يعمل؟)',
                'category' => 'platform_read',
                'created_at' => $now, 'updated_at' => $now,
            ],
        );

        $permId = DB::table('permissions')->where('code', self::CODE)->value('id');
        $opsViewId = DB::table('permissions')->where('code', 'platform.ops.view')->value('id');

        if ($permId === null) {
            return;
        }

        // ① كلُّ من يملك وحدةَ التشغيل يملك حالتَها — فلا يُشلّ عملٌ قائم.
        $roleIds = $opsViewId === null ? collect() : DB::table('role_permissions')
            ->where('permission_id', $opsViewId)->pluck('role_id');

        // ② والدعمُ يُضاف إليهم — وهو سببُ هذه الهجرة.
        $supportId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', 'platform_support')->value('id');

        if ($supportId !== null) {
            $roleIds = $roleIds->push($supportId);
        }

        foreach ($roleIds->unique() as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
