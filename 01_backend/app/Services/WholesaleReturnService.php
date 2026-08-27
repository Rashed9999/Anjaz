<?php

namespace App\Services;

use App\Models\User;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleInvoiceItem;
use App\Models\WholesaleProduct;
use App\Models\WholesaleReturn;
use App\Models\WholesaleReturnItem;
use App\Models\WholesaleSalesRep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * مرتجع الجملة ليس زر "إرجاع" على فاتورة:
 * طلب → قرار → إعادة مخزون + إشعار دائن، وأي مبلغ سبق قبضه يبقى
 * "مستحق رد" صريحاً إلى أن يُنفَّذ رد مالي حقيقي في تدفّق منفصل.
 */
class WholesaleReturnService
{
    public function request(User $actor, WholesaleInvoice $invoice, array $items, string $reason): WholesaleReturn
    {
        if ($invoice->status === 'voided') throw new RuntimeException('لا يمكن إرجاع فاتورة مُبطلة');
        if ($items === []) throw new InvalidArgumentException('اختر صنفاً واحداً على الأقل');

        return DB::transaction(function () use ($actor, $invoice, $items, $reason) {
            $inv = WholesaleInvoice::with('items')->lockForUpdate()->findOrFail($invoice->id);
            $invoiceItems = $inv->items->keyBy('id');
            $subtotal = '0';
            $resolved = [];
            $seenItemIds = [];

            foreach ($items as $raw) {
                $itemId = (int) ($raw['invoice_item_id'] ?? 0);
                $qty = MoneyService::normalize((string) ($raw['quantity'] ?? '0'));
                if (MoneyService::compare($qty, '0') <= 0 || !$invoiceItems->has($itemId) || isset($seenItemIds[$itemId])) {
                    throw new InvalidArgumentException('صنف أو كمية المرتجع غير صحيحة');
                }
                $seenItemIds[$itemId] = true;
                /** @var WholesaleInvoiceItem $line */
                $line = $invoiceItems->get($itemId);
                $alreadyRequested = (string) WholesaleReturnItem::query()
                    ->join('wholesale_returns', 'wholesale_returns.id', '=', 'wholesale_return_items.return_id')
                    ->where('wholesale_return_items.invoice_item_id', $line->id)
                    ->whereIn('wholesale_returns.status', ['requested', 'approved'])
                    ->sum('wholesale_return_items.quantity');
                $available = MoneyService::sub((string) $line->quantity, $alreadyRequested);
                if (MoneyService::compare($qty, $available) > 0) {
                    throw new InvalidArgumentException("كمية مرتجع {$line->product_name} تتجاوز المتاح للإرجاع");
                }
                $lineAmount = MoneyService::div(MoneyService::mul((string) $line->line_total, $qty), (string) $line->quantity);
                $factor = (string) ($line->unit_factor ?: '1');
                $baseQty = MoneyService::mul($qty, $factor);
                $resolved[] = compact('line', 'qty', 'baseQty', 'factor', 'lineAmount');
                $subtotal = MoneyService::add($subtotal, $lineAmount);
            }

            // خصم الفاتورة والضريبة يوزعان بنسبة الجزء المرتجع من مجموع البنود.
            $discount = MoneyService::compare((string) $inv->subtotal, '0') > 0
                ? MoneyService::div(MoneyService::mul($subtotal, (string) $inv->discount_amount), (string) $inv->subtotal)
                : '0';
            $afterDiscount = MoneyService::sub($subtotal, $discount);
            $tax = MoneyService::compare((string) $inv->tax_rate, '0') > 0
                ? MoneyService::div(MoneyService::mul($afterDiscount, (string) $inv->tax_rate), '100')
                : '0';
            $total = MoneyService::add($afterDiscount, $tax);

            $return = WholesaleReturn::create([
                'return_ulid' => (string) Str::ulid(),
                'business_id' => $inv->business_id,
                'invoice_id' => $inv->id,
                'customer_id' => $inv->customer_id,
                'requested_by_user_id' => $actor->id,
                'status' => 'requested',
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'reason' => $reason,
            ]);
            foreach ($resolved as $r) {
                /** @var WholesaleInvoiceItem $line */
                $line = $r['line'];
                WholesaleReturnItem::create([
                    'return_id' => $return->id, 'invoice_item_id' => $line->id,
                    'product_id' => $line->product_id, 'product_name' => $line->product_name,
                    'unit' => $line->unit, 'quantity' => $r['qty'],
                    'unit_factor' => $r['factor'], 'base_quantity' => $r['baseQty'],
                    'unit_price' => $line->unit_price, 'discount_per_unit' => $line->discount_per_unit,
                    'line_total' => $r['lineAmount'],
                ]);
            }
            return $return->fresh(['items', 'invoice']);
        });
    }

    public function resolve(User $reviewer, WholesaleReturn $return, bool $approve, ?string $note = null): WholesaleReturn
    {
        return DB::transaction(function () use ($reviewer, $return, $approve, $note) {
            $ret = WholesaleReturn::with('items')->lockForUpdate()->findOrFail($return->id);
            if ($ret->status !== 'requested') throw new RuntimeException('تم اتخاذ قرار لهذا الطلب مسبقاً');
            if (!$approve) {
                $ret->update([
                    'status' => 'rejected', 'reviewed_by_user_id' => $reviewer->id,
                    'decision_note' => $note, 'resolved_at' => now(),
                ]);
                return $ret->fresh(['items', 'invoice']);
            }

            $invoice = WholesaleInvoice::lockForUpdate()->findOrFail($ret->invoice_id);
            $customer = WholesaleCustomer::lockForUpdate()->findOrFail($ret->customer_id);
            $credited = MoneyService::compare((string) $invoice->balance_due, (string) $ret->total_amount) >= 0
                ? (string) $ret->total_amount : (string) $invoice->balance_due;
            $refundDue = MoneyService::sub((string) $ret->total_amount, $credited);
            foreach ($ret->items as $item) {
                if (!$item->product_id) continue;
                $product = WholesaleProduct::lockForUpdate()->find($item->product_id);
                if ($product) $product->update(['current_stock' => MoneyService::add(
                    (string) $product->current_stock,
                    (string) ($item->base_quantity ?: $item->quantity),
                )]);
            }
            $newTotal = MoneyService::sub((string) $invoice->total_amount, (string) $ret->total_amount);
            $newBalance = MoneyService::sub((string) $invoice->balance_due, $credited);
            $invoice->update([
                'subtotal' => MoneyService::sub((string) $invoice->subtotal, (string) $ret->subtotal_amount),
                'discount_amount' => MoneyService::sub((string) $invoice->discount_amount, (string) $ret->discount_amount),
                'tax_amount' => MoneyService::sub((string) $invoice->tax_amount, (string) $ret->tax_amount),
                'total_amount' => $newTotal,
                'balance_due' => $newBalance,
                'status' => MoneyService::compare($newBalance, '0') <= 0 ? 'paid' : 'partial_paid',
                'notes' => trim(($invoice->notes ?? '') . "\n[RETURN {$ret->return_ulid}] {$ret->total_amount}"),
            ]);
            if (MoneyService::compare($credited, '0') > 0) {
                $customer->update(['current_balance' => MoneyService::sub((string) $customer->current_balance, $credited)]);
            }
            if ($invoice->sales_rep_id && MoneyService::compare((string) $invoice->sales_rep_commission_amount, '0') > 0) {
                $rep = WholesaleSalesRep::lockForUpdate()->find($invoice->sales_rep_id);
                if ($rep) {
                    $commissionReduction = MoneyService::div(
                        MoneyService::mul((string) $invoice->sales_rep_commission_amount, (string) $ret->total_amount),
                        MoneyService::add($newTotal, (string) $ret->total_amount),
                    );
                    $rep->update([
                        'total_sales' => MoneyService::sub((string) $rep->total_sales, (string) $ret->total_amount),
                        'total_commission_earned' => MoneyService::sub((string) $rep->total_commission_earned, $commissionReduction),
                    ]);
                    $invoice->update(['sales_rep_commission_amount' => MoneyService::sub((string) $invoice->sales_rep_commission_amount, $commissionReduction)]);
                }
            }
            $ret->update([
                'status' => 'approved', 'reviewed_by_user_id' => $reviewer->id,
                'settlement_type' => MoneyService::compare($refundDue, '0') > 0 ? 'refund_pending' : 'credit_note',
                'credited_amount' => $credited, 'refund_due_amount' => $refundDue,
                'decision_note' => $note, 'resolved_at' => now(),
            ]);
            return $ret->fresh(['items', 'invoice', 'customer']);
        });
    }
}
