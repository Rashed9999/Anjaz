<?php

namespace App\Support\Access;

use App\Domain\Verticals\VerticalRegistry;
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
                A::F_PAYMENT_REQUESTS,
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

    /**
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-VERTICAL-OOP-001 — **نواةُ القطاع تُسأل من مربّعها.**
     *
     * كانت هنا ستُّ قوائمَ مكتوبةً بالاسم. وصارت تُقرأ من
     * `VerticalRegistry` — **موضعٌ واحدٌ يقول ما هذا القطاع**، بعد أن كان
     * منطقُ «صنف النشاط» متفرّقاً في ستّة ملفّاتٍ و٧٨ موضعَ تفرّع.
     *
     * **والتحويلُ آمنٌ بالقياس لا بالظنّ:** `VerticalParityGuardTest`
     * يقارن المخرجَ القديمَ بالجديد في **ثماني عشرةَ تركيبة**
     * (ستّةُ قطاعاتٍ × ثلاثُ باقات) فيثبت التطابقَ التامّ.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والفارقُ الوحيدُ مقصود:** لم تعد تُعاد هنا `receipts` و
     * `daily_reports`. يمنحهما `roleBase(ROLE_MERCHANT)` لكلّ تاجرٍ
     * أصلاً، وكان كلُّ قطاعٍ يُعيد منحَهما.
     *
     * **والاتّحادُ لا يتغيّر** (`resolveFeatures` يجمع الدورَ أوّلاً)،
     * لكنّ **نزعَهما من الدور صار له أثر**: كان من ينزعهما يظنّ أنّه
     * أغلقهما وهما مفتوحتان من ستّة أبوابٍ أخرى.
     *
     * والحارس: `MerchantSharedBoxGuardTest`.
     * ══════════════════════════════════════════════════════════════════
     */
    public static function businessTypeFeatures(?string $type): array
    {
        return VerticalRegistry::find($type)?->own() ?? [];
    }

    // ============ Features لكل Subscription Plan ============
    // ثلاث باقات فقط: مجاني، أعمال، مؤسسة. نوع النشاط يحدد الانطباق؛
    // هذه الدالة تحدد العمق المدفوع ولا تمنح قدرة قطاعية لقطاع آخر.
    public static function planFeatures(string $plan): array
    {
        return match ($plan) {
            // ══════════════════════════════════════════════════════════
            // AMIAL-VERTICAL-SCOPE-001 — **`quick_sale` و`debts` خرجتا
            // من هنا إلى مربّعات قطاعاتها، ولم تُنزَعا عن أحد.**
            //
            // كانتا تُمنَحان لكلّ تاجرٍ من هذه القائمة **ومن مربّعه معاً**
            // — مصدرا منحٍ لشيءٍ واحد. و`F_DEBTS` مثالُه الصارخ: مُعلَنٌ
            // في نواة القطاعات الخمسة (بيع سريع · تجزئة · جملة · صيدليّة
            // · مطعم) **وليس في الوقود**، و`FuelVertical` تقول بنصّها
            // «ولا `F_DEBTS` — ائتمانُ المحطّة ببطاقاتٍ لا بدفترِ دَين».
            // **ثمّ يُعيدها هذا السطرُ إلى المحطّة من الباب الخلفيّ.**
            //
            // فنُقلت النية إلى موضع التنفيذ: يمنحها مربّعُ القطاع، وتُنزَع
            // من هنا. **ولا يتغيّر ما يصل أحداً** إلّا محطّةَ الوقود —
            // وذاك المقصود. ويحرسه `VerticalScopeGuardTest`.
            // ══════════════════════════════════════════════════════════
            A::PLAN_FREE => [
                A::F_REFUNDS,
            ],
            A::PLAN_BUSINESS => [
                // ══════════════════════════════════════════════════════
                // AMIAL-ORPHAN-CAPS-001 — **مبنيّةٌ ومُسعَّرةٌ ومقفلةٌ معاً.**
                //
                // خمسُ قدراتٍ كانت **تُعلَن بـ`minPlan(PLAN_BUSINESS)`،
                // ولها شاشاتٌ في `CapabilityScreens`، ولها نقاطُ نهايةٍ
                // تعمل — ولا يمنحها مانحٌ واحد**. فيدفع تاجرُ التجزئة
                // خمسةً وثلاثين ريالاً، وتَعِده صفحةُ التسعير، ويفتح
                // الشاشة، **فيردّه وسيطُ `capability:` نفسُه**.
                //
                // وهذا مقلوبُ «تُباع ولا وجودَ لها»: **موجودةٌ ومبيعةٌ
                // ومقفلةٌ**. والسلسلةُ كلُّها سليمةٌ إلّا حلقةَ المنح.
                //
                //   retail.catalog        → capability:retail.catalog
                //                           على /categories /brands /units
                //   retail.variants       → على /products/{id}/variants
                //   retail.waste          → على /wastes
                //   retail.price_versions → نسخُ الأسعار بالاعتماد
                //   retail.returns.by_line→ مرتجعُ صنفٍ من فاتورة
                //
                // **والمنحُ عند أرضيّتها المُعلَنة لا فوقها** — القدرةُ
                // تقول بنفسها في أيّ باقةٍ هي، فيُطابَق المانحُ قولَها.
                //
                // **ونُطّقت بـ`GOODS` أوّلاً** (AMIAL-VERTICAL-SCOPE-001):
                // منحُها هنا بلا نطاقٍ يُعطي «الهالك» و«متغيّرات الصنف»
                // لمحطّة وقود — وهو العطلُ الذي كشفه صاحبُ المشروع بعينه.
                // ══════════════════════════════════════════════════════
                'retail.catalog', 'retail.variants', 'retail.price_versions',
                'retail.waste', 'retail.returns.by_line',

                A::F_REFUNDS,
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_PROMOTIONS,
                A::F_OFFLINE_POS,
                A::F_GIFT_CARDS,
                A::F_SHIFT_CLOSE,
                A::F_EXPENSES,
                A::F_CUSTOMERS, A::F_SUPPLIERS, A::F_PURCHASES,
                A::F_PROFIT_REPORTS, A::F_EXCEL_EXPORT, A::F_ADVANCED_REPORTS,
                // الصلاحيات جزء من إدارة الموظف نفسها، وليست بطاقة ثانية
                // تُباع بلا شاشة أو حارس مستقلين.
                A::F_EMPLOYEES, A::F_MULTI_POS,
                A::F_LOYALTY,
            ],
            // مؤسسة (99 ر.س/شهر): كل الأعمال + نمو متعدد الفروع وحوكمة مؤسسية.
            // ══════════════════════════════════════════════════════════
            // AMIAL-WHOLESALE-GUIDE-001 — **المواقعُ والتحويلاتُ بينها،
            // بثمنٍ من مستندَي صاحب المشروع لا باجتهادي.**
            //
            // كانتا معلَنتين في السجلّ بـ`minPlan(enterprise)` **ولا
            // يمنحهما مصدرٌ**: لا مربّعُ قطاعٍ ولا باقة. فتُعرَضان في
            // «قدراتي» «بالترقية»، ويرقّى التاجرُ إلى المؤسّسة فلا
            // تُفتحان — وكان ذلك في دَين `CapabilityHasAGranterGuardTest`.
            //
            // **ودليلا التشغيل يسعّرانهما صراحةً في المؤسّسة:**
            //   دليلُ الجملة  : «فروع ومستودعات ومواقع»
            //   دليلُ التجزئة : «فروع ومستودعات ومواقع» · «تحويلات بين
            //                   المواقع» · «الفرع يمتلك مخزونه وسياقه.
            //                   النقل يمر بطلب واعتماد وإرسال واستلام،
            //                   لا بخصم صامت من متجر وإضافة صامتة لآخر»
            //
            // فالثمنُ معلَنٌ من قبل، والمانحُ وحدَه كان مفقوداً.
            // ══════════════════════════════════════════════════════════
            A::PLAN_ENTERPRISE => [
                A::F_REFUNDS,
                // **والقائمتان مستقلّتان لا تراكميّتان** — فما يُمنَح في
                // «الأعمال» يُعاد هنا نصّاً، وإلّا أنقصت الترقيةُ ميزة.
                // (أُغفلت الخمسُ أوّلَ مرّةٍ فسقط
                //  `PlanEntitlementMatrixTest::a_higher_plan_never_gives_less`
                //  — **حارسٌ قائمٌ أمسك انحداراً حقيقيّاً**: يدفع التاجرُ
                //  أكثرَ فيحصل على أقلّ.)
                'retail.catalog', 'retail.variants', 'retail.price_versions',
                'retail.waste', 'retail.returns.by_line',
                'retail.locations', 'retail.transfers',
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_PROMOTIONS,
                A::F_OFFLINE_POS,
                A::F_GIFT_CARDS,
                A::F_SHIFT_CLOSE,
                A::F_EXPENSES,
                A::F_CUSTOMERS, A::F_SUPPLIERS, A::F_PURCHASES,
                A::F_PROFIT_REPORTS, A::F_EXCEL_EXPORT, A::F_ADVANCED_REPORTS,
                A::F_EMPLOYEES, A::F_MULTI_POS,
                A::F_LOYALTY,
                A::F_BRANCHES, A::F_BRANCH_REPORTS,
                A::F_MULTI_CURRENCY,
                A::F_INSTALLMENTS,
                A::F_AUDIT_LOG, A::F_ADVANCED_BACKUP,
                A::F_RBAC,
                A::F_API_ACCESS,
                A::F_CORPORATE_ACCOUNTS, A::F_CORPORATE_CREDIT_LIMITS,
                A::F_OPERATIONS_MANAGER, A::F_FINANCIAL_MANAGER,
            ],

            default => [],
        };
    }

    /**
     * ميزات مدفوعة لا تنطبق إلا على قطاعها؛ لا تدخل planFeatures العامة.
     *
     * AMIAL-VERTICAL-OOP-001 — **والعمقُ يُسأل من مربّع القطاع كذلك.**
     *
     * وسُلَّمُ الباقات يُقرأ من `AccessConstants::ALL_PLANS` داخل
     * `planReaches` — فباقةٌ تُضاف غداً تأخذ موضعَها بلا تعديلٍ هنا،
     * وسُلَّمٌ مكتوبٌ بيدٍ يشيخ مع أوّل قرارِ تسعير.
     */
    public static function verticalPlanFeatures(?string $type, string $plan): array
    {
        return VerticalRegistry::find($type)?->depthFor($plan) ?? [];
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
