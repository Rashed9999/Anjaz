<?php

namespace App\Support;

/**
 * قاموس واجهة صلاحيات موظفي المنصة.
 *
 * كل سطر هنا صلاحية مستقلة؛ التبويب تجميع بصري فقط. يجب أن يبقى هذا
 * القاموس متطابقاً مع الصلاحيات التي تفحصها الـmiddleware لأن
 * PlatformPermissionCatalogGuardTest يقارن الطرفين.
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
                    'platform.email.view' => 'مركز البريد والتحقق وسجلّ التسليم',
                ],
                'write' => [
                    'platform.tickets.manage' => 'الردّ على التذاكر وإغلاقها',
                    'platform.customers.notes.create' => 'كتابة ملاحظةٍ في ملفّ العميل',
                    'platform.customers.lifecycle.manage' => 'إنشاء حسابٍ وتعديلُ بياناته',
                    'platform.customers.reset_pin' => 'تصفير الرمز السرّيّ للعميل',
                    'platform.customers.pii.reveal' => 'كشف البيانات الشخصيّة كاملةً (يُسجَّل)',
                    'platform.customers.limits.update' => 'تعديل حدود العميل',
                    'platform.customers.unfreeze.request' => 'طلب رفع التجميد',
                    'platform.customers.close.request' => 'طلب إغلاق حساب',
                    'platform.customers.deceased.request' => 'طلب تسجيل وفاة صاحب الحساب',
                    'platform.email.manage' => 'إبطال تحديات التحقق البريدية النشطة',
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
     * رموز تبويب عند مستوى — للتوافق ولمنح المجموعة دفعة واحدة.
     *
     * @return list<string>
     */
    public static function permissionCodes(string $tab, string $level): array
    {
        $definition = self::all()[$tab] ?? null;
        if (! $definition) {
            return [];
        }

        return array_values(array_unique($level === 'write'
            ? array_merge(array_keys($definition['read']), array_keys($definition['write']))
            : array_keys($definition['read'])));
    }

    /** @return array<string,array{label:string,tab:string,level:string}> */
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

    public static function isGrantable(string $code): bool
    {
        return isset(self::allPermissions()[$code]);
    }
}
