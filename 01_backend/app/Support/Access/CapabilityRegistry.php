<?php

namespace App\Support\Access;

use App\Support\Access\AccessConstants as A;
use App\Support\Access\Capability as C;

/**
 * AMIAL-ENTITLEMENTS-001 — **سجلُّ القدرات: المصدرُ الواحد**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * منه يُشتقّ ستّةُ أشياء بلا كتابةٍ ثانية:
 *
 * | المشتَقّ | كيف |
 * |---|---|
 * | ملفُّ خدمات التاجر | `EntitlementService::manifestFor()` |
 * | حراسةُ الخادم | `EnsureCapability` عبر `routePrefixes()` |
 * | شاشةُ الحساب في التطبيق | تُرسم من الملفّ — صفرُ قوائمَ في Dart |
 * | مصفوفةُ الإدارة | باقة × قدرة، تُعدَّل وتُخزَّن في `plan_capabilities` |
 * | صفحةُ التسعير | تُولَّد فلا تعِد بما لا يفتحه النظام |
 * | الحرّاس | مسارٌ بلا قدرةٍ مصرَّحة يُسقط الفحص |
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثُ قواعدَ تحكم التوزيع:**
 *
 * ① **`core()` لا تُباع.** ما يمنع رقماً كاذباً ليس ميزةً تُسعَّر.
 * ② **نوعُ النشاط ليس باقة.** محطّةُ وقودٍ تحصل على نقطة بيعها بلا دفع؛
 *    الباقةُ تبيع **العمقَ والحجم** لا البابَ الأوّل.
 * ③ **قفلُ الباقة غيرُ قفل الدور.** الأوّل يذهب لصاحب المتجر ليدفع،
 *    والثاني لمديره ليمنحه — وخلطُهما يُرسل كليهما في الطريق الخطأ.
 */
final class CapabilityRegistry
{
    /** @var array<string,Capability>|null */
    private static ?array $cache = null;

    /** @return array<string,Capability> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $out = [];
        foreach (self::declare() as $cap) {
            $out[$cap->code] = $cap;
        }

        return self::$cache = $out;
    }

    public static function find(string $code): ?Capability
    {
        return self::all()[$code] ?? null;
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::all());
    }

    /** @return array<int,Capability> */
    public static function forRoute(string $uri): array
    {
        return array_values(array_filter(
            self::all(), static fn (Capability $c) => $c->guardsRoute($uri),
        ));
    }

    /** المجموعاتُ بترتيب العرض في شاشة الخدمات. */
    public static function groups(): array
    {
        $seen = [];
        foreach (self::all() as $c) {
            $seen[$c->groupName()] = true;
        }

        return array_keys($seen);
    }

    /** ترتيبُ الباقات من الأدنى إلى الأعلى — **به يُقارَن `minPlan`**. */
    public const PLAN_ORDER = [
        A::PLAN_FREE => 0,
        A::PLAN_STARTER => 1,
        A::PLAN_BUSINESS => 2,
        A::PLAN_MERCHANT_PRO => 3,
        A::PLAN_ENTERPRISE => 4,
    ];

    public static function planRank(?string $plan): int
    {
        return self::PLAN_ORDER[$plan ?? A::PLAN_FREE] ?? 0;
    }

    // ══════════════════════════════════════════════════════════════════
    //  الإعلان — **وهذا هو الملفّ الوحيد الذي يُعدَّل عند إضافة قدرة**
    // ══════════════════════════════════════════════════════════════════

    /** @return array<int,Capability> */
    private static function declare(): array
    {
        return array_merge(
            self::core(),
            self::selling(),
            self::catalogue(),
            self::inventory(),
            self::people(),
            self::finance(),
            self::verticals(),
            self::enterprise(),
        );
    }

    // ── ① الأساسيّات — لا تُباع ────────────────────────────────────────

    private static function core(): array
    {
        return [
            C::make('core.sale_lines')
                ->nameAr('أسطر المبيعة وتكلفتها')
                ->descAr('كلّ بيعة تُسجَّل سطراً سطراً، وتكلفة كل صنف تُجمَّد لحظة البيع '
                    . 'فلا يتغيّر ربح الأمس حين يرتفع سعر الشراء اليوم.')
                ->group('الأساس')->icon('receipt_long')->core(),

            C::make('core.stock_movements')
                ->nameAr('حركات المخزون بأسبابها')
                ->descAr('كل تغيّر في المخزون حركة لها سبب وفاعل وموقع — والنقص السالب '
                    . 'يبقى ظاهراً حتى يصحّحه جرد، ولا يُقصّ إلى صفر صامتاً.')
                ->group('الأساس')->icon('history')->core(),

            C::make('core.payment_hold')
                ->nameAr('حجز البضاعة حتى نجاح الدفع')
                ->descAr('البيع بأميال باي غير متزامن: البضاعة تُحجز ولا تُخصم، '
                    . 'فلا تُباع مرّتين ولا تنقص لبيعة لم تقع.')
                ->group('الأساس')->icon('lock_clock')->core(),

            C::make('core.audit_trail')
                ->nameAr('أثر العمليات')
                ->descAr('كل فعل خطير يُسجَّل بفاعله ووقته — والسجلّ المفصَّل في باقة تاجر محترف.')
                ->group('الأساس')->icon('fingerprint')->core(),
        ];
    }

    // ── ② البيع ────────────────────────────────────────────────────────

    private static function selling(): array
    {
        return [
            C::make(A::F_QUICK_SALE)
                ->nameAr('البيع السريع')
                ->descAr('بيع بمبلغ حرّ بلا أصناف — لبائع الخضار والسمك ومن لا كتالوج له.')
                ->group('البيع')->icon('bolt')
                ->minPlan(A::PLAN_FREE)->screen('/quick-sale'),

            C::make(A::F_CASHIER)
                ->nameAr('الكاشير')
                ->descAr('نقطة بيع كاملة بالسلة والباركود وطرق الدفع.')
                ->group('البيع')->icon('point_of_sale')
                ->minPlan(A::PLAN_FREE)->screen('/cashier')
                ->businessTypes([A::BIZ_RETAIL, A::BIZ_WHOLESALE]),

            C::make(A::F_REFUNDS)
                ->nameAr('المرتجعات')
                ->descAr('استرجاع مبلغ من بيعة سابقة.')
                ->group('البيع')->icon('undo')
                ->minPlan(A::PLAN_FREE)->screen('/refunds'),

            C::make('retail.returns.by_line')
                ->nameAr('مرتجع صنف واحد من فاتورة')
                ->descAr('ارتجاع سطر بعينه بكمّيته وقرار إعادته للرفّ — والتالف لا يعود.')
                ->group('البيع')->icon('assignment_return')
                ->minPlan(A::PLAN_STARTER)
                ->permissions(['retail.return.*'])
                ->routes(['retail/returns', 'retail/sales'])
                ->screen('/retail/returns'),

            C::make(A::F_DEBTS)
                ->nameAr('البيع الآجل والديون')
                ->descAr('دفتر ديون العملاء بكشف حساب لكل عميل.')
                ->group('البيع')->icon('account_balance_wallet')
                ->minPlan(A::PLAN_FREE)->screen('/credit'),

            // ══════════════════════════════════════════════════════════
            // **مبنيّةٌ بالكامل ولم تكن موصولةً باستحقاقها.**
            //
            // **وقد كِدتُ أعلنها «لم تُبنَ»** — بحثتُ في الخادم وحدَه فلم
            // أجد لها أثراً. والبناءُ كلُّه في التطبيق:
            //
            //   `OfflineSaleQueue`      طابورٌ في التخزين المحلّيّ
            //   `OfflineSalesScreen`    شاشةٌ تعرض ما لم يُزامَن
            //   `sync()`                يرفعها عند عودة الشبكة
            //   `CashierService`        **يمنع التكرار بـ`client_uuid`**
            //
            // فالدرسُ مقيس: **«لم أجدها» ليست «غيرَ موجودة»** — والبحثُ
            // في نصفِ المشروع يُنتج حكماً على المشروع كلِّه.
            //
            // والحارسُ **على الفعل**: بيعةٌ تصل ومعها `client_uuid` هي
            // بيعةٌ وقعت دون اتصالٍ ثمّ زُومنت — وذاك هو المُباع. والبيعُ
            // المتّصلُ مجّانيٌّ كما كان.
            C::make(A::F_OFFLINE_POS)
                ->nameAr('البيع دون اتصال')
                ->descAr('البيع يستمرّ حين تنقطع الشبكة ويُزامَن بعدها بلا تكرار.')
                ->group('البيع')->icon('cloud_off')
                ->minPlan(A::PLAN_BUSINESS)
                ->routes(['cashier/sales'])
                ->screen('/offline-sales'),

            C::make(A::F_SPLIT_BILL)
                ->nameAr('تقسيم الفاتورة')
                ->descAr('توزيع فاتورة واحدة على أكثر من دافع.')
                ->group('البيع')->icon('call_split')
                ->minPlan(A::PLAN_FREE),

            C::make(A::F_GIFT_CARDS)
                ->nameAr('بطاقات الهدايا')
                ->group('البيع')->icon('card_giftcard')
                ->minPlan(A::PLAN_BUSINESS),
        ];
    }

    // ── ③ محرّك الأصناف ────────────────────────────────────────────────

    private static function catalogue(): array
    {
        return [
            C::make(A::F_PRODUCTS)
                ->nameAr('الأصناف')
                ->descAr('كتالوج المتجر بأسعاره وتكلفته.')
                ->group('الأصناف')->icon('inventory_2')
                ->minPlan(A::PLAN_STARTER)
                ->permissions(['retail.product.*'])
                ->limit('max_products')->screen('/products'),

            C::make(A::F_BARCODE)
                ->nameAr('الباركود')
                ->descAr('مسح الأصناف بالباركود — وصنف واحد بأكثر من رمز: '
                    . 'رمز الحبّة ورمز الكرتون بحجم عبوته.')
                ->group('الأصناف')->icon('qr_code_scanner')
                ->minPlan(A::PLAN_STARTER)
                ->routes(['retail/scan'])
                ->permissions(['retail.product.*']),

            C::make('retail.catalog')
                ->nameAr('التصنيفات والعلامات والوحدات')
                ->descAr('شجرة تصنيفات تُجيب «كم بعتُ من المواد الغذائية؟»، وعلامات '
                    . 'تجارية، ووحدات قياس تعرف أنّ نصف كيلو صواب ونصف حبّة خطأ.')
                ->group('الأصناف')->icon('category')
                ->minPlan(A::PLAN_STARTER)
                ->permissions(['retail.catalog.*', 'retail.product.*'])
                ->routes(['retail/categories', 'retail/brands', 'retail/units'])
                ->screen('/retail/catalog'),

            C::make('retail.variants')
                ->nameAr('متغيّرات الصنف')
                ->descAr('قميص بثلاثة ألوان وثلاثة مقاسات صنف واحد بتسعة متغيّرات — '
                    . 'لا تسعة أصناف مستقلّة يُنسى تعديل أحدها.')
                ->group('الأصناف')->icon('style')
                ->minPlan(A::PLAN_BUSINESS)
                ->permissions(['retail.product.manage'])
                ->routes(['retail/products'])
                ->screen('/retail/variants'),

            C::make('retail.price_versions')
                ->nameAr('نسخ الأسعار بالاعتماد')
                ->descAr('السعر نسخة لها تاريخ سريان ومعتمِد — تُجدوَل زيادةٌ فجر السبت '
                    . 'وتُقرأ «بكم كنّا نبيعه في رمضان؟».')
                ->group('الأصناف')->icon('sell')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->permissions(['retail.price.*'])
                ->routes(['retail/prices'])
                ->screen('/retail/prices'),

            C::make(A::F_PROMOTIONS)
                ->nameAr('العروض والخصومات')
                ->group('الأصناف')->icon('local_offer')
                ->minPlan(A::PLAN_STARTER)->screen('/promotions'),

            C::make(A::F_LOYALTY)
                ->nameAr('نقاط الولاء')
                ->group('الأصناف')->icon('loyalty')
                ->minPlan(A::PLAN_BUSINESS)->screen('/loyalty'),
        ];
    }

    // ── ④ المخزون ──────────────────────────────────────────────────────

    private static function inventory(): array
    {
        return [
            C::make(A::F_INVENTORY)
                ->nameAr('المخزون')
                ->descAr('كمّية كل صنف ورصيده الحالي.')
                ->group('المخزون')->icon('warehouse')
                ->minPlan(A::PLAN_STARTER)
                ->permissions(['retail.stock.*'])
                ->routes(['retail/ops', 'retail/products'])
                ->screen('/retail'),

            C::make(A::F_LOW_STOCK_ALERTS)
                ->nameAr('تنبيه نفاد المخزون')
                ->descAr('ما نزل تحت حدّ إعادة الطلب — ومن لم يُضبط له حدّ لا يُعدّ منخفضاً.')
                ->group('المخزون')->icon('warning_amber')
                ->minPlan(A::PLAN_STARTER)
                ->routes(['pharmacy/alerts'])
                ->screen('/retail'),

            C::make('retail.locations')
                ->nameAr('المواقع والمستودعات')
                ->descAr('مخزون كل فرع ومستودع على حدة — فبيعٌ في عدن لا ينقص مخزون المكلا، '
                    . 'ولا يُطلب توريد لفرع ممتلئ بينما يقف فرع فارغ.')
                ->group('المخزون')->icon('store_mall_directory')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->permissions(['retail.location.*', 'retail.stock.view'])
                ->routes(['retail/locations'])
                ->limit('max_locations')
                ->screen('/retail/locations'),

            C::make('retail.transfers')
                ->nameAr('التحويلات بين المواقع')
                ->descAr('نقل البضاعة بمراحلها: طلب ← اعتماد ← إرسال ← في الطريق ← استلام. '
                    . 'وما نقص في الطريق يُسجَّل بسببه ولا يُساوى بالقوّة.')
                ->group('المخزون')->icon('swap_horiz')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->permissions(['retail.transfer.*'])
                ->routes(['retail/transfers'])
                ->screen('/retail/transfers'),

            C::make(A::F_INVENTORY_AUDIT)
                ->nameAr('الجرد')
                ->descAr('عدّ المخزون ومقارنته بالنظام — ولكلّ فرق سبب واعتماد، '
                    . 'وما لم يُعدّ لا يُصفَّر.')
                ->group('المخزون')->icon('rule')
                ->minPlan(A::PLAN_STARTER)
                ->permissions(['retail.count.*'])
                ->routes(['retail/counts'])
                ->screen('/retail/counts'),

            C::make('retail.waste')
                ->nameAr('الهالك')
                ->descAr('إتلاف بضاعة بسبب مذكور وباعتماد — ومن يُتلف بلا معتمِد '
                    . 'يُخرج بضاعة بلا أثر، والجرد بعدها لا يجد فرقاً.')
                ->group('المخزون')->icon('delete_outline')
                ->minPlan(A::PLAN_BUSINESS)
                ->permissions(['retail.waste.*'])
                ->routes(['retail/wastes'])
                ->screen('/retail/wastes'),

            C::make(A::F_SUPPLIERS)
                ->nameAr('الموردون')
                ->group('المخزون')->icon('local_shipping')
                // AMIAL-ENTITLEMENTS-002 — المساراتُ نسبةً إلى `merchant/`
                // (كما يقرؤها `EntitlementCenterController::coverage`).
                ->routes(['suppliers'])
                ->minPlan(A::PLAN_BUSINESS)->screen('/suppliers'),

            C::make(A::F_PURCHASES)
                ->nameAr('أوامر الشراء')
                ->descAr('طلب بضاعة من مورّد واستلامها كاملة أو جزئية.')
                ->group('المخزون')->icon('shopping_cart')
                ->minPlan(A::PLAN_BUSINESS)
                ->permissions(['retail.purchase.*'])
                ->routes(['purchase-orders'])
                ->screen('/purchase-orders'),
        ];
    }

    // ── ⑤ الناس ────────────────────────────────────────────────────────

    private static function people(): array
    {
        return [
            C::make(A::F_CUSTOMERS)
                ->nameAr('العملاء')
                ->group('الناس')->icon('people')
                ->minPlan(A::PLAN_BUSINESS)// AMIAL-ENTITLEMENTS-006 — **المسارُ مفردٌ لا بادئة**: `credit/*`
                // تخدم `debts` المجّانيّة أيضاً، فحراسةُ البادئة تُقفلها.
                ->routes(['credit/customers'])
                ->screen('/customers'),

            C::make(A::F_EMPLOYEES)
                ->nameAr('الموظفون')
                ->descAr('نقاط بيع للموظفين، لكل واحد رمزه وحسابه.')
                ->group('الناس')->icon('badge')
                ->minPlan(A::PLAN_BUSINESS)
                ->permissions(['staff.*'])
                ->limit('max_employees')->screen('/staff'),

            C::make(A::F_RBAC)
                ->nameAr('الأدوار والصلاحيات')
                ->descAr('أدوار يبنيها المالك بنطاق وحدّ: «الكاشير يخصم حتى ٥٠٠ '
                    . 'ويغلق ورديّته هو وحدها».')
                ->group('الناس')->icon('admin_panel_settings')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->permissions(['role.*'])
                ->routes(['retail/roles'])
                ->screen('/retail/roles'),

            // **ومثلُها: سقفُ الخصم مُعلَنٌ ولا يُفرَض في أيّ موضع.**
            //
            // لا عمودَ سقفٍ في أدوار التجزئة، ولا فحصَ في مسار البيع.
            // فالدورُ يحمل `retail.discount.apply` أو لا يحمله — **وحين
            // يحمله فلا سقف**. أي أنّ «سقف الخصم» اسمٌ بلا رقم.
            C::make('retail.discount_limit')
                ->nameAr('سقف الخصم للموظف')
                ->descAr('حدّ أعلى لما يخصمه كل دور — مبلغ لا نسبة، فالنسبة تُقاس '
                    . 'على إجمالي يكتبه الكاشير نفسه.')
                ->group('الناس')->icon('percent')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->permissions(['retail.discount.apply'])
                ->comingSoon(),

            // ══════════════════════════════════════════════════════════
            // **مصدرا حقيقةٍ تناقضا، والحسمُ لجدول الحدود.**
            //
            // كانت `minPlan(BUSINESS)` — وجدولُ الحدود يقول:
            //
            //     مجّاني 1 · البداية 1 · الأعمال 3 · محترف ∞
            //
            // فالمجّانيُّ **يُباع له مقعدٌ واحد**، وباقةُ الأعمال تُميَّز
            // بالعدد لا بأصل الحقّ. ومع `minPlan(BUSINESS)` كان يقع هذا:
            //
            //   تاجرٌ مجّانيٌّ يفتح شاشةَ الأجهزة ⇒ ٤٠٢ «ارفع الباقة»
            //     ⇒ ولا يُسجّل **المقعدَ الذي تبيعه له باقتُه**
            //       ⇒ فلا يعمل جهازُ بيعٍ واحدٌ على المجّانيّ إطلاقاً
            //
            // وقِيس بالتشغيل: `PosDeviceBypassMatrixTest` سقط عليه.
            //
            // **فالتمييزُ حدٌّ لا بوّابة**: التسجيلُ متاحٌ للكلّ،
            // و`max_pos_devices` يفرض العدد — وهو مشتقٌّ من الباقة.
            // (‏وإن أُريد للأجهزة أن تكون مدفوعةً من أصلها فالتغييرُ في
            // جدول الحدود: `free`/`starter` إلى صفر — وذاك قرارُ تسعير.)
            C::make(A::F_MULTI_POS)
                ->nameAr('أجهزة نقاط البيع')
                ->descAr('مقعدُ ترخيصٍ لكلّ جهازٍ يعمل على نقطة البيع — '
                    . 'والموظّفون يتناوبون عليه، فالجهازُ ليس موظّفاً. '
                    . 'والعددُ بحسب الباقة.')
                ->group('الناس')->icon('devices')
                ->minPlan(A::PLAN_FREE)->limit('max_pos_devices')
                ->routes(['pos-devices'])
                ->screen('/pos-devices'),

            C::make(A::F_SHIFT_CLOSE)
                ->nameAr('الورديات وإغلاق الصندوق')
                ->descAr('عهدة ونقد وردية ومصروفاتها — والمتوقَّع يُحسب من الحركة '
                    . 'فلا يظهر مصروف الكاشير عجزاً في وجهه.')
                ->group('الناس')->icon('schedule')
                ->minPlan(A::PLAN_BUSINESS)
                ->permissions(['shift.*'])->screen('/shifts'),
        ];
    }

    // ── ⑥ المال والتقارير ──────────────────────────────────────────────

    private static function finance(): array
    {
        return [
            C::make(A::F_DAILY_REPORTS)
                ->nameAr('تقرير اليوم')
                ->group('التقارير')->icon('today')
                ->minPlan(A::PLAN_FREE)->screen('/reports/daily'),

            C::make(A::F_PROFIT_REPORTS)
                ->nameAr('تقرير الربحية')
                ->descAr('إيراد وتكلفة وربح وهامش — محسوبة من تكلفة ملتقَطة لحظة البيع، '
                    . 'وما لا تُعرف تكلفته يُعزل ويُقال عدده.')
                ->group('التقارير')->icon('trending_up')
                // **مسارٌ واحدٌ لا بادئة**: تقريرُ الربحيّة نهايةٌ مفردةٌ
                // داخل مجموعة الكاشير، وحراسةُ `cashier` كلِّها تُقفل
                // نقطةَ البيع نفسَها — وهي مجّانيّة.
                ->routes(['cashier/profit-report'])
                ->minPlan(A::PLAN_BUSINESS)->screen('/reports/profit'),

            C::make(A::F_ADVANCED_REPORTS)
                ->nameAr('التقارير المتقدمة')
                ->group('التقارير')->icon('analytics')
                ->minPlan(A::PLAN_BUSINESS)->screen('/reports'),

            C::make(A::F_EXCEL_EXPORT)
                ->nameAr('تصدير Excel')
                ->group('التقارير')->icon('grid_on')
                ->minPlan(A::PLAN_BUSINESS)->screen('/export'),

            C::make(A::F_EXPENSES)
                ->nameAr('المصروفات')
                ->group('التقارير')->icon('payments')
                ->minPlan(A::PLAN_BUSINESS)->screen('/expenses'),

            C::make(A::F_AUDIT_LOG)
                ->nameAr('سجلّ التدقيق المفصَّل')
                ->descAr('من فعل ماذا ومتى ومن أيّ جهاز.')
                ->group('التقارير')->icon('fact_check')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->permissions(['audit.view'])->screen('/audit-log'),

            C::make(A::F_ADVANCED_BACKUP)
                ->nameAr('النسخ الاحتياطي المتقدم')
                ->group('التقارير')->icon('backup')
                ->minPlan(A::PLAN_MERCHANT_PRO)->screen('/backup'),

            C::make(A::F_BRANCHES)
                ->nameAr('الفروع')
                ->group('التقارير')->icon('account_tree')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->routes(['branches'])
                ->limit('max_branches')->screen('/branches'),

            C::make(A::F_BRANCH_REPORTS)
                ->nameAr('تقارير الفروع')
                ->group('التقارير')->icon('leaderboard')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->routes(['branches/{id}/report']),

            C::make(A::F_MULTI_CURRENCY)
                ->nameAr('تعدد العملات')
                ->group('التقارير')->icon('currency_exchange')
                ->minPlan(A::PLAN_MERCHANT_PRO)->screen('/currencies'),

            C::make(A::F_INSTALLMENTS)
                ->nameAr('الأقساط')
                ->group('التقارير')->icon('event_repeat')
                ->minPlan(A::PLAN_MERCHANT_PRO)->screen('/installments'),
        ];
    }

    // ── ⑦ خاصّ بالقطاعات ───────────────────────────────────────────────

    private static function verticals(): array
    {
        return [
            C::make(A::F_FUEL_POS)
                ->nameAr('بيع الوقود')
                ->group('الوقود')->icon('local_gas_station')
                ->minPlan(A::PLAN_FREE)
                ->businessTypes([A::BIZ_FUEL])->screen('/fuel'),

            C::make(A::F_FUEL_PUMPS)
                ->nameAr('المضخات والمسدسات')
                ->group('الوقود')->icon('ev_station')
                ->minPlan(A::PLAN_FREE)
                ->businessTypes([A::BIZ_FUEL])
                ->permissions(['fuel.pump.*'])
                ->routes(['fuel/tanks', 'fuel/pumps', 'fuel/nozzles'])
                ->screen('/fuel/tanks'),

            C::make(A::F_FUEL_VARIANCE)
                ->nameAr('مصالحة المخزون الرطب')
                ->descAr('الفرق بين ما خرج من المسدسات وما نقص من الخزان — '
                    . 'فقدُ لترات لا يظهر في أي تقرير مالي.')
                ->group('الوقود')->icon('science')
                ->minPlan(A::PLAN_BUSINESS)
                ->businessTypes([A::BIZ_FUEL])
                ->permissions(['fuel.recon.*'])
                ->routes(['fuel/stock-variances'])
                ->screen('/fuel/variances'),

            C::make(A::F_FUEL_CARDS)
                ->nameAr('بطاقات الوقود وحسابات الشركات')
                ->group('الوقود')->icon('credit_card')
                ->minPlan(A::PLAN_BUSINESS)
                ->businessTypes([A::BIZ_FUEL])
                ->routes(['fuel/companies/{id}/cards']),

            C::make(A::F_PHARMACY_POS)
                ->nameAr('بيع الصيدلية')
                ->group('الصيدلية')->icon('medication')
                ->minPlan(A::PLAN_FREE)
                ->businessTypes([A::BIZ_PHARMACY])->screen('/pharmacy'),

            C::make(A::F_PHARMACY_BATCHES)
                ->nameAr('الدفعات وتواريخ الصلاحية')
                ->group('الصيدلية')->icon('event_busy')
                ->minPlan(A::PLAN_STARTER)
                ->businessTypes([A::BIZ_PHARMACY])
                ->routes(['pharmacy/products/{id}/batches']),

            C::make(A::F_PHARMACY_PRESCRIPTIONS)
                ->nameAr('الوصفات الطبية')
                ->descAr('وسمُ الصنف «يحتاج وصفة» فيُوقَف بيعُه بلا رقمها، '
                    . 'وتوثيقُ الطبيب وتاريخِ الوصفة على البيعة.')
                ->group('الصيدلية')->icon('description')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->businessTypes([A::BIZ_PHARMACY])
                ->routes(['pharmacy/products', 'pharmacy/sales'])
                ->screen('/pharmacy'),

            C::make(A::F_WHOLESALE_INVOICES)
                ->nameAr('فواتير الجملة')
                ->group('الجملة')->icon('request_quote')
                ->minPlan(A::PLAN_FREE)
                ->businessTypes([A::BIZ_WHOLESALE])->screen('/wholesale'),

            C::make(A::F_WHOLESALE_MULTI_PRICING)
                ->nameAr('تسعير متعدد المستويات')
                ->group('الجملة')->icon('price_change')
                ->minPlan(A::PLAN_MERCHANT_PRO)
                ->businessTypes([A::BIZ_WHOLESALE])
                ->routes(['wholesale/price-tiers']),

            C::make(A::F_RESTAURANT_TABLES)
                ->nameAr('الطاولات والطلبات')
                ->group('المطاعم')->icon('table_restaurant')
                ->minPlan(A::PLAN_FREE)
                ->businessTypes([A::BIZ_RESTAURANT])->screen('/restaurant'),
        ];
    }

    // ── ⑧ المؤسسي ──────────────────────────────────────────────────────

    private static function enterprise(): array
    {
        return [
            C::make(A::F_API_ACCESS)
                ->nameAr('واجهة برمجية (API)')
                ->descAr('مفاتيح تربط أنظمتك الأخرى بأميال باي.')
                ->group('المؤسسي')->icon('api')
                ->minPlan(A::PLAN_ENTERPRISE)->screen('/api-keys'),

            C::make(A::F_CORPORATE_ACCOUNTS)
                ->nameAr('حسابات الشركات')
                ->descAr('بيع على حساب شركة بحدّ ائتماني وأعضاء.')
                ->group('المؤسسي')->icon('business')
                ->minPlan(A::PLAN_ENTERPRISE)->screen('/corporate'),

            // **يُحرَس مع `corporate_accounts` في المتحكّم نفسِه** —
            // فالسطحُ المؤسسيُّ كلُّه خلف بوّابةٍ واحدةٍ في
            // `CorporateAccountController::__construct`، وكلاهما
            // `enterprise`. والمساراتُ تُعلَن هنا ليقرأها التقريرُ
            // فيقول الحقيقة: **قدرةٌ بلا مسارٍ مُعلَنٍ تُقرأ «بلا حارس»
            // وهي محروسة** — بلاغٌ كاذبٌ يُنفق عليه وقتٌ ثمّ لا شيء.
            C::make(A::F_CORPORATE_CREDIT_LIMITS)
                ->nameAr('الحدود الائتمانية للشركات')
                ->group('المؤسسي')->icon('credit_score')
                ->minPlan(A::PLAN_ENTERPRISE)
                ->routes(['corporate/accounts']),

            C::make(A::F_OPERATIONS_MANAGER)
                ->nameAr('مدير العمليات')
                ->group('المؤسسي')->icon('supervisor_account')
                ->minPlan(A::PLAN_ENTERPRISE),

            C::make(A::F_FINANCIAL_MANAGER)
                ->nameAr('المدير المالي')
                ->group('المؤسسي')->icon('account_balance')
                ->minPlan(A::PLAN_ENTERPRISE),
        ];
    }
}
