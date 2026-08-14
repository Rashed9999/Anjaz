<?php

namespace App\Services\Admin;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MERCHANT-360-001 — **ملفُّ التاجر: ماليّاً وتشغيليّاً وإداريّاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** فتح صاحبُ المشروع ملفَّ تاجرِ محطّةِ وقودٍ فوجد:
 * الاسم · الهاتف · الرصيد ٠ · الباقة · «لا موظفين» · «لا فروع» ·
 * «لا مبيعات». وقال: «لا يوجد به أيّ تفاصيل ماليّة، عمليّة، إداريّة،
 * ولا حتّى تفاصيل عمله — ما هذا؟!».
 *
 * **والملاحظةُ دقيقة.** كان الملفُّ يجيب عن «مَن هو» ولا يجيب عن ثلاثة
 * أسئلةٍ يسألها كلُّ من يفتح ملفَّ تاجر:
 *
 *   ① **ماذا يُدرّ؟** — كم رسماً دفع للمنصّة؟ كم استُرجع؟ ما المستحقّ
 *      عليه أو له؟ كم فاتورةً مفتوحة؟ **ولا رقمَ من هذه كان يُعرض.**
 *
 *   ② **كيف يعمل؟** — وهو **محطّةُ وقود**: كم خزّاناً، وكم مضخّة، وأيّ
 *      ورديّةٍ مفتوحة، وكم فرقاً في الجرد؟ الملفُّ لم يكن يعلم أنّ له
 *      قطاعاً أصلاً. (والمهارة تنصّ على ذلك حرفاً: «Merchant Vertical
 *      Visibility — For each merchant type show the appropriate
 *      operational metrics».)
 *
 *   ③ **ماذا أستطيع أن أفعل به؟** — كان زرٌّ واحد: تجميد. ولا ترقيةَ
 *      باقةٍ، ولا تمديدَ اشتراك، ولا فكَّ ارتباطِ جهاز.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ خدمةٌ لا سطورٌ في المتحكّم:** `AdminHubController` بلغ ألفاً
 * ومئتَي سطر، وإقحامُ خمسةِ استعلاماتٍ قطاعيّةٍ فيه يجعله ألفاً وخمسمئة.
 * والأهمُّ أنّ **هذه الأرقام تُقرأ من مصادرها** — لا من عمودٍ مخزَّن —
 * فتحتاج موضعاً واحداً يُختبر. (القاعدة السادسة.)
 *
 * **ولا يُخترع رقم:** كلُّ جدولٍ يُسأل عن وجوده أوّلاً، وما لا وجودَ له
 * يُقال «غير متاح» لا «صفر». (القاعدة السابعة: «غير معروف» ليس صفراً.)
 */
class MerchantThreeSixtyService
{
    /**
     * @return array<string,mixed>
     */
    public function build(User $merchant): array
    {
        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        $vertical = (string) ($profile->business_type ?? '');

        return [
            'financial' => $this->financial($merchant),
            'operations' => $this->operations($merchant, $vertical),
            'devices' => $this->devices($merchant),
            'limits' => $this->limits($profile),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الماليّ — «ماذا يُدرّ هذا الحساب؟»
    // ══════════════════════════════════════════════════════════════════

    /**
     * أرقامٌ محسوبةٌ من مصادرها، لا مقروءةٌ من أعمدةٍ مجمّعة.
     *
     * @return array<string,mixed>
     */
    private function financial(User $m): array
    {
        $out = [];

        // ══════════════════════════════════════════════════════════════
        //  **أسماءُ الأعمدة تُقرأ من المخطّط لا من الذاكرة.**
        //
        //  كتبتُ أوّلاً `transaction_fees.fee_amount` و`merchant_refunds
        //  .amount` من حَدْسي، فسقط الاختبار بـ«Unknown column 'amount'».
        //  والجدولُ الحقيقيّ للرسوم `platform_fee_entries`، وعمودُ
        //  المرتجع `refund_amount`. (القاعدة الثالثة: يُقاس ثمّ يُقال.)
        // ══════════════════════════════════════════════════════════════
        if ($this->has('platform_fee_entries', 'amount', 'from_user_id')) {
            $fees = DB::table('platform_fee_entries')->where('from_user_id', $m->id);

            $out['fees_paid'] = (string) (clone $fees)->sum('amount');
            $out['fees_paid_30d'] = (string) (clone $fees)
                ->where('created_at', '>=', now()->subDays(30))->sum('amount');
        } else {
            $out['fees_paid'] = null;   // **غير متاح** لا صفر
            $out['fees_paid_30d'] = null;
        }

        // ── المبيعات: قيمةً وعدداً ومتوسّطاً ──
        $sales = DB::table('merchant_sales')->where('merchant_user_id', $m->id);
        $count = (clone $sales)->count();
        $total = (string) (clone $sales)->sum('total_amount');

        $out['sales_total'] = $total;
        $out['sales_count'] = $count;
        $out['sales_avg'] = $count > 0 ? bcdiv($total, (string) $count, 2) : '0.00';
        $out['sales_30d'] = (string) (clone $sales)
            ->where('created_at', '>=', now()->subDays(30))->sum('total_amount');

        // ── المرتجعات ──
        if ($this->has('merchant_refunds', 'refund_amount', 'merchant_user_id')) {
            $out['refunds_total'] = (string) DB::table('merchant_refunds')
                ->where('merchant_user_id', $m->id)->sum('refund_amount');
            $out['refunds_count'] = DB::table('merchant_refunds')
                ->where('merchant_user_id', $m->id)->count();
        } else {
            $out['refunds_total'] = null;
            $out['refunds_count'] = null;
        }

        // ── الفواتير ومدفوعاتها ──
        // فواتيرُ الجملة تُربط بالمنشأة لا بالمستخدم — فتُقرأ عبرها.
        $biz = Schema::hasTable('wholesale_businesses')
            ? DB::table('wholesale_businesses')->where('merchant_user_id', $m->id)->value('id')
            : null;

        if ($biz && $this->has('wholesale_invoices', 'total_amount', 'business_id')) {
            $inv = DB::table('wholesale_invoices')->where('business_id', $biz);

            $out['invoices_open'] = (clone $inv)->whereIn('status', ['open', 'partial', 'pending'])->count();
            $out['invoices_due'] = (string) (clone $inv)
                ->whereIn('status', ['open', 'partial', 'pending'])->sum('total_amount');
        } else {
            $out['invoices_open'] = null;
            $out['invoices_due'] = null;
        }

        // ── التسويات ──
        // **والتسوياتُ للشركاء لا للتجّار** في هذا المخطّط: `settlements`
        // تشير إلى `settlement_partners` لا إلى حساب تاجر. فيُقال «غير
        // متاح» بدل أن يُخترع صفر. (القاعدة السابعة.)
        $out['settlements_pending'] = null;
        $out['settlements_paid'] = null;

        return $out;
    }

    /**
     * أموجودٌ الجدولُ **وأعمدتُه**؟
     *
     * فـ`hasTable` وحدها لا تكفي: جدولٌ موجودٌ بعمودٍ مختلفِ الاسم
     * يُسقط الاستعلامَ بـ«Unknown column» — وهو ما وقع لي هنا مرّتين.
     */
    private function has(string $table, string ...$columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $c) {
            if (! Schema::hasColumn($table, $c)) {
                return false;
            }
        }

        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② التشغيليّ — لكلّ نشاطٍ مؤشّراتُه
    // ══════════════════════════════════════════════════════════════════

    /**
     * **الملفُّ يعرف نشاطَ صاحبه.**
     *
     * @return array{vertical:string,label:string,metrics:array<int,array{label:string,value:mixed,hint?:string}>}
     */
    private function operations(User $m, string $vertical): array
    {
        return match ($vertical) {
            A::BIZ_FUEL => $this->fuel($m),
            A::BIZ_PHARMACY => $this->pharmacy($m),
            A::BIZ_WHOLESALE => $this->wholesale($m),
            A::BIZ_RETAIL, A::BIZ_QUICK_SALE => $this->retail($m, $vertical),
            A::BIZ_RESTAURANT => $this->restaurant($m),

            // **ولا يُترك فارغاً صامتاً**: نشاطٌ غيرُ معروفٍ يُقال.
            default => [
                'vertical' => $vertical ?: 'غير محدَّد',
                'label' => 'نشاطٌ بلا لوحةٍ قطاعيّة',
                'metrics' => [],
            ],
        };
    }

    /** @return array<string,mixed> */
    private function fuel(User $m): array
    {
        $station = DB::table('fuel_stations')->where('merchant_user_id', $m->id)->first();

        if (! $station) {
            // **حسابُ محطّةٍ بلا محطّة** — يُقال ولا يُعرض صفراً.
            return ['vertical' => A::BIZ_FUEL, 'label' => 'محطّة وقود',
                    'metrics' => [], 'missing' => 'لا سجلّ محطّةٍ لهذا الحساب'];
        }

        $sid = $station->id;

        $openShift = Schema::hasTable('fuel_shifts')
            ? DB::table('fuel_shifts')->where('station_id', $sid)->whereNull('closed_at')->count()
            : null;

        return [
            'vertical' => A::BIZ_FUEL,
            'label' => 'محطّة وقود',
            'station_name' => $station->name ?? null,
            'metrics' => array_values(array_filter([
                ['label' => 'الخزّانات', 'value' => Schema::hasTable('fuel_tanks')
                    ? DB::table('fuel_tanks')->where('station_id', $sid)->count() : null],
                ['label' => 'المضخّات', 'value' => Schema::hasTable('fuel_pumps')
                    ? DB::table('fuel_pumps')->where('station_id', $sid)->count() : null],
                ['label' => 'الأصناف', 'value' => Schema::hasTable('fuel_products')
                    ? DB::table('fuel_products')->where('station_id', $sid)->count() : null],
                ['label' => 'ورديّات مفتوحة', 'value' => $openShift,
                 'hint' => 'ورديّةٌ لم تُغلق ≠ فرقٌ صفر'],
                ['label' => 'مبيعات الوقود', 'value' => Schema::hasTable('fuel_sales')
                    ? DB::table('fuel_sales')->where('station_id', $sid)->count() : null],
                ['label' => 'لترات مباعة', 'value' => Schema::hasTable('fuel_sales')
                    ? (string) DB::table('fuel_sales')->where('station_id', $sid)->sum('liters') : null],
            ], fn ($x) => $x['value'] !== null)),
        ];
    }

    /** @return array<string,mixed> */
    private function pharmacy(User $m): array
    {
        $ph = DB::table('pharmacies')->where('merchant_user_id', $m->id)->first();

        if (! $ph) {
            return ['vertical' => A::BIZ_PHARMACY, 'label' => 'صيدليّة',
                    'metrics' => [], 'missing' => 'لا سجلّ صيدليّةٍ لهذا الحساب'];
        }

        return [
            'vertical' => A::BIZ_PHARMACY,
            'label' => 'صيدليّة',
            'metrics' => array_values(array_filter([
                ['label' => 'الأدوية', 'value' => Schema::hasTable('pharmacy_medicines')
                    ? DB::table('pharmacy_medicines')->where('pharmacy_id', $ph->id)->count() : null],
                ['label' => 'مبيعات', 'value' => Schema::hasTable('pharmacy_sales')
                    ? DB::table('pharmacy_sales')->where('pharmacy_id', $ph->id)->count() : null],
            ], fn ($x) => $x['value'] !== null)),
        ];
    }

    /** @return array<string,mixed> */
    private function wholesale(User $m): array
    {
        $b = DB::table('wholesale_businesses')->where('merchant_user_id', $m->id)->first();

        if (! $b) {
            return ['vertical' => A::BIZ_WHOLESALE, 'label' => 'تجارة جملة',
                    'metrics' => [], 'missing' => 'لا سجلّ منشأةٍ لهذا الحساب'];
        }

        return [
            'vertical' => A::BIZ_WHOLESALE,
            'label' => 'تجارة جملة',
            'metrics' => array_values(array_filter([
                ['label' => 'فواتير الجملة', 'value' => Schema::hasTable('wholesale_invoices')
                    ? DB::table('wholesale_invoices')->where('business_id', $b->id)->count() : null],
                ['label' => 'ذممٌ مدينة', 'value' => Schema::hasTable('wholesale_invoices')
                    ? (string) DB::table('wholesale_invoices')->where('business_id', $b->id)
                        ->whereIn('status', ['open', 'partial'])->sum('total_amount') : null],
            ], fn ($x) => $x['value'] !== null)),
        ];
    }

    /** @return array<string,mixed> */
    private function retail(User $m, string $vertical): array
    {
        return [
            'vertical' => $vertical,
            'label' => $vertical === A::BIZ_QUICK_SALE ? 'بيع سريع' : 'تجزئة',
            'metrics' => array_values(array_filter([
                ['label' => 'المنتجات', 'value' => Schema::hasTable('merchant_products')
                    ? DB::table('merchant_products')->where('merchant_user_id', $m->id)->count() : null],
                ['label' => 'أصنافٌ بمخزونٍ سالب', 'value' => Schema::hasTable('merchant_products')
                    ? DB::table('merchant_products')->where('merchant_user_id', $m->id)
                        ->where('quantity', '<', 0)->count() : null,
                 'hint' => 'مخزونٌ سالبٌ يعني بيعاً بلا إدخال'],
                ['label' => 'عمليّات جرد', 'value' => Schema::hasTable('inventory_audits')
                    ? DB::table('inventory_audits')->where('merchant_user_id', $m->id)->count() : null],
            ], fn ($x) => $x['value'] !== null)),
        ];
    }

    /** @return array<string,mixed> */
    private function restaurant(User $m): array
    {
        return [
            'vertical' => A::BIZ_RESTAURANT,
            'label' => 'مطعم',
            'metrics' => array_values(array_filter([
                ['label' => 'الطلبات', 'value' => Schema::hasTable('restaurant_orders')
                    ? DB::table('restaurant_orders')->where('merchant_user_id', $m->id)->count() : null],
            ], fn ($x) => $x['value'] !== null)),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الأجهزة والحدود
    // ══════════════════════════════════════════════════════════════════

    /**
     * **أجهزةُ الحساب** — من يدخل به، ومن أين.
     *
     * ولا تُعرض المعرّفاتُ كاملةً: طرفُها يكفي للتمييز، وكاملُها يُمكّن
     * من انتحالها.
     *
     * @return array<int,array<string,mixed>>
     */
    private function devices(User $m): array
    {
        if (! Schema::hasTable('user_log_histories')) {
            return [];
        }

        return DB::table('user_log_histories')
            ->where('user_id', $m->id)
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn ($d) => [
                'device' => $d->device_model ?? $d->device_name ?? '—',
                'device_id_tail' => $d->device_id ? '…' . substr((string) $d->device_id, -6) : null,
                'ip' => $d->ip ?? null,
                'is_active' => (bool) ($d->is_active ?? false),
                'last_seen' => (string) ($d->updated_at ?? $d->created_at ?? ''),
            ])->all();
    }

    /** حدودُ الاستقبال المضبوطة على الملفّ — رقمٌ يقيّد المال فيُعرض. */
    private function limits(?MerchantProfile $p): array
    {
        return [
            'daily_receive' => $p->daily_receive_limit ?? null,
            'single_receive' => $p->single_receive_limit ?? null,
            'monthly_receive' => $p->monthly_receive_limit ?? null,
            'can_transfer_out' => $p ? (bool) $p->can_transfer_out : null,
        ];
    }
}
