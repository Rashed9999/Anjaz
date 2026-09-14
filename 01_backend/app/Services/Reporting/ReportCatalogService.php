<?php

namespace App\Services\Reporting;

/**
 * AMIAL-REPORTING-CENTER-001/004 — كتالوج واحد بدل جزر تقارير متفرقة.
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
                    ['code' => 'trial_balance', 'label' => 'ميزان المراجعة', 'status' => 'ready', 'source' => 'FinancialStatementsService ← ledger lines', 'priority' => 'P0'],
                    ['code' => 'income_statement', 'label' => 'قائمة الدخل', 'status' => 'ready', 'source' => 'FinancialStatementsService ← ledger lines', 'priority' => 'P0'],
                    ['code' => 'balance_sheet', 'label' => 'الميزانية العمومية', 'status' => 'ready', 'source' => 'FinancialStatementsService ← ledger lines', 'priority' => 'P0'],
                    ['code' => 'cash_flow', 'label' => 'التدفق النقدي', 'status' => 'ready', 'source' => 'CashLiquidityReportService ← ledger entry lines', 'priority' => 'P0'],
                    ['code' => 'general_ledger', 'label' => 'دفتر الأستاذ', 'status' => 'ready', 'source' => 'GeneralLedgerReportService', 'priority' => 'P0'],
                ],
            ],
            'reconciliation_treasury' => [
                'label' => 'المصالحة والخزينة والسيولة',
                'reports' => [
                    ['code' => 'wallet_reconciliation', 'label' => 'مطابقة المحافظ بالدفتر', 'status' => 'ready', 'source' => 'LedgerReportService', 'priority' => 'P0'],
                    ['code' => 'reconciliation_cases', 'label' => 'قضايا فروقات المصالحة', 'status' => 'ready', 'source' => 'ReconciliationCaseService', 'priority' => 'P0'],
                    ['code' => 'liquidity_position', 'label' => 'مركز السيولة', 'status' => 'ready', 'source' => 'CashLiquidityReportService ← ledger balances', 'priority' => 'P0'],
                    ['code' => 'safeguarded_funds', 'label' => 'أموال العملاء مقابل الغطاء', 'status' => 'ready', 'source' => 'CashLiquidityReportService ← ledger balances', 'priority' => 'P0'],
                ],
            ],
            'transactions' => [
                'label' => 'المعاملات والحركة المالية',
                'reports' => [
                    ['code' => 'transaction_volume', 'label' => 'حجم وعدد المعاملات', 'status' => 'ready', 'source' => 'TransactionMonitoringReportService ← journal', 'priority' => 'P0'],
                    ['code' => 'failed_reversed_pending', 'label' => 'الفاشلة والعكسية والمعلقة', 'status' => 'ready', 'source' => 'Pending transfers + ledger reversals + audit', 'priority' => 'P0'],
                    ['code' => 'fees_commissions', 'label' => 'الرسوم والعمولات', 'status' => 'ready', 'source' => 'FeeProfitReportService', 'priority' => 'P0'],
                ],
            ],
            'merchant' => [
                'label' => 'التجار والقطاعات',
                'reports' => [
                    ['code' => 'merchant_portfolio', 'label' => 'محفظة التجار والباقات والمخاطر', 'status' => 'ready', 'source' => 'P1ControlReportService ← merchant_profiles', 'priority' => 'P1'],
                    ['code' => 'merchant_financial_truth', 'label' => 'الحقيقة المالية للتاجر', 'status' => 'ready', 'source' => 'MerchantFinancialTruthReportService', 'priority' => 'P1'],
                    ['code' => 'merchant_profit', 'label' => 'ربحية التاجر', 'status' => 'ready', 'source' => 'CashierService', 'priority' => 'P1'],
                    ['code' => 'inventory_valuation', 'label' => 'حركة ورقابة المخزون / التقييم المالي', 'status' => 'partial', 'source' => 'P1MerchantOperationsReportService ← stock movements + stocks؛ التقييم المالي ينتظر سياسة تكلفة وعملة صريحة', 'priority' => 'P1'],
                    ['code' => 'credit_aging', 'label' => 'تقادم الديون والتحصيل', 'status' => 'ready', 'source' => 'P1MerchantOperationsReportService ← replay للدفتر الموحد + رقابة مرآة الجملة بدون ازدواج', 'priority' => 'P0'],
                    ['code' => 'vertical_performance', 'label' => 'أداء الصيدلية والوقود والمطعم والتجزئة والجملة والبيع السريع', 'status' => 'partial', 'source' => 'Vertical services', 'priority' => 'P1'],
                ],
            ],
            'customers_agents' => [
                'label' => 'العملاء والوكلاء',
                'reports' => [
                    ['code' => 'customer_statement', 'label' => 'كشف حساب العميل', 'status' => 'ready', 'source' => 'CustomerLedgerReportService ← ledger lines', 'priority' => 'P0'],
                    ['code' => 'customer_activity', 'label' => 'نشاط واحتفاظ العملاء', 'status' => 'ready', 'source' => 'P1BusinessOperationsReportService ← users + transactions', 'priority' => 'P1'],
                    ['code' => 'agent_daily_close', 'label' => 'إغلاق الوكيل اليومي', 'status' => 'ready', 'source' => 'AgentReportService', 'priority' => 'P1'],
                    ['code' => 'agent_float', 'label' => 'سيولة الوكيل والعجز ومطابقة الخزنة', 'status' => 'ready', 'source' => 'P1ControlReportService ← till + movements + wallet', 'priority' => 'P1'],
                ],
            ],
            'risk_compliance' => [
                'label' => 'الامتثال والمخاطر والتدقيق',
                'reports' => [
                    ['code' => 'kyc_pipeline', 'label' => 'KYC والتحقق والمدة والتراكم', 'status' => 'ready', 'source' => 'P1ControlReportService ← users + merchant verification', 'priority' => 'P1'],
                    ['code' => 'aml_regulatory', 'label' => 'AML وSTR/CTR', 'status' => 'partial', 'source' => 'AmlDashboardService + AmlRegulatoryReportService؛ PEP/watchlist غير مبنيين', 'priority' => 'P1'],
                    ['code' => 'audit_sensitive_actions', 'label' => 'الإجراءات الحساسة والتدقيق', 'status' => 'ready', 'source' => 'P1ControlReportService ← append-only audit', 'priority' => 'P1'],
                    ['code' => 'rbac_changes', 'label' => 'تغييرات الصلاحيات والأدوار', 'status' => 'ready', 'source' => 'P1ControlReportService ← append-only audit', 'priority' => 'P1'],
                ],
            ],
            'operations' => [
                'label' => 'التشغيل والتقنية',
                'reports' => [
                    ['code' => 'system_health_history', 'label' => 'تاريخ صحة النظام وSLA', 'status' => 'partial', 'source' => 'health + alerts', 'priority' => 'P1'],
                    ['code' => 'jobs_queues', 'label' => 'الطوابير والمهام الفاشلة', 'status' => 'partial', 'source' => 'queue/jobs', 'priority' => 'P1'],
                    ['code' => 'email_otp', 'label' => 'البريد وOTP والتسليم', 'status' => 'ready', 'source' => 'otp_challenges', 'priority' => 'P2'],
                    ['code' => 'support_sla', 'label' => 'الدعم وزمن الحل والتراكم', 'status' => 'partial', 'source' => 'P1BusinessOperationsReportService؛ هدف SLA الرسمي غير مضبوط بعد', 'priority' => 'P2'],
                    ['code' => 'subscriptions', 'label' => 'الباقات والاشتراكات والقيمة المتكررة', 'status' => 'ready', 'source' => 'P1BusinessOperationsReportService ← profiles + immutable subscription changes', 'priority' => 'P1'],
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
