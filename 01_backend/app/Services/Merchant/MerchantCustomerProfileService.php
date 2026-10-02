<?php

namespace App\Services\Merchant;

use App\Models\CustomerCreditAccount;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PharmacyCustomer;
use App\Models\PharmacySale;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCollection;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Services\CustomerCreditService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 360° merchant-customer profile from vertical truth + unified credit ledger.
 *
 * The vertical record and the unified credit account are deliberately kept
 * separate in the payload to avoid adding the same debt twice.
 */
final class MerchantCustomerProfileService
{
    public function __construct(
        private readonly CustomerCreditService $credit,
    ) {}

    public function profile(User $merchant, int $customerId): array
    {
        $vertical = (string) (MerchantProfile::where('user_id', $merchant->id)
            ->value('business_type') ?: A::BIZ_RETAIL);

        return match ($vertical) {
            A::BIZ_PHARMACY => $this->pharmacy($merchant, $customerId),
            A::BIZ_WHOLESALE => $this->wholesale($merchant, $customerId),
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_RESTAURANT
                => $this->generic($merchant, $customerId, $vertical),
            default => throw new DomainException('ملف العميل غير متاح لهذا القطاع'),
        };
    }

    private function generic(User $merchant, int $accountId, string $vertical): array
    {
        $account = CustomerCreditAccount::where('merchant_user_id', $merchant->id)
            ->whereKey($accountId)->first();

        if (! $account) throw (new ModelNotFoundException())->setModel(CustomerCreditAccount::class, [$accountId]);

        $statement = $this->credit->getStatement($account);

        $sales = MerchantSale::where('merchant_user_id', $merchant->id)
            ->where('customer_phone', $account->customer_phone)
            ->whereIn('status', ['completed', 'credit_unpaid', 'credit_paid'])
            ->orderByDesc('created_at');

        $summary = $this->salesSummary(clone $sales, 'total_amount');

        return [
            'vertical' => $vertical,
            'kind' => 'generic',
            'identity' => [
                'id' => $account->id,
                'name' => $account->customer_name,
                'phone' => $account->customer_phone,
                'classification' => $account->classification,
                'is_active' => (bool) $account->is_active,
            ],
            'commerce' => $summary + [
                'recent_sales' => $sales->limit(12)->get([
                    'sale_ulid', 'invoice_number', 'total_amount', 'payment_method',
                    'status', 'created_at',
                ])->map(fn ($s) => [
                    'reference' => $s->sale_ulid,
                    'document_number' => $s->invoice_number,
                    'total' => (string) $s->total_amount,
                    'payment_method' => $s->payment_method,
                    'status' => $s->status,
                    'occurred_at' => $s->created_at?->toIso8601String(),
                ])->all(),
            ],
            'credit' => $this->presentCredit($account, $statement),
            'source' => [
                'customer' => 'customer_credit_accounts',
                'sales' => 'merchant_sales',
                'credit' => 'customer_credit_movements',
            ],
        ];
    }

    private function pharmacy(User $merchant, int $customerId): array
    {
        $pharmacyId = DB::table('pharmacies')->where('merchant_user_id', $merchant->id)->value('id');
        $customer = PharmacyCustomer::where('pharmacy_id', $pharmacyId ?: 0)
            ->whereKey($customerId)->first();

        if (! $customer) throw (new ModelNotFoundException())->setModel(PharmacyCustomer::class, [$customerId]);

        $sales = PharmacySale::where('merchant_user_id', $merchant->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at');

        $credit = $this->creditByPhone($merchant, $customer->phone);

        return [
            'vertical' => A::BIZ_PHARMACY,
            'kind' => 'pharmacy',
            'identity' => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'phone' => $customer->phone,
                'date_of_birth' => $customer->date_of_birth?->toDateString(),
                'age' => $customer->age(),
                'gender' => $customer->gender,
                'notes' => $customer->notes,
                'is_active' => (bool) $customer->is_active,
            ],
            'clinical' => [
                'is_pregnant' => (bool) $customer->is_pregnant,
                'is_breastfeeding' => (bool) $customer->is_breastfeeding,
                'allergies' => $customer->allergies ?? [],
                'chronic_conditions' => $customer->chronic_conditions ?? [],
                'regular_medications' => $customer->regular_medications ?? [],
            ],
            'commerce' => $this->salesSummary(clone $sales, 'total_amount') + [
                'recent_sales' => $sales->limit(12)->get([
                    'sale_ulid', 'invoice_number', 'total_amount', 'payment_method',
                    'status', 'prescription_number', 'prescribing_doctor', 'created_at',
                ])->map(fn ($s) => [
                    'reference' => $s->sale_ulid,
                    'document_number' => $s->invoice_number,
                    'total' => (string) $s->total_amount,
                    'payment_method' => $s->payment_method,
                    'status' => $s->status,
                    'prescription_number' => $s->prescription_number,
                    'prescribing_doctor' => $s->prescribing_doctor,
                    'occurred_at' => $s->created_at?->toIso8601String(),
                ])->all(),
            ],
            'credit' => $credit,
            'source' => [
                'customer' => 'pharmacy_customers',
                'sales' => 'pharmacy_sales',
                'credit' => $credit ? 'customer_credit_accounts + customer_credit_movements' : null,
            ],
        ];
    }

    private function wholesale(User $merchant, int $customerId): array
    {
        $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
        $customer = WholesaleCustomer::where('business_id', $businessId ?: 0)
            ->whereKey($customerId)->first();

        if (! $customer) throw (new ModelNotFoundException())->setModel(WholesaleCustomer::class, [$customerId]);

        $invoices = WholesaleInvoice::where('business_id', $businessId)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'voided'])
            ->orderByDesc('invoice_date');

        $collections = WholesaleCollection::where('business_id', $businessId)
            ->where('customer_id', $customer->id)
            ->orderByDesc('collection_date');

        $credit = $this->creditByPhone($merchant, $customer->phone);

        return [
            'vertical' => A::BIZ_WHOLESALE,
            'kind' => 'wholesale',
            'identity' => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'company_name' => $customer->company_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'city' => $customer->city,
                'address' => $customer->address,
                'tax_number' => $customer->tax_number,
                'is_active' => (bool) $customer->is_active,
            ],
            'commerce' => [
                'sales_count' => (clone $invoices)->count(),
                'sales_total' => bcadd((string) ((clone $invoices)->sum('total_amount') ?: '0'), '0', 4),
                'open_invoice_balance' => bcadd((string) ((clone $invoices)
                    ->whereIn('status', ['issued', 'partial_paid', 'overdue'])
                    ->sum('balance_due') ?: '0'), '0', 4),
                'collections_total' => bcadd((string) ((clone $collections)->sum('amount') ?: '0'), '0', 4),
                'last_purchase_date' => $customer->last_purchase_date?->toDateString(),
                'recent_invoices' => $invoices->limit(12)->get([
                    'id', 'invoice_number', 'invoice_date', 'due_date', 'total_amount',
                    'paid_amount', 'balance_due', 'status', 'payment_type',
                ])->map(fn ($i) => [
                    'id' => $i->id,
                    'document_number' => $i->invoice_number,
                    'invoice_date' => $i->invoice_date?->toDateString(),
                    'due_date' => $i->due_date?->toDateString(),
                    'total' => (string) $i->total_amount,
                    'paid' => (string) $i->paid_amount,
                    'balance_due' => (string) $i->balance_due,
                    'status' => $i->status,
                    'payment_method' => $i->payment_type,
                ])->all(),
                'recent_collections' => $collections->limit(12)->get([
                    'collection_ulid', 'collection_date', 'amount', 'payment_method',
                    'reference_number',
                ])->map(fn ($c) => [
                    'reference' => $c->collection_ulid,
                    'date' => $c->collection_date?->toDateString(),
                    'amount' => (string) $c->amount,
                    'payment_method' => $c->payment_method,
                    'reference_number' => $c->reference_number,
                ])->all(),
            ],
            'wholesale_credit_policy' => [
                'credit_limit' => (string) $customer->credit_limit,
                'current_balance_snapshot' => (string) $customer->current_balance,
                'payment_terms_days' => $customer->payment_terms_days,
                'available_credit' => (string) $customer->availableCredit(),
                'note' => 'هذا ملخص قطاع الجملة؛ دفتر الدين الموحد أدناه منفصل لمنع العد المزدوج.',
            ],
            'credit' => $credit,
            'source' => [
                'customer' => 'wholesale_customers',
                'sales' => 'wholesale_invoices',
                'collections' => 'wholesale_collections',
                'credit' => $credit ? 'customer_credit_accounts + customer_credit_movements' : null,
            ],
        ];
    }

    private function creditByPhone(User $merchant, ?string $phone): ?array
    {
        if (! $phone) return null;

        $account = CustomerCreditAccount::where('merchant_user_id', $merchant->id)
            ->where('customer_phone', $phone)
            ->where('is_active', true)
            ->first();

        if (! $account) return null;

        return $this->presentCredit($account, $this->credit->getStatement($account));
    }

    private function presentCredit(CustomerCreditAccount $account, array $statement): array
    {
        return [
            'account_id' => $account->id,
            'current_balance' => (string) $account->current_balance,
            'credit_limit' => (float) $account->credit_limit > 0 ? (string) $account->credit_limit : null,
            'classification' => $account->classification,
            'last_payment_at' => $account->last_payment_at?->toIso8601String(),
            'opening_balance' => $statement['opening_balance'],
            'closing_balance' => $statement['closing_balance'],
            'totals' => $statement['totals'],
            'movements' => collect($statement['movements'])->take(-30)->values()->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => (string) $m->amount,
                'balance_after' => (string) $m->balance_after,
                'due_date' => $m->due_date?->toDateString(),
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id,
                'reference_number' => $m->reference_number,
                'note' => $m->note,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function salesSummary($query, string $amountColumn): array
    {
        $count = (clone $query)->count();
        $total = (string) ((clone $query)->sum($amountColumn) ?: '0');
        $last = (clone $query)->value('created_at');

        return [
            'sales_count' => $count,
            'sales_total' => bcadd($total, '0', 4),
            'average_ticket' => $count > 0 ? bcdiv(bcadd($total, '0', 4), (string) $count, 4) : '0.0000',
            'last_visit_at' => $last ? CarbonCarbon::parse($last)->toIso8601String() : null,
        ];
    }
}
