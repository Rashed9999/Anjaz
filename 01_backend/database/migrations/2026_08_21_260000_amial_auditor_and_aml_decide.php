<?php

/**
 * AMIAL-READONLY-AUDITOR-001 — **مدقّقٌ داخليٌّ يقرأ ولا يكتب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «أضيف مدقّقاً داخليّاً — Read Only. يستطيع قراءة Ledger و
 * Audit والتسويات وقرارات AML وIAM، ولا يستطيع تعديل أيّ شيء.»
 *
 * **وأوّلُ ما فعلتُه أن قِستُ هل ذلك ممكنٌ أصلاً** — فوُجد أنّه ليس كذلك:
 *
 *   **خمسةٌ وعشرون مسارَ كتابةٍ محروسةٌ بصلاحيّاتِ قراءةٍ وحدَها.**
 *
 * فمن يُمنح `platform.audit.view` ليقرأ سجلَّ التدقيق **يستطيع بها
 * تعديلَ قواعد مكافحة غسل الأموال، وحسمَ بلاغِ اشتباه، وإرسالَ تقريرٍ
 * رقابيّ، وحظرَ عنوانِ شبكةٍ وفكَّه**. ومن يُمنح `customers.view` يستطيع
 * تنفيذَ إجراءٍ على حساب. ومن يُمنح `transactions.view` يستطيع **إنشاءَ
 * معاملة**.
 *
 * **فدورٌ اسمُه «قراءةً فقط» كان مستحيلاً بناؤه** — أيُّ صلاحيّةِ قراءةٍ
 * تُمنح له تفتح كتابةً معها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وبعضُ هذا من فعلي في هذه الجلسة، ويُقال:**
 *
 * مجموعةُ `aml` كانت **بلا حارسٍ إطلاقاً** — يفتحها كلُّ من يدخل اللوحة.
 * فحرستُها بـ`audit.view` في AMIAL-ADMIN-DOORS-001، وهو تحسينٌ من
 * «لا شيء» إلى «قراءة». **لكنّه ترك قراراتِ الامتثال خلف صلاحيّة قراءة**
 * — وهو نصفُ إصلاح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقسمةُ هي القسمةُ نفسُها المتكرّرة، وقد سمّاها صاحبُ المشروع:**
 *
 * «لا أريد أن يكون فريقُ الامتثال دوراً واحداً يقوم بـKYC وAML والتحقيق
 * وإنشاء البلاغ وإرسال البلاغ. عند زيادة العمليات يجب فصلُ المحقّق عن
 * صاحب قرار الإبلاغ.»
 *
 *   · `platform.aml.investigate` — يفتح تحقيقاً ويُرفق دليلاً ويُغلقه
 *   · `platform.aml.decide`      — **يحسم بلاغاً ويُرسله ويُعدّل القواعد**
 *
 * والمحقّقُ لا يُرسل البلاغَ بنفسه. وهو فصلُ المُعِدّ عن المعتمِد في
 * الامتثال، كما هو في الخزانة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string,array{0:string,1:string}> */
    private const PERMISSIONS = [
        'platform.aml.investigate' => [
            'فتح تحقيق غسل أموال وإرفاق أدلّته', 'platform_decide',
        ],
        'platform.aml.decide' => [
            'حسم بلاغ الاشتباه وإرساله وتعديل قواعد الرصد', 'platform_decide',
        ],
        'platform.security.act' => [
            'حجب عنوان شبكة وفكّه وإغلاق عطل', 'platform_decide',
        ],
    ];

    private const AUDITOR = 'platform_auditor';

    /**
     * **ما يقرؤه المدقّق — ولا شيءَ فيه يفتح كتابة.**
     *
     * ولا تُمنح `customers.pii.reveal`: كشفُ بيانات الاتّصال فعلٌ يُسجَّل
     * على فاعله، ولا يحتاجه من يدقّق أرقاماً وقيوداً. **ومدقّقٌ يكشف
     * هواتفَ العملاء بلا حاجةٍ يزيد سطحَ الخطر بلا أن يزيد قدرتَه.**
     *
     * ولا `merchants.compliance` ولا `merchants.risk` ولا `customers.view`
     * ولا `transactions.view` — **لأنّ كلَّاً منها يحرس مسارَ كتابةٍ اليوم**.
     * وذاك عملٌ باقٍ يُصرَّح به هنا لا يُسكَت عنه: حين تُفصَل كتاباتُها
     * تُضاف قراءاتُها إلى المدقّق.
     *
     * @var list<string>
     */
    private const AUDITOR_READS = [
        'platform.audit.view',
        'platform.money.view',
        'platform.analytics.view',
        'platform.receipts.view',
        'platform.ops.status.view',
        'platform.customers.kyc.view',
        'platform.customers.wallets.view',
        'platform.fees.view',
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

        // ── الدورُ الجديد ─────────────────────────────────────────────
        DB::table('roles')->updateOrInsert(
            ['code' => self::AUDITOR, 'merchant_user_id' => null],
            ['label_ar' => 'التدقيق الداخليّ (قراءة فقط)',
                'is_system' => 1,
                'description' => 'يقرأ الدفتر والتدقيق والتسويات والمجاميع، ولا يكتب شيئاً.',
                'created_at' => $now, 'updated_at' => $now],
        );

        $auditorId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', self::AUDITOR)->value('id');

        if ($auditorId !== null) {
            foreach (self::AUDITOR_READS as $code) {
                $permId = DB::table('permissions')->where('code', $code)->value('id');

                if ($permId !== null) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $auditorId, 'permission_id' => $permId],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }

        // ── قراراتُ الامتثال والأمن ───────────────────────────────────
        //
        // **ولا يفقد أحدٌ ما يفعله اليوم**: من يملك `audit.view` الآن هو
        // من كان يفعل هذه القرارات، فيُمنح صلاحيّاتِها صراحةً. والفصلُ
        // يبدأ من هنا: الأدوارُ الجديدةُ تُمنح إحداهما لا كلتيهما.
        $auditViewId = DB::table('permissions')->where('code', 'platform.audit.view')->value('id');

        $holders = $auditViewId === null ? collect() : DB::table('role_permissions')
            ->where('permission_id', $auditViewId)->pluck('role_id');

        foreach ($holders as $roleId) {
            // **والمدقّقُ يُستثنى** — وهو سببُ وجوده.
            if ((int) $roleId === (int) $auditorId) {
                continue;
            }

            foreach (array_keys(self::PERMISSIONS) as $code) {
                $permId = DB::table('permissions')->where('code', $code)->value('id');

                if ($permId !== null) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $permId],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $auditorId = DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', self::AUDITOR)->value('id');

        if ($auditorId !== null) {
            DB::table('role_permissions')->where('role_id', $auditorId)->delete();
            DB::table('roles')->where('id', $auditorId)->delete();
        }
    }
};
