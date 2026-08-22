<?php

/**
 * AMIAL-SECURITY-ANALYST-001 — **الصيانةُ ليست الأمن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «أرى حاجةً إلى محلّل أمن/Security Operations مستقلّ. فريقُ
 * الصيانة ليس الشخصَ المناسب للتحقيق في اختراق حساب أو Sentinel أو جلسة
 * مشبوهة. كما أنّ دورَ الصيانة تاريخيّاً يملك حتّى `transactions.view`،
 * وهو وصولٌ لا يحتاجه كلُّ مهندس تشغيل.»
 *
 * وقِيس فصدق الأمران:
 *
 *   platform_maintenance : ops.retry · ops.status.view · ops.view
 *                          · **transactions.view**
 *
 * ونزعُ الأخيرة يُفقده خمسَ عشرةَ صفحةَ قراءة، فيها **أدلّةُ الدفع
 * الآمن** (`safe-payments.evidence-file`) وكشفُ المعاملات كلِّه وتصديرُه.
 * **ومهندسُ تشغيلٍ يقرأ أدلّةَ نزاعٍ بين عميلين وصولٌ لا يحتاجه** — وإن
 * احتاج تتبّعَ معاملةٍ بعينها فبمنحٍ مؤقّتٍ أو بدور التدقيق.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وتصحيحٌ لهجرةٍ كتبتُها قبل ساعة.**
 *
 * `AMIAL-READONLY-AUDITOR-001` منحت `aml.decide` و`aml.investigate` و
 * `security.act` **لكلّ من يملك `audit.view`** — بحجّة «لا يفقد أحدٌ ما
 * يفعله اليوم». والحجّةُ صحيحةٌ في ظاهرها وخاطئةٌ في أثرها: **ما كان
 * يفعله هؤلاء اليوم هو العطلُ نفسُه** الذي جاءت الهجرةُ لتُصلحه.
 *
 * فصار المُشرِفُ العامُّ يُعدّل قواعد مكافحة غسل الأموال «حفاظاً على
 * القائم» — **وحفظُ عطلٍ ليس حفظَ عمل**.
 *
 * فتُضبَط المنح:
 *
 *   · `aml.decide`      → الإدارة · الامتثال          (لا المُشرِف ولا المخاطر)
 *   · `aml.investigate` → الإدارة · الامتثال · المخاطر
 *   · `security.act`    → الإدارة · المخاطر · **الأمن** (لا المُشرِف ولا الامتثال)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والدورُ الجديد يقرأ ما يُحقَّق به فقط.**
 *
 * جلساتُ الحساب وأجهزتُه وأحداثُ الأمن وسجلُّ التدقيق — **ولا يقرأ
 * معاملاتٍ ولا أرصدةً ولا مجاميعَ منصّة**. فالتحقيقُ في اختراقٍ لا يحتاج
 * كشفَ حساب، ومن احتاجه صعّد إلى المخاطر.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SECURITY = 'platform_security';

    /**
     * **ما يقرأ به محلّلُ الأمن.**
     *
     * و`customers.sessions` فيها فعلٌ (إنهاءُ الجلسات وحظرُ جهاز) —
     * **وهو عملُه بعينه**: من يحقّق في اختراقٍ يجب أن يقطع الوصولَ فوراً،
     * وانتظارُ دورٍ آخرَ يترك المهاجمَ داخلاً.
     *
     * @var list<string>
     */
    private const SECURITY_GRANTS = [
        'platform.audit.view',
        'platform.security.act',
        'platform.customers.security.view',
        'platform.customers.sessions',
        'platform.ops.status.view',
    ];

    /** الصلاحيّة ← من يملكها **وحدَهم** بعد هذه الهجرة. */
    private const EXACT_HOLDERS = [
        'platform.aml.decide' => ['platform_admin', 'platform_compliance'],
        'platform.aml.investigate' => ['platform_admin', 'platform_compliance', 'platform_risk'],
        'platform.security.act' => ['platform_admin', 'platform_risk', self::SECURITY],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['code' => self::SECURITY, 'merchant_user_id' => null],
            [
                'label_ar' => 'عمليّات الأمن',
                'is_system' => 1,
                'description' => 'يحقّق في اختراق الحسابات والجلسات المشبوهة، '
                    . 'ويحجب ويفكّ — ولا يقرأ معاملات ولا أرصدة.',
                'created_at' => $now, 'updated_at' => $now,
            ],
        );

        $secId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', self::SECURITY)->value('id');

        if ($secId !== null) {
            foreach (self::SECURITY_GRANTS as $code) {
                $permId = DB::table('permissions')->where('code', $code)->value('id');

                if ($permId !== null) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $secId, 'permission_id' => $permId],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }

        // ── ضبطُ المنح: تُنزَع ممّن ليس في القائمة وتُمنح لمن فيها ────
        foreach (self::EXACT_HOLDERS as $code => $roleCodes) {
            $permId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permId === null) {
                continue;
            }

            $keep = DB::table('roles')->whereNull('merchant_user_id')
                ->whereIn('code', $roleCodes)->pluck('id');

            DB::table('role_permissions')->where('permission_id', $permId)
                ->whereNotIn('role_id', $keep->all() ?: [0])->delete();

            foreach ($keep as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        // ── والصيانةُ لا تقرأ معاملاتِ العملاء ────────────────────────
        $maintId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_maintenance')->value('id');
        $txId = DB::table('permissions')
            ->where('code', 'platform.transactions.view')->value('id');

        if ($maintId !== null && $txId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $maintId)->where('permission_id', $txId)->delete();
        }
    }

    public function down(): void
    {
        $secId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', self::SECURITY)->value('id');

        if ($secId !== null) {
            DB::table('role_permissions')->where('role_id', $secId)->delete();
            DB::table('roles')->where('id', $secId)->delete();
        }

        // **ولا يُعاد `transactions.view` للصيانة في التراجع.** إعادتُه
        // تُعيد وصولاً قُرّر نزعُه، والتراجعُ يُزيل ما أضافته الهجرةُ
        // لا يُحيي ما حذفته عمداً.
    }
};
