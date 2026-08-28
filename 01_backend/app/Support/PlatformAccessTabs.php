<?php

namespace App\Support;

/**
 * AMIAL-OPERATOR-TABS-001
 *
 * قاموس واجهة الصلاحيات. الموظف لا يرى رموز middleware ولا يتعامل معها:
 * يختار مساحة عمل مفهومة، ثم قراءة أو كتابة. الخادم وحده هو الذي يحوّل
 * الاختيار إلى الصلاحيات الدقيقة التي تحرس كل مسار.
 */
final class PlatformAccessTabs
{
    /** @return array<string,array{label:string,icon:string,read:list<string>,write:list<string>}> */
    public static function all(): array
    {
        return [
            'customer_support' => ['label' => 'دعم العملاء والحسابات', 'icon' => '👥',
                'read' => ['platform.customers.view', 'platform.transactions.view', 'platform.receipts.view', 'platform.tickets.view'],
                'write' => ['platform.tickets.manage', 'platform.customers.notes.create', 'platform.customers.lifecycle.manage']],
            'merchants' => ['label' => 'التجّار', 'icon' => '🏪',
                'read' => ['platform.merchants.compliance', 'platform.merchants.money', 'platform.merchants.risk'],
                'write' => ['platform.merchants.investigate']],
            'agents' => ['label' => 'الوكلاء والتسويات', 'icon' => '🤝',
                'read' => ['platform.customers.view', 'platform.money.view'],
                'write' => ['platform.treasury.issue', 'platform.settlements.decide']],
            'finance' => ['label' => 'المالية والدفتر', 'icon' => '📚',
                'read' => ['platform.money.view', 'platform.transactions.view', 'platform.fees.view'],
                'write' => ['platform.money.move', 'platform.treasury.issue', 'platform.fees.update']],
            'compliance' => ['label' => 'الامتثال والتحقق', 'icon' => '🪪',
                'read' => ['platform.audit.view', 'platform.customers.kyc.view', 'platform.registrations.view'],
                'write' => ['platform.approvals.decide', 'platform.customers.kyc.request', 'platform.customers.freeze', 'platform.registrations.create']],
            'risk_security' => ['label' => 'المخاطر والأمن', 'icon' => '🛡️',
                'read' => ['platform.audit.view', 'platform.customers.security.view', 'platform.ops.status.view'],
                'write' => ['platform.customers.freeze', 'platform.customers.sessions', 'platform.security.act', 'platform.aml.investigate', 'platform.aml.decide']],
            'platform_services' => ['label' => 'خدمات المنصّة والنزاعات', 'icon' => '🧩',
                'read' => ['platform.transactions.view'],
                'write' => ['platform.disputes.decide']],
            'operations' => ['label' => 'التشغيل والإعدادات', 'icon' => '⚙️',
                'read' => ['platform.ops.view', 'platform.zones.view'],
                'write' => ['platform.ops.retry', 'platform.settings.manage', 'platform.settings.update', 'platform.zones.assign', 'platform.zones.override', 'platform.zones.policy.update']],
            'staff' => ['label' => 'الموظفون والصلاحيات', 'icon' => '🔐',
                'read' => ['platform.staff.view'],
                'write' => ['platform.staff.manage']],
        ];
    }

    /** @return list<string> */
    public static function permissionCodes(string $tab, string $level): array
    {
        $definition = self::all()[$tab] ?? null;
        if (!$definition) return [];

        return array_values(array_unique($level === 'write'
            ? array_merge($definition['read'], $definition['write'])
            : $definition['read']));
    }
}
