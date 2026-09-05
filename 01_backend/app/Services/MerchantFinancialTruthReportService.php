<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Models\CustomerCreditAccount;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\PharmacySale;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCollection;
use App\Models\WholesaleInvoice;
use Carbon\Carbon;

/**
 * عقد التقرير المالي التشغيلي الموحد للتاجر.
 *
 * لا يخلط هذا التقرير ما دخل المحفظة بما بيع نقداً أو آجلاً. كل رقم يحمل
 * مصدره ونوعه، لذلك لا تتحول «دفعة أميال» إلى إجمالي مبيعات ولا يختفي
 * الآجل لأنه لم يدخل المحفظة بعد.
 */
class MerchantFinancialTruthReportService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function report(User $merchant, ?string $from = null, ?string $to = null): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        if ($end->lt($start)) throw new \InvalidArgumentException('نهاية الفترة يجب أن تكون بعد بدايتها');
        $vertical = MerchantProfile::where('user_id', $merchant->id)->value('business_type') ?: 'retail';

        [$sales, $source] = $this->salesFor($merchant, $vertical, $start, $end);
        // AMIAL-MONEY-SHAPE-001 — **صفرٌ بصيغتين في تقريرٍ واحد.**
        //
        // كانت الأصفارُ الابتدائيّةُ `'0'` خاماً، **فالبندُ الذي وصله بيعٌ
        // يخرج `'400.0000'` والذي لم يصله يخرج `'0'`** — رقمان ماليّان في
        // الحقل نفسِه بصيغتين، والفرقُ يتبع البياناتِ لا العقد.
        // فمن قرأ الحقلَ بمقارنةٍ نصّيّةٍ أو نسّقه بأربع خاناتٍ انكسر عليه
        // أحدُهما. (`amial-financial-truth`: صيغةُ الرقم جزءٌ من عقده.)
        $zero = MoneyService::normalize('0');
        $methods = ['cash' => $zero, 'amial_pay' => $zero, 'credit' => $zero, 'other' => $zero];
        $gross = '0'; $count = 0;
        foreach ($sales as $sale) {
            $amount = MoneyService::normalize((string) ($sale->amount ?? '0'));
            // البيع المختلط ليس «طريقة رابعة»: جزؤه النقدي في الدرج وجزؤه
            // الإلكتروني في محفظة أميال. وضع الإجمالي في other يُخفي المال
            // عن المطابقة ويجعل تقرير اليوم صحيح المجموع وخاطئ المعنى.
            if (($sale->method ?? null) === 'mixed') {
                $cash = MoneyService::normalize((string) ($sale->cash_amount ?? '0'));
                $walletPart = MoneyService::normalize((string) ($sale->wallet_amount ?? '0'));
                $methods['cash'] = MoneyService::add($methods['cash'], $cash);
                $methods['amial_pay'] = MoneyService::add($methods['amial_pay'], $walletPart);
                $gross = MoneyService::add($gross, $amount);
                $count++;
                continue;
            }
            $method = $this->normalizeMethod((string) ($sale->method ?? ''));
            $methods[$method] = MoneyService::add($methods[$method], $amount);
            $gross = MoneyService::add($gross, $amount);
            $count++;
        }

        $collections = $vertical === 'wholesale'
            ? $this->wholesaleCollections($merchant, $start, $end)
            : ['cash' => $zero, 'amial_pay' => $zero, 'other' => $zero, 'count' => 0];

        $wallet = $this->walletTruth($merchant, $start, $end);
        return [
            'contract_version' => 'merchant-financial-truth/v1',
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'vertical' => $vertical,
            'currency' => 'YER',
            'sales' => [
                'gross' => $gross, 'count' => $count,
                'by_payment_method' => $methods,
                'source' => $source,
            ],
            // التحصيلات تُعرض مستقلة: قد تخص دين فاتورة قديمة، فلا تضاف إلى بيع اليوم.
            'collections' => $collections,
            'wallet' => $wallet,
            'receivables' => $this->receivables($merchant, $vertical),
            // AMIAL-DAILY-MOVEMENT-001 — انظر `movement()` أدناه.
            'movement' => $this->movement(
                $merchant, $vertical, $start, $end, $methods, $count, $source),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-DAILY-MOVEMENT-001 — الحركةُ اليوميّة الكاملة
    // ══════════════════════════════════════════════════════════════════

    /**
     * **أربعُ حركاتٍ في اتّجاهين، وكلُّ خانةٍ من مصدرها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **من أين جاءت:** أرسل صاحبُ المشروع شاشاتِ تطبيقٍ محاسبيٍّ منافس،
     * وفي صدرها مصفوفةُ يومٍ واحدة: **مبيعٌ وشراءٌ ومرتجعاهما، نقداً
     * وآجلاً**. واختارها من بين خمسِ أفكارٍ عُرضت عليه.
     *
     * **ولا يُبنى مصدرٌ ثانٍ للحقيقة.** هذه الدالّةُ داخلَ خدمة الحقيقة
     * الماليّة نفسِها، **وصفُّ «المبيع» يُمرَّر إليها محسوباً** من الحلقة
     * أعلاه — لا يُعاد حسابُه باستعلامٍ ثانٍ يمكن أن يختلف. (مهارةُ
     * `amial-admin-command`: «The Admin Center must never become a second
     * source of financial truth»، والقاعدةُ السادسة.)
     *
     * **وثلاثةُ حدودٍ تحكم كلَّ خانة:**
     *
     *   ① **الغيابُ يُقال ولا يُصفَّر** (القاعدة السابعة). صفٌّ لا مصدرَ
     *      له في هذا القطاع يخرج `available=false` **وقيمُه `null` لا
     *      أصفار**: صفرٌ في «مرتجع الشراء» يُقرأ «لم يُرتجَع اليوم»،
     *      والحقيقةُ «لا بابَ للردّ في هذا القطاع أصلاً».
     *
     *   ② **ولا يُجمع صافٍ عبر أعمدةٍ مختلفةِ المعنى.** بيعٌ آجلٌ ليس
     *      نقداً في الدرج، ودفعةُ محفظةٍ ليست ورقةً تُعدّ. فالصافي
     *      **نقديٌّ وحدَه** ويُسمّى باسمه: ما دخل الدرجَ ناقصَ ما خرج
     *      منه. والآجلُ يبقى ذمّةً في `receivables`.
     *
     *   ③ **والشراءُ لا يُقرأ من أمر الشراء بل من الاستلام.** أمرٌ
     *      مُعتمَدٌ لم تصل بضاعتُه ليس شراءً وقع — وعدّه يجعل مشترياتِ
     *      اليومِ أكبرَ ممّا دخل المخزن. فالمصدرُ `supplier_ledger`
     *      عند `po_receive`.
     *
     * @param  array<string,string>  $saleMethods  صفُّ المبيع كما حُسب أعلاه
     */
    private function movement(
        User $merchant, string $vertical, Carbon $start, Carbon $end,
        array $saleMethods, int $saleCount, string $saleSource,
    ): array {
        $rows = [
            $this->row('sale', 'المبيع', 'in', [
                'cash' => $saleMethods['cash'], 'amial_pay' => $saleMethods['amial_pay'],
                'credit' => $saleMethods['credit'],
            ], $saleCount, $saleSource),

            $this->saleReturnRow($merchant, $vertical, $start, $end),
            $this->purchaseRow($merchant, $vertical, $start, $end),
            $this->purchaseReturnRow($merchant, $vertical, $start, $end),
        ];

        // ② **الصافي النقديُّ وحدَه** — ولا يُحتسب من صفٍّ غائب.
        $netCash = '0';
        foreach ($rows as $r) {
            if ($r['available'] !== true) {
                continue;
            }
            $netCash = $r['direction'] === 'in'
                ? MoneyService::add($netCash, $r['cash'])
                : MoneyService::sub($netCash, $r['cash']);
        }

        return [
            'contract' => 'daily-movement/v1',
            'columns' => ['cash', 'amial_pay', 'credit'],
            'column_labels_ar' => [
                'cash' => 'نقدي', 'amial_pay' => 'أميال باي', 'credit' => 'آجل',
            ],
            'rows' => $rows,
            'net_cash' => [
                'label_ar' => 'صافي النقد من حركة اليوم',
                'amount' => $netCash,
                'note_ar' => 'نقدُ الدرج وحدَه: لا يشمل الآجل ولا حركةَ المحفظة. '
                    . 'والآجلُ يبقى ذمّةً في «ذمم العملاء».',
            ],
        ];
    }

    /** @param array<string,string|null> $cells */
    private function row(string $code, string $label, string $direction,
        array $cells, int $count, string $source): array
    {
        $total = MoneyService::add(
            MoneyService::add($cells['cash'] ?? '0', $cells['amial_pay'] ?? '0'),
            $cells['credit'] ?? '0');

        return [
            'code' => $code, 'label_ar' => $label, 'direction' => $direction,
            'available' => true,
            'cash' => $cells['cash'] ?? MoneyService::normalize('0'),
            'amial_pay' => $cells['amial_pay'] ?? MoneyService::normalize('0'),
            'credit' => $cells['credit'] ?? MoneyService::normalize('0'),
            'total' => $total, 'count' => $count, 'source' => $source,
        ];
    }

    /** ① صفٌّ لا مصدرَ له — **بلا أرقامٍ إطلاقاً، ومعه سببُه.** */
    private function absent(string $code, string $label, string $direction, string $why): array
    {
        return [
            'code' => $code, 'label_ar' => $label, 'direction' => $direction,
            'available' => false, 'unavailable_reason_ar' => $why,
            'cash' => null, 'amial_pay' => null, 'credit' => null,
            'total' => null, 'count' => null, 'source' => null,
        ];
    }

    /**
     * **مرتجعُ المبيع يُقرأ من المال لا من البضاعة.**
     *
     * في التجزئة جدولان متلازمان: `sale_returns` تملك **البضاعةَ
     * وأسطرَها**، و`merchant_refunds` تملك **المال**. وقراءةُ الأوّل هنا
     * تجعل مرتجعاً مقبولَ البضاعةِ مرفوضَ الصرف يظهر مالاً خرج — وهو لم
     * يخرج. فالمصدرُ هو `merchant_refunds` عند `completed`.
     */
    private function saleReturnRow(User $merchant, string $vertical, Carbon $start, Carbon $end): array
    {
        $z = MoneyService::normalize('0');

        if ($vertical === 'wholesale') {
            $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
            if (! $businessId) {
                return $this->row('sale_return', 'مرتجع المبيع', 'out',
                    ['cash' => $z, 'amial_pay' => $z, 'credit' => $z], 0, 'wholesale_returns');
            }

            $rows = \App\Models\WholesaleReturn::where('business_id', $businessId)
                ->where('status', 'approved')
                ->whereBetween('updated_at', [$start, $end])
                ->get(['credited_amount', 'refund_due_amount']);

            $credit = '0'; $cash = '0';
            foreach ($rows as $r) {
                // **إشعارُ الخصم يُنقص الذمّة، والاستردادُ يخرج نقداً** —
                // وهما في الصفّ نفسِه بعمودين مختلفين لا بمجموعٍ واحد.
                $credit = MoneyService::add($credit, (string) ($r->credited_amount ?: '0'));
                $cash = MoneyService::add($cash, (string) ($r->refund_due_amount ?: '0'));
            }

            return $this->row('sale_return', 'مرتجع المبيع', 'out',
                ['cash' => $cash, 'amial_pay' => $z, 'credit' => $credit],
                $rows->count(), 'wholesale_returns');
        }

        $refunds = \App\Models\MerchantRefund::where('merchant_user_id', $merchant->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->get(['refund_amount', 'refund_method']);

        $cells = ['cash' => $z, 'amial_pay' => $z, 'credit' => $z];
        foreach ($refunds as $r) {
            $key = match ((string) $r->refund_method) {
                'cash' => 'cash',
                'wallet' => 'amial_pay',
                default => 'credit',      // credit_account: يُخصَم من ذمّة العميل
            };
            $cells[$key] = MoneyService::add($cells[$key], (string) $r->refund_amount);
        }

        return $this->row('sale_return', 'مرتجع المبيع', 'out',
            $cells, $refunds->count(), 'merchant_refunds');
    }

    /** ③ الشراءُ من الاستلام — و`cash_amount` هو ما دُفع فوراً. */
    private function purchaseRow(User $merchant, string $vertical, Carbon $start, Carbon $end): array
    {
        if (! $this->keepsSuppliers($vertical)) {
            return $this->absent('purchase', 'الشراء', 'out',
                'لا مورّدين ولا أوامرَ شراءٍ في قطاع «' . $vertical . '» — والشراءُ لا يُسجَّل فيه أصلاً');
        }

        $rows = \App\Models\SupplierLedgerEntry::where('merchant_user_id', $merchant->id)
            ->where('entry_type', 'po_receive')
            ->whereBetween('created_at', [$start, $end])
            ->get(['amount', 'cash_amount']);

        $cash = '0'; $credit = '0';
        foreach ($rows as $r) {
            $amount = (string) $r->amount;
            // **و`null` تعني «استلامٌ قبل أن يوجد هذا العمود» لا «دُفع
            // صفر»** — وكان الاستلامُ يومَها آجلاً كلَّه فعلاً.
            $paid = $r->cash_amount === null ? '0' : (string) $r->cash_amount;
            $cash = MoneyService::add($cash, $paid);
            $credit = MoneyService::add($credit, MoneyService::sub($amount, $paid));
        }

        return $this->row('purchase', 'الشراء', 'out', [
            'cash' => $cash, 'amial_pay' => MoneyService::normalize('0'), 'credit' => $credit,
        ], $rows->count(), 'supplier_ledger.po_receive');
    }

    private function purchaseReturnRow(User $merchant, string $vertical, Carbon $start, Carbon $end): array
    {
        if (! $this->keepsSuppliers($vertical)) {
            return $this->absent('purchase_return', 'مرتجع الشراء', 'in',
                'لا مورّدين في قطاع «' . $vertical . '» — فلا بضاعةَ تُردّ إليهم');
        }

        $rows = \App\Models\PurchaseReturn::where('merchant_user_id', $merchant->id)
            ->where('status', 'approved')
            ->whereBetween('approved_at', [$start, $end])
            ->get(['total_amount', 'settlement_type']);

        $z = MoneyService::normalize('0');
        $cells = ['cash' => $z, 'amial_pay' => $z, 'credit' => $z];
        foreach ($rows as $r) {
            $key = $r->settlement_type === \App\Models\PurchaseReturn::SETTLE_CASH_REFUND
                ? 'cash' : 'credit';
            $cells[$key] = MoneyService::add($cells[$key], (string) $r->total_amount);
        }

        return $this->row('purchase_return', 'مرتجع الشراء', 'in',
            $cells, $rows->count(), 'purchase_returns');
    }

    /**
     * **من يشتري بضاعةً أصلاً** — ويُقرأ من سجلّ القدرات لا من قائمةٍ
     * مكتوبةٍ هنا. قائمةٌ ثانيةٌ تشيخ: يُفتح الشراءُ لقطاعٍ في السجلّ
     * فيبقى صفُّه غائباً في التقرير أبداً، ولا خطأ في أيّ سجلّ.
     */
    private function keepsSuppliers(string $vertical): bool
    {
        return \App\Support\Access\CapabilityRegistry::find(
            \App\Support\Access\AccessConstants::F_PURCHASES)?->appliesTo($vertical) === true;
    }

    private function salesFor(User $merchant, string $vertical, Carbon $start, Carbon $end): array
    {
        if ($vertical === 'wholesale') {
            $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
            if (!$businessId) return [collect(), 'wholesale_invoices'];
            return [WholesaleInvoice::where('business_id', $businessId)->where('status', '!=', 'voided')
                ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
                ->get(['total_amount as amount', 'payment_type as method']), 'wholesale_invoices'];
        }
        if ($vertical === 'fuel') {
            return [FuelSale::where('merchant_user_id', $merchant->id)->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])->get(['total_amount as amount', 'payment_method as method']), 'fuel_sales'];
        }
        if ($vertical === 'pharmacy') {
            return [PharmacySale::where('merchant_user_id', $merchant->id)->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])->get(['total_amount as amount', 'payment_method as method']), 'pharmacy_sales'];
        }
        return [MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['completed', 'credit_unpaid', 'credit_paid'])
            ->whereBetween('created_at', [$start, $end])
            ->get(['total_amount as amount', 'payment_method as method', 'cash_amount', 'wallet_amount']), 'merchant_sales'];
    }

    private function wholesaleCollections(User $merchant, Carbon $start, Carbon $end): array
    {
        $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
        // **والصيغةُ واحدةٌ هنا أيضاً** — انظر `AMIAL-MONEY-SHAPE-001`.
        $z = MoneyService::normalize('0');
        $result = ['cash' => $z, 'amial_pay' => $z, 'other' => $z, 'count' => 0];
        if (!$businessId) return $result;
        $rows = WholesaleCollection::where('business_id', $businessId)
            ->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])
            ->get(['amount', 'payment_method']);
        foreach ($rows as $row) {
            $method = $this->normalizeMethod((string) $row->payment_method);
            if (!array_key_exists($method, $result)) $method = 'other';
            $result[$method] = MoneyService::add($result[$method], (string) $row->amount);
            $result['count']++;
        }
        return $result;
    }

    private function receivables(User $merchant, string $vertical): array
    {
        // كل القطاعات الجديدة تنشر الآجل إلى دفتر واحد. لا تُجمع فواتير
        // الجملة فوقه، لأن الفاتورة نفسها موجودة كحركة في الحساب وسيكون
        // ذلك عدّاً مزدوجاً. يُفصل فقط إرث الجملة بلا رقم هاتف، إذ لا يمكن
        // نسبته إلى عميل في التطبيق بعد.
        $unified = (string) CustomerCreditAccount::where('merchant_user_id', $merchant->id)
            ->where('is_active', true)
            ->where('current_balance', '>', 0)
            ->sum('current_balance');

        $legacyWholesale = '0';
        if ($vertical === 'wholesale') {
            $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
            if ($businessId) {
                $legacyWholesale = (string) WholesaleInvoice::where('business_id', $businessId)
                    ->whereNotIn('status', ['voided', 'paid'])
                    ->whereHas('customer', fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
                    ->sum('balance_due');
            }
        }

        return [
            'known' => true,
            'amount' => MoneyService::add($unified ?: '0', $legacyWholesale ?: '0'),
            'source' => 'customer_credit_accounts.current_balance',
            'breakdown' => [
                'unified_customer_credits' => $unified ?: '0',
                'legacy_unlinked_wholesale' => $legacyWholesale ?: '0',
            ],
        ];
    }

    private function walletTruth(User $merchant, Carbon $start, Carbon $end): array
    {
        $wallet = $this->ledger->getOrCreateUserWallet($merchant->id);
        $credits = (string) LedgerEntryLine::where('account_id', $wallet->id)->where('direction', 'credit')
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $debits = (string) LedgerEntryLine::where('account_id', $wallet->id)->where('direction', 'debit')
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        return [
            'received' => $credits ?: '0', 'paid_out' => $debits ?: '0',
            'net_movement' => MoneyService::sub($credits ?: '0', $debits ?: '0'),
            'balance' => (string) $wallet->current_balance,
            'source' => 'ledger_entry_lines',
        ];
    }

    private function normalizeMethod(string $method): string
    {
        return match ($method) {
            'cash' => 'cash', 'amial_pay', 'customer_wallet' => 'amial_pay', 'credit', 'company_card', 'corporate' => 'credit',
            default => 'other',
        };
    }
}
