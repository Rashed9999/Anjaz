<?php

namespace App\Support\Access;

/**
 * CRITICAL-001 — تعريف ثوابت النظام (Single Source of Truth).
 *
 * كل القوائم هنا في PHP لمرونة التعديل بدون migrations.
 * عندما تنضج المتطلّبات، يمكن نقلها إلى DB tables.
 */
class AccessConstants
{
    // ============ Roles ============
    public const ROLE_USER = 'user';
    public const ROLE_AGENT = 'agent';
    public const ROLE_DISTRIBUTOR = 'distributor';
    public const ROLE_MERCHANT = 'merchant';
    public const ROLE_ADMIN = 'admin';

    public const ALL_ROLES = [
        self::ROLE_USER, self::ROLE_AGENT, self::ROLE_DISTRIBUTOR,
        self::ROLE_MERCHANT, self::ROLE_ADMIN,
    ];

    // ============ Verification Levels ============
    public const VERIFICATION_BASIC = 'basic';        // هاتف فقط
    public const VERIFICATION_VERIFIED = 'verified';  // + هوية
    public const VERIFICATION_PREMIUM = 'premium';    // + إثبات عنوان (يفتح multi-currency في v2)

    public const ALL_VERIFICATION_LEVELS = [
        self::VERIFICATION_BASIC, self::VERIFICATION_VERIFIED, self::VERIFICATION_PREMIUM,
    ];

    // ============ Business Types ============
    public const BIZ_QUICK_SALE = 'quick_sale';   // أسماك، خضار، بسطات
    public const BIZ_RETAIL = 'retail';           // بقالة، سوبرماركت
    public const BIZ_FUEL = 'fuel';               // محطة وقود
    public const BIZ_PHARMACY = 'pharmacy';       // صيدلية
    public const BIZ_WHOLESALE = 'wholesale';     // جملة
    public const BIZ_RESTAURANT = 'restaurant';   // مطعم (v2)

    public const ALL_BUSINESS_TYPES = [
        self::BIZ_QUICK_SALE, self::BIZ_RETAIL, self::BIZ_FUEL,
        self::BIZ_PHARMACY, self::BIZ_WHOLESALE, self::BIZ_RESTAURANT,
    ];

    // العناوين العربية للعرض
    public const BUSINESS_TYPE_LABELS = [
        self::BIZ_QUICK_SALE => 'بيع سريع (أسماك، خضار، بسطات)',
        self::BIZ_RETAIL => 'تجزئة (بقالة، سوبرماركت)',
        self::BIZ_FUEL => 'محطة وقود',
        self::BIZ_PHARMACY => 'صيدلية',
        self::BIZ_WHOLESALE => 'تجارة جملة',
        self::BIZ_RESTAURANT => 'مطعم',
    ];

    // ============ Subscription Plans ============
    public const PLAN_FREE = 'free';
    public const PLAN_STARTER = 'starter';            // 15 SAR/شهر
    public const PLAN_BUSINESS = 'business';          // 35 SAR/شهر
    public const PLAN_MERCHANT_PRO = 'merchant_pro';  // 65 SAR/شهر
    public const PLAN_ENTERPRISE = 'enterprise';      // 150 SAR/شهر

    public const ALL_PLANS = [
        self::PLAN_FREE, self::PLAN_STARTER, self::PLAN_BUSINESS,
        self::PLAN_MERCHANT_PRO, self::PLAN_ENTERPRISE,
    ];

    /**
     * **عملةُ الأسعار — مصدرٌ واحدٌ يُقرأ ولا يُكتب في شاشة.**
     *
     * قِيس: التطبيقُ فيه ٢٧٥ موضعاً بـ«ر.ي» وموضعٌ واحدٌ بـ«ر.س» — وهو
     * سطرُ سعر الباقة، محفوراً في ملفّ Dart. وليست غلطةَ حرف: هذه
     * الأسعارُ **بالريال السعوديّ فعلاً** كما يقول اسمُ الثابت، وكلُّ
     * رصيدٍ في المنتج بالريال اليمنيّ.
     *
     * فصارت العملةُ تُرسَل مع السعر. **وتغييرُها قرارُ تسعيرٍ يُتَّخذ هنا
     * بسطرٍ واحد** — لا تعديلَ نصٍّ في تسع شاشات.
     */
    public const PLAN_PRICE_CURRENCY = 'ر.س';

    public const PLAN_PRICES_SAR = [
        self::PLAN_FREE => 0,
        self::PLAN_STARTER => 15,
        self::PLAN_BUSINESS => 35,
        self::PLAN_MERCHANT_PRO => 65,
        self::PLAN_ENTERPRISE => 150,
    ];

    public const PLAN_PRICES_SAR_ANNUAL = [
        self::PLAN_FREE => 0,
        self::PLAN_STARTER => 150,
        self::PLAN_BUSINESS => 350,
        self::PLAN_MERCHANT_PRO => 650,
        self::PLAN_ENTERPRISE => 1500,
    ];

    public const PLAN_LABELS = [
        self::PLAN_FREE => 'مجاني',
        self::PLAN_STARTER => 'البداية',
        self::PLAN_BUSINESS => 'الأعمال',
        self::PLAN_MERCHANT_PRO => 'تاجر محترف',
        self::PLAN_ENTERPRISE => 'مؤسسة',
    ];

    /**
     * حدود رقمية لكل خطّة. القيمة -1 = غير محدود. القيمة 0 = ممنوع.
     * هذه الحدود تُفرض في Middleware/Service قبل أي عملية إضافة.
     */
    public const PLAN_LIMITS = [
        self::PLAN_FREE => [
            'monthly_operations' => 100,    // عمليات بيع شهرياً
            'archive_days' => 30,
            'products' => 0,                // FREE: لا منتجات (بيع سريع فقط)
            'employees' => 0,
            'branches' => 0,
            'pos_devices' => 1,
        ],
        self::PLAN_STARTER => [
            'monthly_operations' => -1,
            'archive_days' => 180,          // 6 أشهر
            'products' => 100,
            'employees' => 0,
            'branches' => 0,
            'pos_devices' => 1,
        ],
        self::PLAN_BUSINESS => [
            'monthly_operations' => -1,
            'archive_days' => 365,
            'products' => 300,
            'employees' => 5,
            'branches' => 0,
            'pos_devices' => 3,
        ],
        self::PLAN_MERCHANT_PRO => [
            'monthly_operations' => -1,
            'archive_days' => -1,
            'products' => -1,
            'employees' => -1,
            'branches' => 3,
            'pos_devices' => -1,
        ],
        self::PLAN_ENTERPRISE => [
            'monthly_operations' => -1,
            'archive_days' => -1,
            'products' => -1,
            'employees' => -1,
            'branches' => -1,
            'pos_devices' => -1,
        ],
    ];

    // ============ Features (مفاتيح الميزات) ============
    // أساسيات لكل المستخدمين
    public const F_WALLET = 'wallet';
    public const F_TRANSFER = 'transfer';
    public const F_RECEIVE = 'receive';
    public const F_QR_PAY = 'qr_pay';
    public const F_NOTIFICATIONS = 'notifications';
    public const F_RECEIPTS = 'receipts';
    public const F_PROFILE = 'profile';

    // مستخدم عادي
    public const F_FAMILY_FUND = 'family_fund';
    public const F_SAFE_PAY = 'safe_pay';
    public const F_BILL_PAY = 'bill_pay';
    public const F_FAVORITE_NUMBERS = 'favorite_numbers';

    // وكيل
    public const F_CASH_IN = 'cash_in';
    public const F_CASH_OUT = 'cash_out';
    public const F_AGENT_COMMISSIONS = 'agent_commissions';
    public const F_AGENT_FLOAT = 'agent_float';
    public const F_AGENT_REPORTS = 'agent_reports';

    // موزّع
    public const F_DISTRIBUTOR_AGENTS = 'distributor_agents';
    public const F_DISTRIBUTOR_NETWORK = 'distributor_network';

    // تاجر — POS عام
    public const F_QUICK_SALE = 'quick_sale';       // بيع سريع (للأسماك/الخضار)
    public const F_CASHIER = 'cashier';             // كاشير متقدّم
    public const F_PRODUCTS = 'products';
    public const F_INVENTORY = 'inventory';
    public const F_BARCODE = 'barcode';
    public const F_CUSTOMERS = 'customers';
    public const F_DEBTS = 'debts';                 // الديون / الحسابات الآجلة
    public const F_REFUNDS = 'refunds';
    public const F_PAYMENT_REQUESTS = 'payment_requests';
    public const F_SPLIT_BILL = 'split_bill';
    public const F_DAILY_REPORTS = 'daily_reports';
    public const F_ADVANCED_REPORTS = 'advanced_reports';
    public const F_MERCHANT_VERIFICATION = 'merchant_verification';

    // محطة وقود
    public const F_FUEL_POS = 'fuel_pos';
    public const F_FUEL_PUMPS = 'fuel_pumps';
    public const F_FUEL_PRODUCTS = 'fuel_products';
    public const F_FUEL_COMPANIES = 'fuel_companies';
    public const F_FUEL_CARDS = 'fuel_cards';
    public const F_FUEL_SHIFTS = 'fuel_shifts';
    public const F_FUEL_VARIANCE = 'fuel_variance';

    // صيدلية
    public const F_PHARMACY_POS = 'pharmacy_pos';
    public const F_PHARMACY_PRODUCTS = 'pharmacy_products';
    public const F_PHARMACY_BATCHES = 'pharmacy_batches';
    public const F_PHARMACY_CUSTOMERS = 'pharmacy_customers';
    public const F_PHARMACY_ALERTS = 'pharmacy_alerts';
    public const F_PHARMACY_PRESCRIPTIONS = 'pharmacy_prescriptions';

    // جملة
    public const F_WHOLESALE_INVOICES = 'wholesale_invoices';
    public const F_WHOLESALE_MULTI_PRICING = 'wholesale_multi_pricing';
    public const F_WHOLESALE_COLLECTIONS = 'wholesale_collections';

    // مطعم (v2)
    public const F_RESTAURANT_TABLES = 'restaurant_tables';
    public const F_RESTAURANT_ORDERS = 'restaurant_orders';
    public const F_RESTAURANT_KITCHEN = 'restaurant_kitchen';

    // ميزات Enterprise
    public const F_EMPLOYEES = 'employees';         // إدارة موظفي نقطة البيع
    public const F_EMPLOYEE_PERMISSIONS = 'employee_permissions'; // basic/advanced/enterprise
    public const F_BRANCHES = 'branches';
    public const F_BRANCH_REPORTS = 'branch_reports';
    public const F_CORPORATE_ACCOUNTS = 'corporate_accounts';
    public const F_CORPORATE_CREDIT_LIMITS = 'corporate_credit_limits';
    public const F_API_ACCESS = 'api_access';
    public const F_RBAC = 'rbac';                   // صلاحيات مفصّلة
    public const F_OPERATIONS_MANAGER = 'operations_manager';
    public const F_FINANCIAL_MANAGER = 'financial_manager';
    public const F_AUDIT_LOG = 'audit_log';
    public const F_ADVANCED_BACKUP = 'advanced_backup';
    public const F_MULTI_POS = 'multi_pos';         // أكثر من نقطة بيع

    // Stock & Inventory المتقدّمة
    public const F_INVENTORY_AUDIT = 'inventory_audit';     // جرد المخزون
    public const F_LOW_STOCK_ALERTS = 'low_stock_alerts';   // تنبيه نفاد
    public const F_SUPPLIERS = 'suppliers';                 // الموردون
    public const F_PURCHASES = 'purchases';                 // المشتريات
    public const F_PROFIT_REPORTS = 'profit_reports';       // تقارير الأرباح
    public const F_EXCEL_EXPORT = 'excel_export';           // تصدير Excel

    // مرتبطة بـ verification (لـ v2)
    public const F_MULTI_CURRENCY = 'multi_currency';

    // ============ النمو والولاء (Growth) — تُضاف تدريجياً وكلٌّ لها شاشة تعمل ============
    public const F_LOYALTY = 'loyalty';       // برنامج النقاط والولاء (الأعمال+)
    public const F_PROMOTIONS = 'promotions'; // العروض والخصومات والكوبونات (ستارتر+)
    public const F_INSTALLMENTS = 'installments'; // البيع بالتقسيط من المحفظة (التاجر برو+)
    public const F_OFFLINE_POS = 'offline_pos';   // وضع البيع دون اتصال + مزامنة (الأعمال+)
    public const F_GIFT_CARDS = 'gift_cards';     // بطاقات الهدايا ورصيد المتجر (الأعمال+)
    public const F_SHIFT_CLOSE = 'shift_close';   // إقفال الوردية ودرج النقد X/Z (الأعمال+)
    public const F_EXPENSES = 'expenses';         // المصروفات والصندوق النثري (الأعمال+)

    // العملات المدعومة (يفتحها MERCHANT_PRO فأعلى)
    public const CURRENCY_YER = 'YER';   // الافتراضي (الجنوب)
    public const CURRENCY_SAR = 'SAR';
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_AED = 'AED';
    public const CURRENCY_OMR = 'OMR';

    public const ALL_CURRENCIES_FOR_PRO = [
        self::CURRENCY_SAR, self::CURRENCY_USD, self::CURRENCY_AED, self::CURRENCY_OMR,
    ];

    /**
     * Archive duration بالأيام لكل plan (يُستخدم في reports/transactions filter).
     * -1 = غير محدود.
     */
    public static function archiveDaysForPlan(string $plan): int
    {
        return self::PLAN_LIMITS[$plan]['archive_days'] ?? 30;
    }

    /**
     * عدد العمليات الشهرية المسموحة (-1 = غير محدود، 0 = ممنوع).
     */
    public static function monthlyOpsLimit(string $plan): int
    {
        return self::PLAN_LIMITS[$plan]['monthly_operations'] ?? 100;
    }

    /** هل الـ plan يسمح بميزة "products"؟ */
    public static function maxProducts(string $plan): int
    {
        return self::PLAN_LIMITS[$plan]['products'] ?? 0;
    }

    public static function maxEmployees(string $plan): int
    {
        return self::PLAN_LIMITS[$plan]['employees'] ?? 0;
    }

    public static function maxBranches(string $plan): int
    {
        return self::PLAN_LIMITS[$plan]['branches'] ?? 0;
    }

    public static function maxPosDevices(string $plan): int
    {
        return self::PLAN_LIMITS[$plan]['pos_devices'] ?? 1;
    }
}
