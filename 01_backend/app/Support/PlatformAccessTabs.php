<?php

namespace App\Support;

/**
 * AMIAL-OPERATOR-TABS-001 · AMIAL-OPERATOR-GRAIN-002
 *
 * قاموس واجهة الصلاحيات. الموظف لا يرى رموز middleware ولا يتعامل معها:
 * يختار ما يحتاجه بالعربيّة، والخادم وحده يحوّل الاختيار إلى الصلاحيات
 * الدقيقة التي تحرس كلّ مسار.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الحبّةُ صارت صلاحيّةً لا تبويباً — وهذا ما دُفع ثمنُه.**
 *
 * قاله صاحب المشروع أمام الشاشة: «ليس هذا ما أردته، أردتُ جعل الأمر
 * أكثر تفصيلاً. مثلاً في الامتثال والمخاطر قد أحتاج إلى موظّف يرى **سجلّ
 * التدقيق فقط** من هذه القائمة، بينما في الصلاحيات يمكنك فقط اختيار
 * **القائمة كاملة**».
 *
 * وكان محقّاً: `compliance:read` كانت تمنح ثلاثَ صلاحيّاتٍ دفعةً واحدة —
 * سجلَّ التدقيق **ومراجعةَ الهويّة وملفّاتِ الفتح معها**. فمن أراد
 * مدقّقاً يقرأ السجلَّ وحدَه **لم يكن أمامه إلّا أن يفتح له ملفّات
 * العملاء كلَّها**. وأقلُّ امتيازٍ ممكن مبدأٌ مكتوبٌ في مهارة الصلاحيّات،
 * وكانت الشاشةُ تمنعه.
 *
 * فصار كلُّ سطرٍ هنا **صلاحيّةً واحدةً باسمها العربيّ**، والتبويبُ
 * مجموعةٌ للعرض لا وحدةَ منح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وسبعَ عشرةَ فجوةً قِيست في القاموس نفسِه، وأُغلقت هنا.**
 *
 * قِيس ما تمنحه هذه الشاشةُ مقابل ما يُفحَص فعلاً في الشيفرة (وسيطاً
 * كان أو فحصاً داخل متحكّم)، فخرج:
 *
 *   · **ستَّ عشرةَ صلاحيّةً تحرس عملاً حقيقيّاً ولا تمنحها أيُّ شاشة** —
 *     أي أنّ الفعلَ محروسٌ ولا سبيلَ لأحدٍ أن يُؤذَن به. وفيها **رادارُ
 *     ساهر كلُّه** (خمسةُ رموز): لا يستطيع موظّفٌ أن يراه مهما أُعطي،
 *     ولا يفتحه إلّا من يتجاوز الفحصَ أصلاً. ومعها كشفُ البيانات
 *     الشخصيّة، ومحافظُ العميل، وتصفيرُ الرمز السرّي، وطلباتُ الإغلاق
 *     والوفاة ورفعِ التجميد وتعديلِ الحدود.
 *   · **وواحدةٌ تُمنَح ولا تُفحَص في أيّ موضع** (`platform.receipts.view`)
 *     — مربّعٌ يُؤشَّر ولا يفتح شيئاً. وحُذفت: **صلاحيّةٌ لا تحرس شيئاً
 *     تُعلّم من يمنحها أنّ التأشيرَ بلا أثر**، فيؤشّر الباقيَ بلا نظر.
 *
 * والحارس: `PlatformPermissionCatalogGuardTest` — يقارن المصدرين في
 * الاتّجاهين، فلا تُضاف صلاحيّةٌ في الشيفرة وتُنسى هنا، ولا تبقى هنا
 * واحدةٌ لا تحرس شيئاً.
 */
final class PlatformAccessTabs
{
    /**
     * @return array<string,array{
     *     label:string, icon:string,
     *     read:array<string,string>, write:array<string,string>}>
     */
    public static function all(): array
    {
        return [
            'executive' => ['label' => 'نظرة عامة', 'icon' => '📊',
                'read' => [
                    'platform.analytics.view' => 'لوحة القيادة والمؤشّرات',
                ],
                'write' => []],

            'customer_support' => ['label' => 'دعم العملاء والحسابات', 'icon' => '👥',
                'read' => [
                    'platform.customers.view' => 'ملفّات العملاء والبحث',
                    'platform.transactions.view' => 'سجلّ العمليّات',
                    'platform.customers.wallets.view' => 'أرصدة محافظ العميل',
                    'platform.customers.notifications.view' => 'إشعارات العميل المُرسَلة',
                    'platform.tickets.view' => 'تذاكر الدعم',
                ],
                'write' => [
                    'platform.tickets.manage' => 'الردّ على التذاكر وإغلاقها',
                    'platform.customers.notes.create' => 'كتابة ملاحظةٍ في ملفّ العميل',
                    'platform.customers.lifecycle.manage' => 'إنشاء حسابٍ وتعديلُ بياناته',
                    // **يُفصَل عن سابقه عمداً**: من يصفّر الرمزَ السرّيّ
                    // يستطيع الدخولَ بحساب العميل. وكان مضموماً إلى
                    // «دعم العملاء» كتلةً واحدة — وهو ما جعل موظّفَ
                    // الدعم قادراً على تصفير رمز عميلٍ سرّيّ.
                    'platform.customers.reset_pin' => 'تصفير الرمز السرّيّ للعميل',
                    'platform.customers.pii.reveal' => 'كشف البيانات الشخصيّة كاملةً (يُسجَّل)',
                    'platform.customers.limits.update' => 'تعديل حدود العميل',
                    'platform.customers.unfreeze.request' => 'طلب رفع التجميد',
                    'platform.customers.close.request' => 'طلب إغلاق حساب',
                    'platform.customers.deceased.request' => 'طلب تسجيل وفاة صاحب الحساب',
                ]],

            'merchants' => ['label' => 'التجّار', 'icon' => '🏪',
                'read' => [
                    'platform.merchants.compliance' => 'ملفّ التاجر وامتثالُه',
                    'platform.merchants.money' => 'مبيعات التاجر وتسوياتُه',
                    'platform.merchants.risk' => 'مخاطر التاجر',
                ],
                'write' => [
                    'platform.merchants.investigate' => 'فتح تحقيقٍ على تاجر',
                    'platform.risk.investigations.create' => 'رفع درجة المخاطر وفتح تحقيق',
                ]],

            'agents' => ['label' => 'الوكلاء والتسويات', 'icon' => '🤝',
                'read' => [
                    'platform.customers.view' => 'ملفّات الوكلاء',
                    'platform.money.view' => 'أرصدة الوكلاء وسيولتُهم',
                ],
                'write' => [
                    'platform.treasury.issue' => 'إصدار سيولةٍ للوكيل',
                    'platform.settlements.decide' => 'اعتماد التسويات ورفضُها',
                ]],

            'finance' => ['label' => 'المالية والدفتر', 'icon' => '📚',
                'read' => [
                    'platform.money.view' => 'الأرصدة والخزانة',
                    'platform.transactions.view' => 'سجلّ العمليّات',
                    'platform.fees.view' => 'الرسوم والعمولات',
                ],
                'write' => [
                    'platform.money.move' => 'تحريك المال بين المحافظ',
                    'platform.treasury.issue' => 'إصدار سيولة',
                    'platform.fees.update' => 'تعديل الرسوم',
                ]],

            'compliance' => ['label' => 'الامتثال والتحقق', 'icon' => '🪪',
                'read' => [
                    // **هذا هو السطرُ الذي طُلب بعينه**: مدقّقٌ يقرأ
                    // السجلَّ وحدَه، بلا ملفّاتِ عملاءَ ولا هويّات.
                    'platform.audit.view' => 'سجلّ التدقيق',
                    'platform.customers.kyc.view' => 'مراجعة وثائق الهويّة',
                    'platform.registrations.view' => 'ملفّات فتح الحسابات',
                ],
                'write' => [
                    'platform.approvals.decide' => 'اعتماد التوثيق ورفضُه',
                    'platform.customers.kyc.request' => 'طلب وثائقَ ورفعُها',
                    'platform.customers.freeze' => 'تجميد حسابٍ وفكُّه',
                    'platform.registrations.create' => 'إنشاء ملفّ فتح حساب',
                ]],

            'risk_security' => ['label' => 'المخاطر والأمن', 'icon' => '🛡️',
                'read' => [
                    'platform.audit.view' => 'سجلّ التدقيق',
                    'platform.customers.security.view' => 'أجهزة العميل وجلساتُه',
                    'platform.ops.status.view' => 'حالة النظام',
                    // رادارُ ساهر — وكان محروساً بلا سبيلٍ إلى منحه.
                    'saher.view' => 'رادار ساهر — الشاشة',
                    'saher.findings.view' => 'تفاصيل الاكتشافات',
                    'saher.evidence.view' => 'أدلّة الاكتشاف (شيفرةٌ ومسارات)',
                ],
                'write' => [
                    'platform.customers.freeze' => 'تجميد حسابٍ وفكُّه',
                    'platform.customers.sessions' => 'إنهاء جلسات العميل',
                    'platform.security.act' => 'إجراءاتُ الأمن',
                    'platform.aml.investigate' => 'تحقيقُ غسل الأموال',
                    'platform.aml.decide' => 'حسمُ بلاغ غسل الأموال',
                    'saher.scan.run' => 'تشغيل جولة فحصٍ يدويّة',
                    'saher.findings.suppress' => 'كتمُ اكتشافٍ أو الحكمُ عليه',
                ]],

            'platform_services' => ['label' => 'خدمات المنصّة والنزاعات', 'icon' => '🧩',
                'read' => [
                    'platform.transactions.view' => 'سجلّ العمليّات',
                ],
                'write' => [
                    'platform.disputes.decide' => 'حسمُ النزاعات',
                ]],

            'operations' => ['label' => 'التشغيل والإعدادات', 'icon' => '⚙️',
                'read' => [
                    'platform.ops.view' => 'المهامّ والطوابير',
                    'platform.zones.view' => 'المناطق التشغيليّة',
                    'platform.zones.audit.view' => 'سجلّ تغيّر المناطق',
                ],
                'write' => [
                    'platform.ops.retry' => 'إعادة تشغيل مهمّةٍ متعثّرة',
                    'platform.settings.manage' => 'إدارة الإعدادات',
                    'platform.settings.update' => 'تعديل الإعدادات',
                    'platform.zones.assign' => 'إسناد منطقةٍ لحساب',
                    'platform.zones.override' => 'تجاوز المنطقة يدويّاً',
                    'platform.zones.policy.update' => 'تعديل سياسة المناطق',
                ]],

            'staff' => ['label' => 'الموظفون والصلاحيات', 'icon' => '🔐',
                'read' => [
                    'platform.staff.view' => 'قائمة الموظّفين وصلاحيّاتهم',
                ],
                'write' => [
                    'platform.staff.manage' => 'إنشاء موظّفٍ ومنحُ الصلاحيات',
                ]],
        ];
    }

    /**
     * رموزُ تبويبٍ عند مستوىً — يبقى للتوافق ولمنحِ المجموعة دفعةً واحدة.
     *
     * @return list<string>
     */
    public static function permissionCodes(string $tab, string $level): array
    {
        $definition = self::all()[$tab] ?? null;
        if (!$definition) return [];

        return array_values(array_unique($level === 'write'
            ? array_merge(array_keys($definition['read']), array_keys($definition['write']))
            : array_keys($definition['read'])));
    }

    /**
     * كلُّ صلاحيّةٍ يمكن منحُها، ومعها موضعُها ومستواها واسمُها.
     *
     * **والصلاحيّةُ الواحدةُ قد تقع في تبويبين** (سجلُّ التدقيق في
     * الامتثال وفي المخاطر) — فتُعاد أوّلُ مواضعها ولا تُكرَّر، لأنّ
     * المنحَ يقع على الرمز لا على موضع عرضه.
     *
     * @return array<string,array{label:string,tab:string,level:string}>
     */
    public static function allPermissions(): array
    {
        $out = [];

        foreach (self::all() as $tab => $definition) {
            foreach (['read', 'write'] as $level) {
                foreach ($definition[$level] as $code => $label) {
                    $out[$code] ??= ['label' => $label, 'tab' => $tab, 'level' => $level];
                }
            }
        }

        return $out;
    }

    /** هل هذا الرمزُ ممنوحٌ من هذه الشاشة أصلاً؟ */
    public static function isGrantable(string $code): bool
    {
        return isset(self::allPermissions()[$code]);
    }
}
