<?php

namespace App\Support\Merchant;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلتان ٤ و٥ — **فهرسُ الأفعال**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ما كان: سبعةُ أعلامٍ على مستوى **الشاشة** —
 * `F_FUEL_POS` · `F_FUEL_PUMPS` · `F_FUEL_SHIFTS` …
 *
 * **و«يرى شاشة الورديات» ليست «يعتمد إغلاق وردية».** والعَلَمُ الواحد
 * يمنح الاثنين، فيصير الكاشيرُ مشرفاً بلا أن يقرّر أحد.
 *
 * فالصلاحيّةُ هنا `مورد.فعل`، وتُقرن في `merchant_role_permissions`
 * بنطاقٍ وحدٍّ واعتماد.
 *
 * **وليست خاصّةً بالوقود:** ما تحت `PLATFORM` و`STAFF` و`CASH` ترثه
 * الصيدليّةُ والمطعمُ والجملةُ بلا سطرٍ إضافيّ. وهذا هو اختبارُ القاعدة
 * الأولى في `amial-verticals`.
 */
final class MerchantPermissions
{
    // ── نواةُ التاجر — لكلّ القطاعات ─────────────────────────────────
    public const STAFF_VIEW = 'staff.view';
    public const STAFF_MANAGE = 'staff.manage';
    public const ROLE_VIEW = 'role.view';
    public const ROLE_MANAGE = 'role.manage';

    public const CASH_COUNT = 'cash.count';
    public const CASH_MOVE = 'cash.move';
    public const CASH_ADJUST = 'cash.adjust';

    public const SHIFT_OPEN = 'shift.open';
    public const SHIFT_CLOSE = 'shift.close';
    public const SHIFT_APPROVE = 'shift.approve';
    public const SHIFT_VIEW_ALL = 'shift.view_all';

    public const SETTLEMENT_VIEW = 'settlement.view';
    public const SETTLEMENT_REQUEST = 'settlement.request';

    public const REPORT_SALES = 'report.sales';
    public const REPORT_FINANCIAL = 'report.financial';
    public const REPORT_STAFF = 'report.staff';

    public const LEDGER_VIEW = 'ledger.view';
    public const ADJUSTMENT_REQUEST = 'adjustment.request';
    public const ADJUSTMENT_APPROVE = 'adjustment.approve';

    public const AUDIT_VIEW = 'audit.view';
    public const SETTINGS_MANAGE = 'settings.manage';

    // ── قطاعُ الوقود وحدَه ────────────────────────────────────────────
    public const FUEL_SALE_CREATE = 'fuel.sale.create';
    public const FUEL_SALE_CANCEL = 'fuel.sale.cancel';
    public const FUEL_SALE_REFUND = 'fuel.sale.refund';
    public const FUEL_SALE_VIEW_ALL = 'fuel.sale.view_all';

    public const FUEL_PRICE_VIEW = 'fuel.price.view';
    public const FUEL_PRICE_PROPOSE = 'fuel.price.propose';
    public const FUEL_PRICE_APPROVE = 'fuel.price.approve';

    public const FUEL_PUMP_VIEW = 'fuel.pump.view';
    public const FUEL_PUMP_MANAGE = 'fuel.pump.manage';
    public const FUEL_METER_READ = 'fuel.meter.read';

    public const FUEL_TANK_VIEW = 'fuel.tank.view';
    public const FUEL_TANK_MANAGE = 'fuel.tank.manage';
    public const FUEL_DIP_RECORD = 'fuel.dip.record';

    public const FUEL_DELIVERY_RECEIVE = 'fuel.delivery.receive';
    public const FUEL_DELIVERY_VERIFY = 'fuel.delivery.verify';
    public const FUEL_DELIVERY_POST = 'fuel.delivery.post';

    public const FUEL_RECON_VIEW = 'fuel.recon.view';
    public const FUEL_RECON_RESOLVE = 'fuel.recon.resolve';

    public const FUEL_COMPANY_MANAGE = 'fuel.company.manage';

    // ══════════ التجزئة — AMIAL-RETAIL-VERTICAL-001 · المرحلة ٨ ══════════

    public const RETAIL_PRODUCT_VIEW = 'retail.product.view';
    public const RETAIL_PRODUCT_MANAGE = 'retail.product.manage';
    public const RETAIL_CATALOG_MANAGE = 'retail.catalog.manage';

    public const RETAIL_PRICE_VIEW = 'retail.price.view';
    public const RETAIL_PRICE_PROPOSE = 'retail.price.propose';
    public const RETAIL_PRICE_APPROVE = 'retail.price.approve';

    /**
     * **الخصمُ فعلٌ له حدّ** — و`max_amount` في `merchant_role_permissions`
     * هو الحدّ نفسُه بلا بناءٍ جديد: «الكاشير حتّى ٥٠٠ والمدير حتّى ٥٠٠٠».
     */
    public const RETAIL_DISCOUNT_APPLY = 'retail.discount.apply';

    public const RETAIL_STOCK_VIEW = 'retail.stock.view';
    public const RETAIL_STOCK_ADJUST = 'retail.stock.adjust';

    public const RETAIL_LOCATION_MANAGE = 'retail.location.manage';

    public const RETAIL_TRANSFER_REQUEST = 'retail.transfer.request';
    public const RETAIL_TRANSFER_APPROVE = 'retail.transfer.approve';
    public const RETAIL_TRANSFER_SHIP = 'retail.transfer.ship';
    public const RETAIL_TRANSFER_RECEIVE = 'retail.transfer.receive';

    public const RETAIL_COUNT_START = 'retail.count.start';
    public const RETAIL_COUNT_ENTER = 'retail.count.enter';
    public const RETAIL_COUNT_APPROVE = 'retail.count.approve';

    public const RETAIL_WASTE_RECORD = 'retail.waste.record';
    public const RETAIL_WASTE_APPROVE = 'retail.waste.approve';

    public const RETAIL_RETURN_CREATE = 'retail.return.create';
    public const RETAIL_RETURN_APPROVE = 'retail.return.approve';

    public const RETAIL_PURCHASE_VIEW = 'retail.purchase.view';
    public const RETAIL_PURCHASE_MANAGE = 'retail.purchase.manage';
    public const RETAIL_PURCHASE_RECEIVE = 'retail.purchase.receive';

    // ══════════ الصيدليّة — AMIAL-VERTICAL-RBAC-001 ══════════
    //
    // **وقِيس قبل أن يُكتب رمز**: `PharmacyController` فيه سبعةَ عشرَ فعلاً
    // و**صفرُ فحصِ صلاحيّة**. فكاشيرُ الصيدليّة يُعدّل الأصناف، ويُضيف
    // تشغيلات، ويُغلق تنبيهَ انتهاء صلاحيّة، **ويقرأ ويكتب السجلَّ الطبّيّ
    // للمريض** — حساسيّاتِه وأمراضَه المزمنة وحملَه.

    public const PHARMACY_PRODUCT_VIEW = 'pharmacy.product.view';
    public const PHARMACY_PRODUCT_MANAGE = 'pharmacy.product.manage';

    /** **إضافةُ تشغيلةٍ استلامُ مخزونٍ بتاريخ صلاحيّة** — لا إدخالُ بيانات. */
    public const PHARMACY_BATCH_VIEW = 'pharmacy.batch.view';
    public const PHARMACY_BATCH_RECORD = 'pharmacy.batch.record';
    /** سحب دفعة من البيع قرار سلامة، لا مجرد تعديل مخزون. */
    public const PHARMACY_BATCH_RECALL = 'pharmacy.batch.recall';
    /** الإرجاع للمورد أو الإتلاف يغيّر رصيداً ويجب أن يبقى قابلاً للتدقيق. */
    public const PHARMACY_BATCH_DISPOSE = 'pharmacy.batch.dispose';

    public const PHARMACY_SALE_CREATE = 'pharmacy.sale.create';
    public const PHARMACY_SALE_VIEW_ALL = 'pharmacy.sale.view_all';

    /**
     * **السجلُّ الطبّيّ ليس «بيانات عميل».**
     *
     * `addCustomer` يقبل `allergies` و`chronic_conditions` و`is_pregnant`
     * و`regular_medications`. ومن يبيع علبةَ دواءٍ لا يحتاج قراءةَ ملفّ
     * المريض، ومن يكتبه خطأً يُنتج تحذيراً دوائيّاً كاذباً أو يُسكِت
     * صادقاً. فتُفصَل القراءةُ عن الكتابة، وكلتاهما عن البيع.
     */
    public const PHARMACY_PATIENT_VIEW = 'pharmacy.patient.view';
    public const PHARMACY_PATIENT_MANAGE = 'pharmacy.patient.manage';

    /** توثيقُ الوصفة — وهي مقيَّدةٌ بالباقة أيضاً، والقيدان مستقلّان. */
    public const PHARMACY_PRESCRIPTION_RECORD = 'pharmacy.prescription.record';

    /**
     * **وإغلاقُ التنبيه فعلٌ لا عرض.** تنبيهُ «تشغيلةٌ تنتهي بعد شهر»
     * يُغلَق فيختفي — ومن أغلقه بلا معالجةٍ باع دواءً منتهياً بلا إنذار.
     */
    public const PHARMACY_ALERT_VIEW = 'pharmacy.alert.view';
    public const PHARMACY_ALERT_DISMISS = 'pharmacy.alert.dismiss';

    // ══════════ الجملة — AMIAL-VERTICAL-RBAC-001 ══════════
    //
    // خمسةٌ وعشرون فعلاً وصفرُ فحص — وفيها **إبطالُ فاتورةٍ** و**تسجيلُ
    // تحصيلٍ نقديّ**. فمندوبُ مبيعاتٍ يُبطل فاتورةَ مليونٍ بسببٍ يكتبه هو،
    // ويُسجّل تحصيلاً لم يُقبَض، **ولا يمرّ ذلك بأحد**.

    public const WHOLESALE_PRODUCT_VIEW = 'wholesale.product.view';
    public const WHOLESALE_PRODUCT_MANAGE = 'wholesale.product.manage';
    public const WHOLESALE_STOCK_ADJUST = 'wholesale.stock.adjust';

    public const WHOLESALE_PRICE_VIEW = 'wholesale.price.view';
    public const WHOLESALE_PRICE_SET = 'wholesale.price.set';
    public const WHOLESALE_TIER_MANAGE = 'wholesale.tier.manage';

    public const WHOLESALE_CUSTOMER_VIEW = 'wholesale.customer.view';
    public const WHOLESALE_CUSTOMER_MANAGE = 'wholesale.customer.manage';

    public const WHOLESALE_INVOICE_VIEW = 'wholesale.invoice.view';
    public const WHOLESALE_INVOICE_CREATE = 'wholesale.invoice.create';

    /** **الإبطالُ ليس تعديلاً** — يمحو ديناً قائماً بسطرِ سبب. */
    public const WHOLESALE_INVOICE_VOID = 'wholesale.invoice.void';

    /** المرتجع يمرّ بطلب ثم قرار؛ لا يُستبدل بإبطال الفاتورة. */
    public const WHOLESALE_RETURN_VIEW = 'wholesale.return.view';
    public const WHOLESALE_RETURN_REQUEST = 'wholesale.return.request';
    public const WHOLESALE_RETURN_APPROVE = 'wholesale.return.approve';

    public const WHOLESALE_COLLECTION_VIEW = 'wholesale.collection.view';
    public const WHOLESALE_COLLECTION_RECORD = 'wholesale.collection.record';

    public const WHOLESALE_REP_VIEW = 'wholesale.rep.view';
    public const WHOLESALE_REP_MANAGE = 'wholesale.rep.manage';

    /** أعمارُ الديون وكشفُ حساب العميل وأداءُ المندوبين. */
    public const WHOLESALE_REPORT_VIEW = 'wholesale.report.view';

    // ══════════ المطعم — AMIAL-VERTICAL-RBAC-001 ══════════

    public const RESTAURANT_TABLE_VIEW = 'restaurant.table.view';
    public const RESTAURANT_TABLE_MANAGE = 'restaurant.table.manage';

    public const RESTAURANT_ORDER_VIEW_ALL = 'restaurant.order.view_all';
    public const RESTAURANT_ORDER_OPEN = 'restaurant.order.open';
    public const RESTAURANT_ORDER_UPDATE = 'restaurant.order.update';

    /** تقديمُ الطلب في مساره (مطبخ ← جاهز ← مُقدَّم) — عملُ المطبخ. */
    public const RESTAURANT_ORDER_STATUS = 'restaurant.order.status';

    /** **والإغلاقُ قبضٌ** — يُنهي الطلبَ ويُثبت المبلغ. */
    public const RESTAURANT_ORDER_CLOSE = 'restaurant.order.close';

    public const RESTAURANT_KITCHEN_VIEW = 'restaurant.kitchen.view';

    /**
     * كلُّ الأفعال مع مجموعتها واسمها — **مصدرٌ واحد**.
     *
     * تقرؤه شاشةُ الأدوار، ويتحقّق منه المحرّك، وتُبنى منه البذور. فقائمةٌ
     * ثانيةٌ في الواجهة تنحرف عن هذه عند أوّل إضافة.
     *
     * @return array<string,array{group:string,name:string,sensitive:bool}>
     */
    public static function catalogue(): array
    {
        $g = static fn (string $group, string $name, bool $sensitive = false): array
            => ['group' => $group, 'name' => $name, 'sensitive' => $sensitive];

        return [
            self::STAFF_VIEW => $g('الموظفون', 'عرض الموظفين'),
            self::STAFF_MANAGE => $g('الموظفون', 'إضافة وتعديل الموظفين', true),
            self::ROLE_VIEW => $g('الموظفون', 'عرض الأدوار'),
            self::ROLE_MANAGE => $g('الموظفون', 'إنشاء الأدوار والصلاحيات', true),

            self::CASH_COUNT => $g('الصندوق', 'جرد الصندوق'),
            self::CASH_MOVE => $g('الصندوق', 'إدخال/إخراج نقد ومصروفات'),
            self::CASH_ADJUST => $g('الصندوق', 'تسوية فرق الصندوق', true),

            self::SHIFT_OPEN => $g('الورديات', 'فتح وردية'),
            self::SHIFT_CLOSE => $g('الورديات', 'إغلاق وردية'),
            self::SHIFT_APPROVE => $g('الورديات', 'اعتماد إغلاق وردية', true),
            self::SHIFT_VIEW_ALL => $g('الورديات', 'عرض ورديات الجميع'),

            self::SETTLEMENT_VIEW => $g('المالية', 'عرض التسويات'),
            self::SETTLEMENT_REQUEST => $g('المالية', 'طلب تسوية', true),
            self::LEDGER_VIEW => $g('المالية', 'عرض الدفتر'),
            self::ADJUSTMENT_REQUEST => $g('المالية', 'طلب قيد تسوية', true),
            self::ADJUSTMENT_APPROVE => $g('المالية', 'اعتماد قيد تسوية', true),

            self::REPORT_SALES => $g('التقارير', 'تقارير المبيعات'),
            self::REPORT_FINANCIAL => $g('التقارير', 'التقارير المالية'),
            self::REPORT_STAFF => $g('التقارير', 'تقارير الموظفين'),

            self::AUDIT_VIEW => $g('الأمان', 'سجل التدقيق'),
            self::SETTINGS_MANAGE => $g('الأمان', 'إعدادات المنشأة', true),

            self::FUEL_SALE_CREATE => $g('الوقود — البيع', 'بيع وقود'),
            self::FUEL_SALE_CANCEL => $g('الوقود — البيع', 'إلغاء عملية', true),
            self::FUEL_SALE_REFUND => $g('الوقود — البيع', 'استرجاع', true),
            self::FUEL_SALE_VIEW_ALL => $g('الوقود — البيع', 'عرض عمليات الجميع'),

            self::FUEL_PRICE_VIEW => $g('الوقود — الأسعار', 'عرض الأسعار'),
            self::FUEL_PRICE_PROPOSE => $g('الوقود — الأسعار', 'اقتراح سعر'),
            self::FUEL_PRICE_APPROVE => $g('الوقود — الأسعار', 'اعتماد سعر', true),

            self::FUEL_PUMP_VIEW => $g('الوقود — المضخات', 'عرض المضخات'),
            self::FUEL_PUMP_MANAGE => $g('الوقود — المضخات', 'إدارة المضخات والمسدسات', true),
            self::FUEL_METER_READ => $g('الوقود — المضخات', 'تسجيل قراءة عداد'),

            self::FUEL_TANK_VIEW => $g('الوقود — الخزانات', 'عرض الخزانات'),
            self::FUEL_TANK_MANAGE => $g('الوقود — الخزانات', 'إدارة الخزانات', true),
            self::FUEL_DIP_RECORD => $g('الوقود — الخزانات', 'تسجيل قياس خزان'),

            self::FUEL_DELIVERY_RECEIVE => $g('الوقود — التوريدات', 'استلام توريد'),
            self::FUEL_DELIVERY_VERIFY => $g('الوقود — التوريدات', 'التحقق من توريد'),
            self::FUEL_DELIVERY_POST => $g('الوقود — التوريدات', 'ترحيل توريد للمخزون', true),

            self::FUEL_RECON_VIEW => $g('الوقود — المصالحة', 'عرض الفروقات'),
            self::FUEL_RECON_RESOLVE => $g('الوقود — المصالحة', 'إغلاق تحقيق فرق', true),

            self::FUEL_COMPANY_MANAGE => $g('الوقود — الآجل', 'إدارة حسابات الشركات', true),

            self::RETAIL_PRODUCT_VIEW => $g('التجزئة — الأصناف', 'عرض الأصناف'),
            self::RETAIL_PRODUCT_MANAGE => $g('التجزئة — الأصناف', 'إضافة وتعديل الأصناف', true),
            self::RETAIL_CATALOG_MANAGE => $g('التجزئة — الأصناف', 'التصنيفات والعلامات والوحدات', true),

            self::RETAIL_PRICE_VIEW => $g('التجزئة — الأسعار', 'عرض الأسعار'),
            self::RETAIL_PRICE_PROPOSE => $g('التجزئة — الأسعار', 'اقتراح سعر'),
            self::RETAIL_PRICE_APPROVE => $g('التجزئة — الأسعار', 'اعتماد سعر', true),
            self::RETAIL_DISCOUNT_APPLY => $g('التجزئة — الأسعار', 'منح خصم على البيع', true),

            self::RETAIL_STOCK_VIEW => $g('التجزئة — المخزون', 'عرض المخزون'),
            self::RETAIL_STOCK_ADJUST => $g('التجزئة — المخزون', 'تعديل المخزون مباشرة', true),
            self::RETAIL_LOCATION_MANAGE => $g('التجزئة — المخزون', 'إدارة المواقع والمستودعات', true),

            self::RETAIL_TRANSFER_REQUEST => $g('التجزئة — التحويلات', 'طلب تحويل'),
            self::RETAIL_TRANSFER_APPROVE => $g('التجزئة — التحويلات', 'اعتماد تحويل', true),
            self::RETAIL_TRANSFER_SHIP => $g('التجزئة — التحويلات', 'إرسال تحويل', true),
            self::RETAIL_TRANSFER_RECEIVE => $g('التجزئة — التحويلات', 'استلام تحويل'),

            self::RETAIL_COUNT_START => $g('التجزئة — الجرد', 'فتح جرد'),
            self::RETAIL_COUNT_ENTER => $g('التجزئة — الجرد', 'إدخال العدّ'),
            self::RETAIL_COUNT_APPROVE => $g('التجزئة — الجرد', 'اعتماد الجرد وتسوية الفروق', true),

            self::RETAIL_WASTE_RECORD => $g('التجزئة — الهالك', 'تسجيل هالك'),
            self::RETAIL_WASTE_APPROVE => $g('التجزئة — الهالك', 'اعتماد الهالك', true),

            self::RETAIL_RETURN_CREATE => $g('التجزئة — المرتجعات', 'إنشاء مرتجع'),
            self::RETAIL_RETURN_APPROVE => $g('التجزئة — المرتجعات', 'اعتماد المرتجع', true),

            self::RETAIL_PURCHASE_VIEW => $g('التجزئة — المشتريات', 'عرض أوامر الشراء'),
            self::RETAIL_PURCHASE_MANAGE => $g('التجزئة — المشتريات', 'إنشاء واعتماد أوامر الشراء', true),
            self::RETAIL_PURCHASE_RECEIVE => $g('التجزئة — المشتريات', 'استلام بضاعة'),

            self::PHARMACY_PRODUCT_VIEW => $g('الصيدليّة — الأصناف', 'عرض الأدوية'),
            self::PHARMACY_PRODUCT_MANAGE => $g('الصيدليّة — الأصناف', 'إضافة وتعديل الأدوية', true),

            self::PHARMACY_BATCH_VIEW => $g('الصيدليّة — التشغيلات', 'عرض التشغيلات وتواريخ الصلاحية'),
            self::PHARMACY_BATCH_RECORD => $g('الصيدليّة — التشغيلات', 'استلام تشغيلة جديدة', true),
            self::PHARMACY_BATCH_RECALL => $g('الصيدليّة — التشغيلات', 'سحب تشغيلة ومنع بيعها', true),
            self::PHARMACY_BATCH_DISPOSE => $g('الصيدليّة — التشغيلات', 'إرجاع أو إتلاف تشغيلة غير قابلة للبيع', true),

            self::PHARMACY_SALE_CREATE => $g('الصيدليّة — البيع', 'بيع دواء'),
            self::PHARMACY_SALE_VIEW_ALL => $g('الصيدليّة — البيع', 'عرض مبيعات الجميع'),

            self::PHARMACY_PATIENT_VIEW => $g('الصيدليّة — ملفّ المريض', 'قراءة الملفّ الطبّيّ', true),
            self::PHARMACY_PATIENT_MANAGE => $g('الصيدليّة — ملفّ المريض', 'تعديل الحساسيّات والأمراض المزمنة', true),
            self::PHARMACY_PRESCRIPTION_RECORD => $g('الصيدليّة — ملفّ المريض', 'توثيق وصفة طبّيّة', true),

            self::PHARMACY_ALERT_VIEW => $g('الصيدليّة — التنبيهات', 'عرض تنبيهات الصلاحية والنقص'),
            self::PHARMACY_ALERT_DISMISS => $g('الصيدليّة — التنبيهات', 'إغلاق تنبيه', true),

            self::WHOLESALE_PRODUCT_VIEW => $g('الجملة — الأصناف', 'عرض الأصناف'),
            self::WHOLESALE_PRODUCT_MANAGE => $g('الجملة — الأصناف', 'إضافة وتعديل الأصناف', true),
            self::WHOLESALE_STOCK_ADJUST => $g('الجملة — الأصناف', 'تعديل المخزون مباشرة', true),

            self::WHOLESALE_PRICE_VIEW => $g('الجملة — الأسعار', 'عرض أسعار الشرائح'),
            self::WHOLESALE_PRICE_SET => $g('الجملة — الأسعار', 'تحديد سعر صنف لشريحة', true),
            self::WHOLESALE_TIER_MANAGE => $g('الجملة — الأسعار', 'إدارة شرائح الأسعار', true),

            self::WHOLESALE_CUSTOMER_VIEW => $g('الجملة — العملاء', 'عرض العملاء وحدود الآجل'),
            self::WHOLESALE_CUSTOMER_MANAGE => $g('الجملة — العملاء', 'إضافة وتعديل العملاء', true),

            self::WHOLESALE_INVOICE_VIEW => $g('الجملة — الفواتير', 'عرض الفواتير'),
            self::WHOLESALE_INVOICE_CREATE => $g('الجملة — الفواتير', 'إنشاء فاتورة'),
            self::WHOLESALE_INVOICE_VOID => $g('الجملة — الفواتير', 'إبطال فاتورة', true),
            self::WHOLESALE_RETURN_VIEW => $g('الجملة — المرتجعات', 'عرض طلبات المرتجع'),
            self::WHOLESALE_RETURN_REQUEST => $g('الجملة — المرتجعات', 'إنشاء طلب مرتجع', true),
            self::WHOLESALE_RETURN_APPROVE => $g('الجملة — المرتجعات', 'اعتماد أو رفض المرتجع', true),

            self::WHOLESALE_COLLECTION_VIEW => $g('الجملة — التحصيل', 'عرض التحصيلات'),
            self::WHOLESALE_COLLECTION_RECORD => $g('الجملة — التحصيل', 'تسجيل تحصيل', true),

            self::WHOLESALE_REP_VIEW => $g('الجملة — المندوبون', 'عرض المندوبين'),
            self::WHOLESALE_REP_MANAGE => $g('الجملة — المندوبون', 'إضافة مندوب وتحديد عمولته', true),

            self::WHOLESALE_REPORT_VIEW => $g('الجملة — التقارير', 'أعمار الديون وكشوف الحسابات'),

            self::RESTAURANT_TABLE_VIEW => $g('المطعم — الطاولات', 'عرض الطاولات'),
            self::RESTAURANT_TABLE_MANAGE => $g('المطعم — الطاولات', 'إضافة وتعديل وحذف الطاولات', true),

            self::RESTAURANT_ORDER_VIEW_ALL => $g('المطعم — الطلبات', 'عرض طلبات الجميع'),
            self::RESTAURANT_ORDER_OPEN => $g('المطعم — الطلبات', 'فتح طلب'),
            self::RESTAURANT_ORDER_UPDATE => $g('المطعم — الطلبات', 'تعديل أصناف الطلب'),
            self::RESTAURANT_ORDER_STATUS => $g('المطعم — الطلبات', 'تغيير حالة الطلب'),
            self::RESTAURANT_ORDER_CLOSE => $g('المطعم — الطلبات', 'إغلاق الطلب وقبض قيمته', true),

            self::RESTAURANT_KITCHEN_VIEW => $g('المطعم — المطبخ', 'شاشة المطبخ'),
        ];
    }

    /** @return array<int,string> */
    public static function all(): array
    {
        return array_keys(self::catalogue());
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::catalogue());
    }

    /**
     * الأدوارُ الستّةُ بذرةً — **يملكها التاجر ويعدّلها**.
     *
     * ولا تُحقن في الشيفرة: تُنسخ إلى `merchant_roles` عند تفعيل القطاع،
     * فما بعدها قرارُ المالك لا قرارُنا.
     *
     * @return array<string,array{name:string,permissions:array<int,string>}>
     */
    public static function fuelSeedRoles(): array
    {
        return [
            'owner' => [
                'name' => 'مالك المحطة',
                // **كلُّ شيء** — وحدودُه حدودُ المنصّة لا حدودُ دورِه.
                'permissions' => self::all(),
            ],

            'manager' => [
                'name' => 'مدير المحطة',
                'permissions' => [
                    self::STAFF_VIEW, self::STAFF_MANAGE, self::ROLE_VIEW,
                    self::CASH_COUNT, self::CASH_MOVE,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE, self::SHIFT_APPROVE, self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                    self::FUEL_SALE_CREATE, self::FUEL_SALE_CANCEL, self::FUEL_SALE_VIEW_ALL,
                    self::FUEL_PRICE_VIEW, self::FUEL_PRICE_PROPOSE,
                    self::FUEL_PUMP_VIEW, self::FUEL_PUMP_MANAGE, self::FUEL_METER_READ,
                    self::FUEL_TANK_VIEW, self::FUEL_DIP_RECORD,
                    self::FUEL_DELIVERY_RECEIVE, self::FUEL_DELIVERY_VERIFY,
                    self::FUEL_RECON_VIEW,
                ],
                // ولا: اعتمادَ سعرٍ، ولا ترحيلَ توريد، ولا طلبَ تسوية،
                // ولا إعداداتِ المنشأة — تلك للمالك.
            ],

            'accountant' => [
                'name' => 'محاسب المحطة',
                'permissions' => [
                    self::CASH_COUNT,
                    self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW, self::SETTLEMENT_REQUEST,
                    self::LEDGER_VIEW, self::ADJUSTMENT_REQUEST,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                    self::FUEL_SALE_VIEW_ALL, self::FUEL_PRICE_VIEW,
                    self::FUEL_TANK_VIEW, self::FUEL_RECON_VIEW,
                ],
                // **ولا تعديلَ عمليّةٍ مكتملة**: التصحيح بقيدٍ جديدٍ باعتماد.
                // ولذلك عنده `adjustment.request` ولا `adjustment.approve`.
            ],

            'supervisor' => [
                'name' => 'مشرف وردية',
                'permissions' => [
                    self::CASH_COUNT, self::CASH_MOVE,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE,
                    self::REPORT_SALES,
                    self::FUEL_SALE_CREATE, self::FUEL_SALE_CANCEL, self::FUEL_SALE_VIEW_ALL,
                    self::FUEL_PRICE_VIEW,
                    self::FUEL_PUMP_VIEW, self::FUEL_METER_READ,
                    self::FUEL_TANK_VIEW, self::FUEL_DIP_RECORD,
                ],
            ],

            'cashier' => [
                'name' => 'كاشير',
                'permissions' => [
                    self::FUEL_SALE_CREATE,
                    self::FUEL_PRICE_VIEW,
                    self::FUEL_PUMP_VIEW,
                    self::SHIFT_CLOSE,      // ورديّتَه هو — والنطاق `own`
                    self::CASH_COUNT,
                ],
                // ولا: سعرٌ، ولا حذف، ولا دفتر، ولا موظّفون، ولا تسوية،
                // ولا خزّانات، ولا عدّادات. (least privilege)
            ],

            'stock_keeper' => [
                'name' => 'موظف المخزون',
                'permissions' => [
                    self::FUEL_TANK_VIEW, self::FUEL_DIP_RECORD,
                    self::FUEL_DELIVERY_RECEIVE, self::FUEL_DELIVERY_VERIFY,
                    self::FUEL_RECON_VIEW,
                    self::FUEL_METER_READ, self::FUEL_PUMP_VIEW,
                ],
                // ولا مالَ إطلاقاً.
            ],
        ];
    }

    /**
     * قيودُ النطاق والحدّ للأدوار البذرة.
     *
     * **والكاشيرُ يُغلق ورديّتَه هو** — `own`. وبلا هذا يُغلق وردياتِ
     * زملائه ويخرج بفرقٍ ليس فرقَه.
     *
     * @return array<string,array<string,array{scope:string,limit?:string,approval?:string}>>
     */
    public static function fuelSeedScopes(): array
    {
        return [
            'cashier' => [
                self::SHIFT_CLOSE => ['scope' => 'own'],
                self::FUEL_SALE_CREATE => ['scope' => 'own'],
            ],
            'supervisor' => [
                self::SHIFT_CLOSE => ['scope' => 'shift'],
                // إلغاءٌ محدودٌ باعتمادِ مدير — لا مطلق.
                self::FUEL_SALE_CANCEL => [
                    'scope' => 'shift', 'limit' => '100000', 'approval' => 'manager',
                ],
            ],
            'manager' => [
                self::FUEL_SALE_CANCEL => ['scope' => 'station', 'limit' => '1000000'],
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  التجزئة — AMIAL-RETAIL-VERTICAL-001 · المرحلة ٨
    // ══════════════════════════════════════════════════════════════════

    /**
     * الأدوارُ الثمانية بذرةً — **ولا محرّكَ جديد**.
     *
     * `MerchantPermissionService` بُني في جولة الوقود ويجيب ثلاثةَ أسئلة:
     * أيملكها؟ أفي نطاقه؟ أتحت حدّه؟ — **وحدُّ الخصم هو `max_amount`
     * نفسُه** بلا سطرٍ جديد.
     *
     * @return array<string,array{name:string,permissions:array<int,string>}>
     */
    public static function retailSeedRoles(): array
    {
        $core = [
            self::RETAIL_PRODUCT_VIEW, self::RETAIL_PRICE_VIEW, self::RETAIL_STOCK_VIEW,
        ];

        return [
            'owner' => [
                'name' => 'مالك المتجر',
                'permissions' => self::all(),
            ],

            'store_manager' => [
                'name' => 'مدير المتجر',
                'permissions' => array_merge($core, [
                    self::STAFF_VIEW, self::STAFF_MANAGE, self::ROLE_VIEW,
                    self::CASH_COUNT, self::CASH_MOVE,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE, self::SHIFT_APPROVE, self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                    self::RETAIL_PRODUCT_MANAGE, self::RETAIL_CATALOG_MANAGE,
                    self::RETAIL_PRICE_PROPOSE,
                    self::RETAIL_DISCOUNT_APPLY,
                    self::RETAIL_TRANSFER_REQUEST, self::RETAIL_TRANSFER_APPROVE,
                    self::RETAIL_TRANSFER_RECEIVE,
                    self::RETAIL_COUNT_START, self::RETAIL_COUNT_ENTER, self::RETAIL_COUNT_APPROVE,
                    self::RETAIL_WASTE_RECORD, self::RETAIL_WASTE_APPROVE,
                    self::RETAIL_RETURN_CREATE, self::RETAIL_RETURN_APPROVE,
                    self::RETAIL_PURCHASE_VIEW, self::RETAIL_PURCHASE_MANAGE,
                ]),
                // ولا: اعتمادَ سعرٍ، ولا تعديلَ مخزونٍ مباشراً، ولا إعداداتِ
                // المنشأة — **تلك للمالك**. والتعديلُ المباشر خاصّةً: بابٌ
                // يلتفّ على الجرد كلِّه.
            ],

            'accountant' => [
                'name' => 'محاسب',
                'permissions' => array_merge($core, [
                    self::CASH_COUNT,
                    self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW, self::SETTLEMENT_REQUEST,
                    self::LEDGER_VIEW, self::ADJUSTMENT_REQUEST,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                    self::RETAIL_PURCHASE_VIEW,
                ]),
                // **ولا تعديلَ عمليّةٍ مكتملة**: التصحيح بقيدٍ جديدٍ باعتماد.
            ],

            'inventory_manager' => [
                'name' => 'مدير المخزون',
                'permissions' => array_merge($core, [
                    self::REPORT_SALES,
                    self::RETAIL_PRODUCT_MANAGE, self::RETAIL_CATALOG_MANAGE,
                    self::RETAIL_LOCATION_MANAGE,
                    self::RETAIL_TRANSFER_REQUEST, self::RETAIL_TRANSFER_APPROVE,
                    self::RETAIL_TRANSFER_SHIP, self::RETAIL_TRANSFER_RECEIVE,
                    self::RETAIL_COUNT_START, self::RETAIL_COUNT_ENTER, self::RETAIL_COUNT_APPROVE,
                    self::RETAIL_WASTE_RECORD, self::RETAIL_WASTE_APPROVE,
                    self::RETAIL_PURCHASE_VIEW, self::RETAIL_PURCHASE_MANAGE,
                    self::RETAIL_PURCHASE_RECEIVE,
                ]),
                // ولا مالَ إطلاقاً: لا دفتر، ولا تسوية، ولا خصم.
            ],

            'warehouse_staff' => [
                'name' => 'موظف مستودع',
                'permissions' => array_merge($core, [
                    self::RETAIL_TRANSFER_REQUEST, self::RETAIL_TRANSFER_SHIP,
                    self::RETAIL_TRANSFER_RECEIVE,
                    self::RETAIL_COUNT_ENTER,
                    self::RETAIL_WASTE_RECORD,
                    self::RETAIL_PURCHASE_RECEIVE,
                ]),
                // **يُدخل العدّ ولا يعتمده**، ويُسجّل الهالك ولا يعتمده.
            ],

            'cashier' => [
                'name' => 'كاشير',
                'permissions' => array_merge($core, [
                    self::SHIFT_OPEN, self::SHIFT_CLOSE,
                    self::CASH_COUNT,
                    self::RETAIL_DISCOUNT_APPLY,
                    self::RETAIL_RETURN_CREATE,
                ]),
                // ولا: تعديلَ صنفٍ، ولا سعراً، ولا جرداً، ولا اعتماداً.
            ],

            'sales_rep' => [
                'name' => 'مندوب مبيعات',
                'permissions' => array_merge($core, [
                    self::REPORT_SALES,
                    self::RETAIL_DISCOUNT_APPLY,
                    self::RETAIL_RETURN_CREATE,
                ]),
            ],

            // **دورٌ فارغٌ عمداً** — يبنيه المالك من الصفر، ولا يُمنح شيئاً
            // بحسن نيّة. (least privilege)
            'custom' => [
                'name' => 'دور مخصَّص',
                'permissions' => [],
            ],
        ];
    }

    /**
     * حدودُ التجزئة ونطاقاتُها.
     *
     * **وحدُّ الخصم هنا مبلغٌ لا نسبة.** والنسبةُ تبدو أوضح («٥٪») لكنّها
     * تُقاس على إجماليٍّ يكتبه الكاشيرُ نفسُه: فيرفع الإجماليَّ ثمّ يخصم
     * ٥٪ منه فيخرج بمبلغٍ أكبر ممّا نوى المالكُ السماحَ به. **والمبلغُ
     * سقفٌ لا يُلتفّ عليه.**
     *
     * @return array<string,array<string,array{scope:string,limit?:string,approval?:string}>>
     */
    public static function retailSeedScopes(): array
    {
        return [
            'cashier' => [
                self::SHIFT_CLOSE => ['scope' => 'own'],
                self::RETAIL_DISCOUNT_APPLY => ['scope' => 'own', 'limit' => '500'],
                self::RETAIL_RETURN_CREATE => ['scope' => 'own', 'approval' => 'manager'],
            ],
            'sales_rep' => [
                self::RETAIL_DISCOUNT_APPLY => ['scope' => 'own', 'limit' => '1000'],
            ],
            'store_manager' => [
                // **`merchant` لا `store`**: قِيس أنّ `scope_type` قائمةٌ
                // مغلقة (merchant/station/branch/shift/own)، وقيمةٌ خارجها
                // تُبتر في القاعدة ثمّ يردّها `scopeMatches` رفضاً صامتاً —
                // فيُمنع المديرُ من خصمٍ يملكه ولا يعرف لماذا.
                self::RETAIL_DISCOUNT_APPLY => ['scope' => 'merchant', 'limit' => '5000'],
            ],
            'warehouse_staff' => [
                self::RETAIL_WASTE_RECORD => ['scope' => 'own', 'approval' => 'manager'],
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  الصيدليّة والجملة والمطعم — AMIAL-VERTICAL-RBAC-001
    //
    //  **ما كان:** `VerticalBootstrapService::seedRolesFor` تزرع أدوارَ
    //  التجزئة لكلّ ما ليس وقوداً، والتعليقُ يعترف: «ولا تُخترع قائمةٌ
    //  لكلّ نشاطٍ قبل أن تُكتب صلاحيّاتُه». **وقد كُتبت الآن.**
    //
    //  فصاحبُ صيدليّةٍ كان يفتح شاشةَ الأدوار فيجد «مدير متجر · موظّف
    //  مستودع · مندوب مبيعات» — وليس فيها صيدليٌّ ولا فنّيّ. ثمّ لو أسند
    //  أيّاً منها **لم يتغيّر شيء**: لا متحكّمَ في الصيدليّة يقرأ صلاحيّة.
    //  فالقالبُ كان اسماً بلا أثر، وذاك أسوأ من غيابه: يُوهم بالضبط.
    // ══════════════════════════════════════════════════════════════════

    /**
     * **الصيدليُّ ليس الكاشير، والكاشيرُ لا يقرأ ملفّاً طبّيّاً.**
     *
     * @return array<string,array{name:string,permissions:array<int,string>}>
     */
    public static function pharmacySeedRoles(): array
    {
        $core = [
            self::PHARMACY_PRODUCT_VIEW, self::PHARMACY_BATCH_VIEW,
            self::PHARMACY_ALERT_VIEW,
        ];

        return [
            'owner' => [
                'name' => 'مالك الصيدلية',
                'permissions' => self::all(),
            ],

            'pharmacist' => [
                'name' => 'صيدلي',
                'permissions' => array_merge($core, [
                    self::PHARMACY_SALE_CREATE, self::PHARMACY_SALE_VIEW_ALL,
                    self::PHARMACY_PATIENT_VIEW, self::PHARMACY_PATIENT_MANAGE,
                    self::PHARMACY_PRESCRIPTION_RECORD,
                    self::PHARMACY_PRODUCT_MANAGE,
                    self::PHARMACY_BATCH_RECORD,
                    self::PHARMACY_BATCH_DISPOSE,
                    self::PHARMACY_ALERT_DISMISS,
                    self::CASH_COUNT,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE,
                    self::REPORT_SALES,
                ]),
                // **وهو وحدَه يوثّق الوصفة ويقرأ الملفّ الطبّيّ** — ذاك
                // عملُه المرخَّص، لا امتيازُ درجة.
            ],

            'pharmacy_technician' => [
                'name' => 'فنّي صيدلة',
                'permissions' => array_merge($core, [
                    self::PHARMACY_SALE_CREATE,
                    self::PHARMACY_BATCH_RECORD,
                    self::CASH_COUNT,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE,
                ]),
                // **ولا ملفَّ مريضٍ ولا وصفة**: يبيع ما لا وصفةَ له،
                // ويستلم التشغيلات. والصنفُ الموصوف يوقفه المحرّكُ نفسُه
                // في `PharmacySaleService`.
            ],

            'cashier' => [
                'name' => 'كاشير الصيدلية',
                'permissions' => array_merge($core, [
                    self::PHARMACY_SALE_CREATE,
                    self::CASH_COUNT,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE,
                ]),
                // ولا: تعديلَ صنف، ولا استلامَ تشغيلة، ولا إغلاقَ تنبيه.
            ],

            'inventory_clerk' => [
                'name' => 'أمين المخزون',
                'permissions' => array_merge($core, [
                    self::PHARMACY_PRODUCT_MANAGE,
                    self::PHARMACY_BATCH_RECORD,
                    self::PHARMACY_BATCH_DISPOSE,
                    self::PHARMACY_ALERT_DISMISS,
                ]),
                // ولا بيعَ ولا مالَ ولا ملفَّ مريض.
            ],

            'accountant' => [
                'name' => 'محاسب',
                'permissions' => array_merge($core, [
                    self::PHARMACY_SALE_VIEW_ALL,
                    self::CASH_COUNT, self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW, self::SETTLEMENT_REQUEST,
                    self::LEDGER_VIEW, self::ADJUSTMENT_REQUEST,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                ]),
                // **ولا ملفَّ مريضٍ للمحاسب** — يدقّق أرقاماً لا أمراضاً.
            ],

            'custom' => [
                'name' => 'دور مخصَّص',
                'permissions' => [],
            ],
        ];
    }

    /**
     * **والحدُّ على إغلاق التنبيه لا على البيع.**
     *
     * @return array<string,array<string,array{scope:string,limit?:string,approval?:string}>>
     */
    public static function pharmacySeedScopes(): array
    {
        return [
            'cashier' => [
                self::SHIFT_CLOSE => ['scope' => 'own'],
                self::PHARMACY_SALE_CREATE => ['scope' => 'own'],
            ],
            'pharmacy_technician' => [
                self::SHIFT_CLOSE => ['scope' => 'own'],
                self::PHARMACY_SALE_CREATE => ['scope' => 'own'],
            ],
            'pharmacist' => [
                self::SHIFT_CLOSE => ['scope' => 'own'],
            ],
        ];
    }

    /**
     * **الجملةُ ديونٌ قبل أن تكون بضاعة.**
     *
     * فالفصلُ الحاكم فيها: **من يبيع لا يُحصّل، ومن يُحصّل لا يُبطل**.
     * وثلاثتُها في يدٍ واحدةٍ تُخفي اختلاساً بلا أثر: يُسجَّل بيعٌ، ويُقبَض
     * نقداً، ثمّ تُبطَل الفاتورةُ فيختفي الدَّينُ والمقبوض معاً.
     *
     * @return array<string,array{name:string,permissions:array<int,string>}>
     */
    public static function wholesaleSeedRoles(): array
    {
        $core = [
            self::WHOLESALE_PRODUCT_VIEW, self::WHOLESALE_PRICE_VIEW,
            self::WHOLESALE_CUSTOMER_VIEW,
        ];

        return [
            'owner' => [
                'name' => 'مالك المؤسسة',
                'permissions' => self::all(),
            ],

            'sales_manager' => [
                'name' => 'مدير المبيعات',
                'permissions' => array_merge($core, [
                    self::STAFF_VIEW, self::ROLE_VIEW,
                    self::WHOLESALE_CUSTOMER_MANAGE,
                    self::WHOLESALE_INVOICE_VIEW, self::WHOLESALE_INVOICE_CREATE,
                    self::WHOLESALE_RETURN_VIEW, self::WHOLESALE_RETURN_REQUEST,
                    self::WHOLESALE_COLLECTION_VIEW,
                    self::WHOLESALE_REP_VIEW, self::WHOLESALE_REP_MANAGE,
                    self::WHOLESALE_REPORT_VIEW,
                    self::WHOLESALE_PRICE_SET,
                    self::REPORT_SALES, self::REPORT_STAFF,
                    self::SHIFT_VIEW_ALL,
                ]),
                // **ولا إبطالَ ولا تحصيل** — يبيع ويُسعّر ويقرأ الأعمار.
                // والإبطالُ يمحو ديناً، والتحصيلُ يُقرّ بقبضٍ: كلاهما مالٌ
                // لا مبيعات.
            ],

            'sales_rep' => [
                'name' => 'مندوب مبيعات',
                'permissions' => array_merge($core, [
                    self::WHOLESALE_INVOICE_VIEW, self::WHOLESALE_INVOICE_CREATE,
                    self::WHOLESALE_RETURN_VIEW, self::WHOLESALE_RETURN_REQUEST,
                    self::WHOLESALE_COLLECTION_RECORD,
                    self::REPORT_SALES,
                ]),
                // **والمندوبُ يُحصّل فعلاً** — هو من يذهب إلى العميل. لكنّه
                // **لا يُبطل** ولا يُغيّر سعراً ولا حدَّ آجل.
            ],

            'collector' => [
                'name' => 'محصّل',
                'permissions' => array_merge($core, [
                    self::WHOLESALE_INVOICE_VIEW,
                    self::WHOLESALE_COLLECTION_VIEW, self::WHOLESALE_COLLECTION_RECORD,
                    self::WHOLESALE_REPORT_VIEW,
                ]),
                // ولا إنشاءَ فاتورةٍ ولا إبطالَها.
            ],

            'accountant' => [
                'name' => 'محاسب',
                'permissions' => array_merge($core, [
                    self::WHOLESALE_INVOICE_VIEW, self::WHOLESALE_INVOICE_VOID,
                    self::WHOLESALE_RETURN_VIEW, self::WHOLESALE_RETURN_APPROVE,
                    self::WHOLESALE_COLLECTION_VIEW,
                    self::WHOLESALE_REPORT_VIEW,
                    self::CASH_COUNT, self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW, self::SETTLEMENT_REQUEST,
                    self::LEDGER_VIEW, self::ADJUSTMENT_REQUEST,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                ]),
                // **والإبطالُ عنده وحدَه دون البيع والتحصيل** — فمن أنشأ
                // الفاتورةَ لا يمحوها، ومن قبض لا يمحو ما قبضه.
            ],

            'warehouse_staff' => [
                'name' => 'موظف مستودع',
                'permissions' => array_merge($core, [
                    self::WHOLESALE_PRODUCT_MANAGE,
                ]),
                // **ولا `stock.adjust`**: التعديلُ المباشر بابٌ يلتفّ على
                // الفواتير كلِّها — يُنقص المخزونَ بلا بيعٍ فلا يظهر عجز.
            ],

            'custom' => [
                'name' => 'دور مخصَّص',
                'permissions' => [],
            ],
        ];
    }

    /**
     * **ولا `approval` في القوالب الجديدة — وهذا قرارٌ مقيس.**
     *
     * كُتب أوّلاً `WHOLESALE_INVOICE_VOID => ['approval' => 'manager']`
     * قصدَ فصلِ المُعِدّ عن المعتمِد. **ثمّ جُرّب بالعكس فسقط المقياس على
     * الوجه الآخر:** المحاسبُ يملك الإبطالَ **ولا يستطيعه أبداً**.
     *
     * والسببُ أنّ `assert()` تعُدّ «يحتاج اعتماداً» رفضاً — بنصّها:
     * «والاعتمادُ المطلوبُ رفضٌ هنا؛ ومن أراد مسارَ الاعتماد ينادي
     * `evaluate` ويبني الطلب». **وقِيس فلا مُنادي لـ`evaluate` في المشروع
     * كلِّه.** فالعمودُ يُكتب ولا يقرؤه أحد.
     *
     * فمنحةٌ باعتمادٍ اليوم = **منحةٌ ميّتة**: تُعرَض في شاشة الأدوار،
     * وتُسنَد، ولا تفتح باباً. وهو «مبنيٌّ ولا يُوصَل إليه» في صورةٍ أخبث
     * — لأنّ الشاشةَ تقول إنّه ممنوح.
     *
     * **ولا يُشحَن ميّتٌ جديد.** والحدودُ تبقى فهي تعمل، ويحرس الفراغَ
     * `no_seeded_grant_requires_an_approval_flow_that_does_not_exist`.
     *
     * @return array<string,array<string,array{scope:string,limit?:string,approval?:string}>>
     */
    public static function wholesaleSeedScopes(): array
    {
        return [
            'sales_rep' => [
                // **حدُّ الفاتورة الواحدة**، ومبلغٌ لا نسبة — للسبب نفسِه
                // المكتوب في `retailSeedScopes`.
                self::WHOLESALE_INVOICE_CREATE => ['scope' => 'own', 'limit' => '2000000'],
                self::WHOLESALE_COLLECTION_RECORD => ['scope' => 'own', 'limit' => '1000000'],
            ],
            'collector' => [
                self::WHOLESALE_COLLECTION_RECORD => ['scope' => 'merchant', 'limit' => '5000000'],
            ],
            'accountant' => [
                // **والإبطالُ للمحاسب وحدَه دون البيع والتحصيل** — وهو
                // الفصلُ المتاح اليوم بلا مسار اعتماد.
                self::WHOLESALE_INVOICE_VOID => ['scope' => 'merchant'],
            ],
        ];
    }

    /**
     * **والمطعمُ يفترق عن الجميع في أنّ الطلبَ يمرّ بأيدٍ ثلاث.**
     *
     * النادلُ يفتح ويُعدّل، والمطبخُ يُغيّر الحالةَ ولا يمسّ الأصناف،
     * والكاشيرُ يُغلق ويقبض. **وطاهٍ يُغلق طلباً يُثبت مبلغاً لم يُقبَض.**
     *
     * @return array<string,array{name:string,permissions:array<int,string>}>
     */
    public static function restaurantSeedRoles(): array
    {
        $core = [self::RESTAURANT_TABLE_VIEW];

        return [
            'owner' => [
                'name' => 'مالك المطعم',
                'permissions' => self::all(),
            ],

            'restaurant_manager' => [
                'name' => 'مدير المطعم',
                'permissions' => array_merge($core, [
                    self::STAFF_VIEW, self::STAFF_MANAGE, self::ROLE_VIEW,
                    self::RESTAURANT_TABLE_MANAGE,
                    self::RESTAURANT_ORDER_VIEW_ALL, self::RESTAURANT_ORDER_OPEN,
                    self::RESTAURANT_ORDER_UPDATE, self::RESTAURANT_ORDER_STATUS,
                    self::RESTAURANT_ORDER_CLOSE,
                    self::RESTAURANT_KITCHEN_VIEW,
                    self::CASH_COUNT, self::CASH_MOVE,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE, self::SHIFT_APPROVE, self::SHIFT_VIEW_ALL,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::SETTLEMENT_VIEW,
                    self::AUDIT_VIEW,
                ]),
            ],

            'waiter' => [
                'name' => 'نادل',
                'permissions' => array_merge($core, [
                    self::RESTAURANT_ORDER_OPEN, self::RESTAURANT_ORDER_UPDATE,
                    self::RESTAURANT_ORDER_VIEW_ALL,
                ]),
                // **ولا إغلاق** — الإغلاقُ قبضٌ، وهو للكاشير.
            ],

            'kitchen_staff' => [
                'name' => 'موظف مطبخ',
                'permissions' => [
                    self::RESTAURANT_KITCHEN_VIEW,
                    self::RESTAURANT_ORDER_STATUS,
                    self::RESTAURANT_ORDER_VIEW_ALL,
                ],
                // **ولا طاولاتٍ ولا أصنافَ طلبٍ ولا مال** — يرى ما يُطبخ
                // ويقول «جهز».
            ],

            'cashier' => [
                'name' => 'كاشير المطعم',
                'permissions' => array_merge($core, [
                    self::RESTAURANT_ORDER_VIEW_ALL, self::RESTAURANT_ORDER_CLOSE,
                    self::CASH_COUNT,
                    self::SHIFT_OPEN, self::SHIFT_CLOSE,
                ]),
            ],

            'accountant' => [
                'name' => 'محاسب',
                'permissions' => array_merge($core, [
                    self::RESTAURANT_ORDER_VIEW_ALL,
                    self::CASH_COUNT, self::SHIFT_VIEW_ALL,
                    self::SETTLEMENT_VIEW, self::SETTLEMENT_REQUEST,
                    self::LEDGER_VIEW, self::ADJUSTMENT_REQUEST,
                    self::REPORT_SALES, self::REPORT_FINANCIAL, self::REPORT_STAFF,
                    self::AUDIT_VIEW,
                ]),
            ],

            'custom' => [
                'name' => 'دور مخصَّص',
                'permissions' => [],
            ],
        ];
    }

    /** @return array<string,array<string,array{scope:string,limit?:string,approval?:string}>> */
    public static function restaurantSeedScopes(): array
    {
        return [
            'waiter' => [
                self::RESTAURANT_ORDER_UPDATE => ['scope' => 'own'],
            ],
            'cashier' => [
                self::SHIFT_CLOSE => ['scope' => 'own'],
            ],
        ];
    }
}
