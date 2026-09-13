<?php

namespace App\Services\Reporting;

/**
 * AMIAL-REPORTING-CENTER-001 — كتالوج واحد بدل جزر تقارير متفرقة.
 *
 * هذا الملف لا يحسب أرقاماً مالية. هو فهرس تشغيلي يصف التقارير ومصادرها
 * وحالتها كي تعرف الإدارة ما هو جاهز وما هو ناقص بدون ادعاء اكتمال.
 */
class ReportCatalogService
{
    public function catalog(): array
    {
        return [
            'financial_core' => [
                'label' => 'القوائم المالية والدفتر',
                'reports' => [
                    ['code' => 'trial_balance', 'label' => 'ميزان المراجعة', 'status' => 'ready', 'source' => 'LedgerReportService', 'priority' => 'P0'],
                    ['code' => 'income_statement', 'label' => 'قائمة الدخل', 'status' => 'ready', 'source' => 'FinancialStatementsService', 'priority' => 'P0'],
                    ['code' => 'balance_sheet', 'label' => 'الميزانية العمومية', 'status' => 'ready', 'source' => 'FinancialStatementsService', 'priority' => 'P0'],
                    ['code' => 'cash_flow', 'label' => 'التدفق النقدي', 'status' => 'missing', 'source' => 'Ledger + Treasury classification', 'priority' => 'P0'],
                    ['code' => 'general_ledger', 'label' => 'دفتر الأستاذ', 'status' => 'partial', 'source' => 'LedgerReportService statements', 'priority' => 'P0'],
                ],
            ],
            'reconciliation_treasury' => [
                'label' => 'المصالحة والخزينة والسيولة',
                'reports' => [
                    ['code' => 'wallet_reconciliation', 'label' => 'مطابقة المحافظ بالدفتر', 'status' => 'ready', 'source' => 'LedgerReportService', 'priority' => 'P0'],
                    ['code' => 'reconciliation_cases', 'label' => 'قضايا فروقات المصالحة', 'status' => 'ready', 'source' => 'ReconciliationCaseService', 'priority' => 'P0'],
                    ['code' => 'liquidity_position', 'label' => 'مركز السيولة', 'status' => 'partial', 'source' => 'Treasury + Wallets + Settlements', 'priority' => 'P0'],
                    ['code' => 'safeguarded_funds', 'label' => 'أموال العملاء مقابل الغطاء', 'status' => 'missing', 'source' => 'Treasury + Ledger', 'priority' => 'P0'],
                ],
            ],
            'transactions' => [
                'label' => 'المعاملات والحركة المالية',
                'reports' => [
                    ['code' => 'transaction_volume', 'label' => 'حجم وعدد المعاملات', 'status' => 'partial', 'source' => 'transactions + ledger', 'priority' => 'P0'],
                    ['code' => 'failed_reversed_pending', 'label' => 'الفاشلة والعكسية والمعلقة', 'status' => 'missing', 'source' => 'transactions + audit', 'priority' => 'P0'],
                    ['code' => 'fees_commissions', 'label' => 'الرسوم والعمولات', 'status' => 'partial', 'source' => 'FeeService + ledger', 'priority' => 'P0'],
                ],
            ],
            'merchant' => [
                'label' => 'التجار والقطاعات',
                'reports' => [
                    ['code' => 'merchant_financial_truth', 'label' => 'الحقيقة المالية للتاجر', 'status' => 'ready', 'source' => 'MerchantFinancialTruthReportService', 'priority' => 'P1'],
                    ['code' => 'merchant_profit', 'label' => 'ربحية التاجر', 'status' => 'ready', 'source' => 'CashierService', 'priority' => 'P1'],
                    ['code' => 'inventory_valuation', 'label' => 'تقييم وحركة المخزون', 'status' => 'missing', 'source' => 'Retail/vertical inventory', 'priority' => 'P1'],
                    ['code' => 'credit_aging', 'label' => 'تقادم الديون والتحصيل', 'status' => 'partial', 'source' => 'Credit + WholesaleReportsService', 'priority' => 'P0'],
                    ['code' => 'vertical_performance', 'label' => 'أداء الصيدلية والوقود والمطعم والتجزئة والجملة والبيع السريع', 'status' => 'partial', 'source' => 'Vertical services', 'priority' => 'P1'],
                ],
            ],
            'customers_agents' => [
                'label' => 'العملاء والوكلاء',
                'reports' => [
                    ['code' => 'customer_statement', 'label' => 'كشف حساب العميل', 'status' => 'partial', 'source' => 'customer transactions', 'priority' => 'P0'],
                    ['code' => 'customer_activity', 'label' => 'نشاط واحتفاظ العملاء', 'status' => 'missing', 'source' => 'users + transactions', 'priority' => 'P1'],
                    ['code' => 'agent_daily_close', 'label' => 'إغلاق الوكيل اليومي', 'status' => 'ready', 'source' => 'AgentShift/Settlement services', 'priority' => 'P1'],
                    ['code' => 'agent_float', 'label' => 'سيولة الوكيل والعجز', 'status' => 'partial', 'source' => 'agent wallet + cash + ledger', 'priority' => 'P1'],
                ],
            ],
            'risk_compliance' => [
                'label' => 'الامتثال والمخاطر والتدقيق',
                'reports' => [
                    ['code' => 'kyc_pipeline', 'label' => 'KYC والتحقق والمدة', 'status' => 'missing', 'source' => 'KYC services', 'priority' => 'P1'],
                    ['code' => 'aml_regulatory', 'label' => 'AML وSTR/CTR', 'status' => 'partial', 'source' => 'AmlRegulatoryReportService', 'priority' => 'P1'],
                    ['code' => 'audit_sensitive_actions', 'label' => 'الإجراءات الحساسة والتدقيق', 'status' => 'partial', 'source' => 'AuditService', 'priority' => 'P1'],
                    ['code' => 'rbac_changes', 'label' => 'تغييرات الصلاحيات والأدوار', 'status' => 'missing', 'source' => 'roles + permissions + audit', 'priority' => 'P1'],
                ],
            ],
            'operations' => [
                'label' => 'التشغيل والتقنية',
                'reports' => [
                    ['code' => 'system_health_history', 'label' => 'تاريخ صحة النظام وSLA', 'status' => 'partial', 'source' => 'health + alerts', 'priority' => 'P1'],
                    ['code' => 'jobs_queues', 'label' => 'الطوابير والمهام الفاشلة', 'status' => 'partial', 'source' => 'queue/jobs', 'priority' => 'P1'],
                    ['code' => 'email_otp', 'label' => 'البريد وOTP والتسليم', 'status' => 'ready', 'source' => 'otp_challenges', 'priority' => 'P2'],
                    ['code' => 'support_sla', 'label' => 'الدعم وSLA وزمن الحل', 'status' => 'missing', 'source' => 'support tickets', 'priority' => 'P2'],
                    ['code' => 'subscriptions', 'label' => 'الباقات والاشتراكات وMRR/Churn', 'status' => 'missing', 'source' => 'plans + subscriptions', 'priority' => 'P1'],
                ],
            ],
        ];
    }

    public function summary(): array
    {
        $counts = ['ready' => 0, 'partial' => 0, 'missing' => 0, 'total' => 0];

        foreach ($this->catalog() as $domain) {
            foreach ($domain['reports'] as $report) {
                $counts['total']++;
                $counts[$report['status']]++;
            }
        }

        return $counts;
    }
}
