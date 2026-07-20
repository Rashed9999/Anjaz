<?php

namespace App\Support\Access;

use App\Support\Access\AccessConstants as A;

/**
 * CRITICAL-001 — Presets الـ Features لكل (Role + Business Type + Plan).
 *
 * المنطق:
 *   final_features = role_base ∪ business_type_features ∪ plan_features ∪ extra_features
 *
 * النتيجة هي قائمة Features التي يراها المستخدم.
 */
class AccessPresets
{
    // ============ Features الأساسية لكل دور ============
    public static function roleBase(string $role): array
    {
        $common = [A::F_WALLET, A::F_NOTIFICATIONS, A::F_PROFILE];

        return match ($role) {
            A::ROLE_USER => [
                ...$common,
                A::F_TRANSFER, A::F_RECEIVE, A::F_QR_PAY,
                A::F_FAMILY_FUND, A::F_SAFE_PAY, A::F_BILL_PAY,
                A::F_FAVORITE_NUMBERS, A::F_RECEIPTS,
            ],
            A::ROLE_AGENT => [
                ...$common,
                A::F_CASH_IN, A::F_CASH_OUT,
                A::F_AGENT_COMMISSIONS, A::F_AGENT_FLOAT, A::F_AGENT_REPORTS,
                A::F_TRANSFER, A::F_RECEIPTS,
            ],
            A::ROLE_DISTRIBUTOR => [
                ...$common,
                A::F_DISTRIBUTOR_AGENTS, A::F_DISTRIBUTOR_NETWORK,
                A::F_AGENT_COMMISSIONS, A::F_TRANSFER, A::F_RECEIPTS,
            ],
            A::ROLE_MERCHANT => [
                // التاجر يحصل على الأساسيات لكنه يعتمد بشكل رئيسي على business_type + plan
                ...$common,
                A::F_TRANSFER, A::F_RECEIVE, A::F_QR_PAY,
                A::F_MERCHANT_VERIFICATION, A::F_RECEIPTS,
                A::F_DAILY_REPORTS,
            ],
            A::ROLE_ADMIN => [
                ...$common,
                // الأدمن يرى كل شيء — يُعالَج في الواجهة الإدارية
            ],
            default => $common,
        };
    }

    // ============ Features لكل Business Type ============
    public static function businessTypeFeatures(?string $type): array
    {
        if ($type === null) return [];

        return match ($type) {
            // بائع السمك/الخضار: بساطة قصوى
            A::BIZ_QUICK_SALE => [
                A::F_QUICK_SALE, A::F_DEBTS, A::F_DAILY_REPORTS, A::F_REFUNDS,
            ],

            // بقالة/سوبرماركت: POS كامل
            A::BIZ_RETAIL => [
                A::F_CASHIER, A::F_PRODUCTS, A::F_CUSTOMERS, A::F_DEBTS,
                A::F_REFUNDS, A::F_PAYMENT_REQUESTS, A::F_SPLIT_BILL,
                A::F_DAILY_REPORTS, A::F_RECEIPTS,
            ],

            // محطة وقود
            A::BIZ_FUEL => [
                A::F_FUEL_POS, A::F_FUEL_PUMPS, A::F_FUEL_PRODUCTS,
                A::F_FUEL_COMPANIES, A::F_FUEL_SHIFTS, A::F_DAILY_REPORTS,
                A::F_RECEIPTS,
            ],

            // صيدلية
            A::BIZ_PHARMACY => [
                A::F_PHARMACY_POS, A::F_PHARMACY_PRODUCTS, A::F_PHARMACY_BATCHES,
                A::F_PHARMACY_CUSTOMERS, A::F_PHARMACY_ALERTS,
                A::F_DEBTS, A::F_DAILY_REPORTS, A::F_RECEIPTS,
            ],

            // جملة
            A::BIZ_WHOLESALE => [
                A::F_CASHIER, A::F_PRODUCTS, A::F_CUSTOMERS, A::F_DEBTS,
                A::F_WHOLESALE_INVOICES, A::F_WHOLESALE_COLLECTIONS,
                A::F_DAILY_REPORTS, A::F_RECEIPTS,
            ],

            // مطعم (v2 — placeholder للآن)
            A::BIZ_RESTAURANT => [
                A::F_RESTAURANT_TABLES, A::F_RESTAURANT_ORDERS,
                A::F_RESTAURANT_KITCHEN, A::F_DEBTS, A::F_DAILY_REPORTS,
            ],

            default => [],
        };
    }

    // ============ Features لكل Subscription Plan ============
    // وفق جدول التسعير الرسمي v1.0
    public static function planFeatures(string $plan): array
    {
        return match ($plan) {
            // FREE — أساسيات فقط (بيع سريع + QR + نقد + آجل بسيط + تقارير يوم/شهر)
            // الحدّ: 100 عملية شهرياً، أرشيف 30 يوم
            A::PLAN_FREE => [
                A::F_QUICK_SALE, A::F_DEBTS, A::F_REFUNDS,
            ],

            // STARTER (15 SAR/شهر) — يفتح المنتجات + الباركود + المخزون
            A::PLAN_STARTER => [
                A::F_QUICK_SALE, A::F_DEBTS, A::F_REFUNDS,
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_PROMOTIONS,
            ],

            // BUSINESS (35 SAR/شهر) — يفتح إدارة العملاء + الموردين + الموظفين
            A::PLAN_BUSINESS => [
                A::F_QUICK_SALE, A::F_DEBTS, A::F_REFUNDS,
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_PROMOTIONS,
                A::F_CUSTOMERS, A::F_SUPPLIERS, A::F_PURCHASES,
                A::F_PROFIT_REPORTS, A::F_EXCEL_EXPORT, A::F_ADVANCED_REPORTS,
                A::F_EMPLOYEES, A::F_EMPLOYEE_PERMISSIONS, A::F_MULTI_POS,
                A::F_FUEL_CARDS, A::F_FUEL_VARIANCE,
                A::F_LOYALTY,
            ],

            // MERCHANT_PRO (65 SAR/شهر) — كل ما في Business + فروع + متعدّد العملات + audit
            A::PLAN_MERCHANT_PRO => [
                A::F_QUICK_SALE, A::F_DEBTS, A::F_REFUNDS,
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_PROMOTIONS,
                A::F_CUSTOMERS, A::F_SUPPLIERS, A::F_PURCHASES,
                A::F_PROFIT_REPORTS, A::F_EXCEL_EXPORT, A::F_ADVANCED_REPORTS,
                A::F_EMPLOYEES, A::F_EMPLOYEE_PERMISSIONS, A::F_MULTI_POS,
                A::F_FUEL_CARDS, A::F_FUEL_VARIANCE,
                A::F_LOYALTY,
                A::F_BRANCHES, A::F_BRANCH_REPORTS,
                A::F_MULTI_CURRENCY,
                A::F_INSTALLMENTS,
                A::F_AUDIT_LOG, A::F_ADVANCED_BACKUP,
                A::F_RBAC, A::F_PHARMACY_PRESCRIPTIONS,
                A::F_WHOLESALE_MULTI_PRICING,
            ],

            // ENTERPRISE (150 SAR/شهر) — يضيف API + corporate accounts + managers
            A::PLAN_ENTERPRISE => [
                A::F_QUICK_SALE, A::F_DEBTS, A::F_REFUNDS,
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_PROMOTIONS,
                A::F_CUSTOMERS, A::F_SUPPLIERS, A::F_PURCHASES,
                A::F_PROFIT_REPORTS, A::F_EXCEL_EXPORT, A::F_ADVANCED_REPORTS,
                A::F_EMPLOYEES, A::F_EMPLOYEE_PERMISSIONS, A::F_MULTI_POS,
                A::F_FUEL_CARDS, A::F_FUEL_VARIANCE,
                A::F_LOYALTY,
                A::F_BRANCHES, A::F_BRANCH_REPORTS,
                A::F_MULTI_CURRENCY,
                A::F_INSTALLMENTS,
                A::F_AUDIT_LOG, A::F_ADVANCED_BACKUP,
                A::F_RBAC, A::F_PHARMACY_PRESCRIPTIONS,
                A::F_WHOLESALE_MULTI_PRICING,
                A::F_API_ACCESS,
                A::F_CORPORATE_ACCOUNTS, A::F_CORPORATE_CREDIT_LIMITS,
                A::F_OPERATIONS_MANAGER, A::F_FINANCIAL_MANAGER,
            ],

            default => [],
        };
    }

    // ============ Features لكل Verification Level ============
    public static function verificationFeatures(string $level): array
    {
        return match ($level) {
            A::VERIFICATION_BASIC => [],
            A::VERIFICATION_VERIFIED => [], // رفع الحدود — منطقي داخلي وليس feature ظاهرة
            A::VERIFICATION_PREMIUM => [
                // multi_currency مؤجّل لـ v2 — لا نُفعّله الآن
                // A::F_MULTI_CURRENCY,
            ],
            default => [],
        };
    }

    // ============ Plan Limits (حدود الكميّات) ============
    // المصدر الواحد: AccessConstants::PLAN_LIMITS
    public static function planLimits(string $plan): array
    {
        $limits = A::PLAN_LIMITS[$plan] ?? A::PLAN_LIMITS[A::PLAN_FREE];

        return [
            'max_products' => $limits['products'],
            'max_employees' => $limits['employees'],
            'max_branches' => $limits['branches'],
            'max_pos_devices' => $limits['pos_devices'],
            'monthly_operations' => $limits['monthly_operations'],
            'archive_days' => $limits['archive_days'],
            // max_customers لا حدّ صريح في الجدول — نتركها غير محدودة
            'max_customers' => -1,
        ];
    }
}
