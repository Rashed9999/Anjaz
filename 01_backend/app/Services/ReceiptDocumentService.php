<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PharmacySale;
use App\Models\Receipt;
use App\Models\RestaurantOrder;
use App\Models\WholesaleCollection;
use App\Models\WholesaleInvoice;
use App\Support\Access\AccessConstants as A;
use App\Support\ArabicTafqit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * AMIAL-DOCUMENTS-001 - يبني عقد طباعة واحداً من مصادر العمل الموثوقة.
 *
 * لا يقرأ هذا الـ service مبلغاً أو صنفاً من واجهة Flutter. إيصال المحفظة
 * يُبنى من Receipt/Transaction، وفاتورة التاجر تُبنى من سجل البيع القطاعي
 * (merchant_sales, fuel_sales, pharmacy_sales, restaurant_orders أو
 * wholesale_invoices). القوالب وأحجام الورق مستهلكون للعقد نفسه فقط.
 */
class ReceiptDocumentService
{
    public const DOCUMENT_VERSION = '2';

    private const MERCHANT_TYPES = [
        'pay_merchant', 'pos_payment', 'qr_payment', 'split_bill_payment',
    ];

    private const VERTICAL_LABELS = [
        A::BIZ_QUICK_SALE => 'بيع سريع',
        A::BIZ_RETAIL => 'تجزئة',
        A::BIZ_FUEL => 'محطة وقود',
        A::BIZ_PHARMACY => 'صيدلية',
        A::BIZ_WHOLESALE => 'تجارة جملة',
        A::BIZ_RESTAURANT => 'مطعم',
    ];

    public function __construct(
        private readonly ReceiptNoticeService $notice,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Receipt $receipt): array
    {
        $receipt->loadMissing(['user', 'counterparty']);

        return $this->isMerchantPayment($receipt)
            ? $this->merchantInvoice($receipt)
            : $this->walletVoucher($receipt);
    }

    /** بيانات خفيفة للتطبيق كي يسمّي زر التحميل ويعرض المقاسات الصحيحة. */
    public function descriptor(Receipt $receipt): array
    {
        $document = $this->build($receipt);

        return [
            'kind' => $document['kind'],
            'title' => $document['title'],
            'vertical' => $document['vertical'] ?? null,
            'vertical_label' => $document['vertical_label'] ?? null,
            'document_number' => $document['document_number'],
            'has_line_items' => !empty($document['items']),
            'thermal_print' => $this->thermalPrintPayload($document),
            'formats' => [
                ['code' => 'a4', 'label' => 'PDF A4', 'route' => 'download'],
                ['code' => 'thermal_80', 'label' => 'حراري 80 مم', 'route' => 'thermal?size=80'],
                ['code' => 'thermal_58', 'label' => 'حراري 58 مم', 'route' => 'thermal?size=58'],
            ],
        ];
    }

    public function a4View(array $document): string
    {
        return $document['kind'] === 'merchant_invoice'
            ? 'receipts.merchant-invoice'
            : 'receipts.wallet-voucher';
    }

    public function isCurrent(Receipt $receipt): bool
    {
        $metadata = is_array($receipt->metadata) ? $receipt->metadata : [];
        return (string) ($metadata['print_document_version'] ?? '') === self::DOCUMENT_VERSION;
    }

    private function isMerchantPayment(Receipt $receipt): bool
    {
        return in_array($receipt->receipt_type, self::MERCHANT_TYPES, true);
    }

    /** @return array<string,mixed> */
    private function base(Receipt $receipt): array
    {
        $metadata = is_array($receipt->metadata) ? $receipt->metadata : [];
        $owner = $receipt->user;
        $counterparty = $receipt->counterparty;
        $from = $receipt->direction === 'debit' ? $owner : $counterparty;
        $to = $receipt->direction === 'debit' ? $counterparty : $owner;
        $status = $receipt->op_status;

        return [
            'receipt' => $receipt,
            'receipt_number' => (string) $receipt->receipt_number,
            'document_number' => (string) $receipt->receipt_number,
            'transaction_number' => $receipt->transaction_no ?: $receipt->reference_transaction_id,
            'issued_at' => $receipt->issued_at,
            'operation_type' => $receipt->receipt_type,
            'operation_label' => $this->notice->typeLabel($receipt),
            'direction' => $receipt->direction,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'amount' => (string) $receipt->amount,
            'fee' => (string) $receipt->fee,
            'final_amount' => (string) $receipt->net_amount,
            'amount_words' => ArabicTafqit::yer((string) $receipt->amount),
            'currency' => 'ر.ي',
            'from' => $this->party($from),
            'to' => $this->party($to),
            'verification_code' => (string) $receipt->verification_code,
            'verification_url' => rtrim((string) config('app.url', 'https://amialpay.com'), '/')
                . '/v/' . $receipt->verification_code,
            'platform_logo_data' => $this->platformLogoData(),
            'zone_code' => (string) $receipt->zone_code,
            'zone_label' => $this->zoneLabel((string) $receipt->zone_code),
            'note' => $this->safeText($metadata['note'] ?? null, 240),
            'channel_label' => $this->channelLabel($receipt->receipt_type),
            'metadata' => $metadata,
        ];
    }

    /** @return array<string,mixed> */
    private function walletVoucher(Receipt $receipt): array
    {
        $base = $this->base($receipt);
        $meta = $base['metadata'];
        $context = [];

        foreach ([
            'branch_name' => 'الفرع',
            'branch_code' => 'رمز الفرع',
            'branch_city' => 'المدينة',
            'teller_name' => 'موظف الخدمة',
            'teller_code' => 'رمز الموظف',
            'fund_name' => 'الصندوق',
            'provider' => 'مقدم الخدمة',
            'service' => 'الخدمة',
        ] as $key => $label) {
            $value = $this->safeText($meta[$key] ?? null, 120);
            if ($value !== null) {
                $context[] = ['label' => $label, 'value' => $value];
            }
        }

        return array_merge($base, [
            'kind' => 'wallet_voucher',
            'title' => $this->walletTitle($receipt->receipt_type),
            'subtitle' => $receipt->direction === 'debit'
                ? 'سند قيد مدين صادر إلكترونياً'
                : 'سند قيد دائن صادر إلكترونياً',
            'final_label' => $receipt->direction === 'debit'
                ? 'إجمالي المخصوم'
                : 'صافي المضاف',
            'context_fields' => $context,
            'items' => [],
            'seller' => null,
            'customer' => null,
            'paper_profile' => 'A4',
        ]);
    }

    /** @return array<string,mixed> */
    private function merchantInvoice(Receipt $receipt): array
    {
        $base = $this->base($receipt);
        $merchantUserId = $receipt->direction === 'credit'
            ? (int) $receipt->user_id
            : (int) ($receipt->counterparty_user_id ?? 0);

        $profile = $merchantUserId > 0
            ? MerchantProfile::where('user_id', $merchantUserId)->first()
            : null;
        $merchant = $merchantUserId > 0
            ? Merchant::where('user_id', $merchantUserId)->first()
            : null;
        $vertical = $this->normalizeVertical((string) ($profile?->business_type ?? ''));
        $source = $this->resolveBusinessSource($receipt, $merchantUserId, $vertical);

        $settings = array_merge([
            'header_note' => '',
            'footer_note' => 'شكراً لتعاملكم معنا',
            'phone' => '',
            'address' => '',
            'show_logo' => true,
            'show_phone' => true,
            'show_address' => true,
            'paper_width' => 80,
            'currency_label' => 'ر.ي',
        ], (array) ($profile?->receipt_settings ?? []));

        $sellerUser = $merchantUserId === (int) $receipt->user_id
            ? $receipt->user
            : $receipt->counterparty;
        $storeName = $source['seller_name']
            ?? $merchant?->store_name
            ?? trim(($sellerUser?->f_name ?? '') . ' ' . ($sellerUser?->l_name ?? ''))
            ?: 'تاجر أميال باي';

        $seller = [
            'name' => $storeName,
            'phone' => !empty($settings['show_phone'])
                ? ($this->safeText($settings['phone'] ?? null, 32) ?: $sellerUser?->phone)
                : null,
            'address' => !empty($settings['show_address'])
                ? ($this->safeText($settings['address'] ?? null, 180) ?: $merchant?->address)
                : null,
            'registration_number' => $source['registration_number'] ?? $merchant?->merchant_number,
            'tax_number' => $source['tax_number'] ?? null,
            'logo_data' => !empty($settings['show_logo']) ? $this->merchantLogoData($merchant) : null,
            'logo_url' => !empty($settings['show_logo']) && !empty($merchant?->logo)
                ? $merchant->logo_fullpath
                : null,
            'header_note' => $this->safeText($settings['header_note'] ?? null, 120),
            'footer_note' => $this->safeText($settings['footer_note'] ?? null, 160)
                ?: 'شكراً لتعاملكم معنا',
        ];

        $nonMerchant = $merchantUserId === (int) $receipt->user_id
            ? $receipt->counterparty
            : $receipt->user;
        $customer = $source['customer'] ?? $this->party($nonMerchant);
        $items = $source['items'] ?? [];

        if (empty($items)) {
            // دفع بمبلغ حرّ لا يحمل أصنافاً في مصدر العمل. لا نخترع أسماء؛
            // نعرضه كبند صريح يمكن تدقيقه إلى معاملة المحفظة.
            $items[] = [
                'name' => 'دفع مشتريات عبر أميال باي',
                'sku' => null,
                'unit' => 'خدمة',
                'quantity' => '1',
                'unit_price' => (string) $receipt->amount,
                'discount' => '0',
                'line_total' => (string) $receipt->amount,
                'batch_number' => null,
                'expiry_date' => null,
                'notes' => null,
            ];
        }

        $subtotal = (string) ($source['subtotal'] ?? $receipt->amount);
        $discount = (string) ($source['discount'] ?? '0');
        $tax = (string) ($source['tax'] ?? '0');
        $total = (string) ($source['total'] ?? $receipt->amount);

        return array_merge($base, [
            'kind' => 'merchant_invoice',
            'title' => $source['title'] ?? $this->invoiceTitle($vertical),
            'subtitle' => self::VERTICAL_LABELS[$vertical] ?? 'فاتورة بيع',
            'vertical' => $vertical,
            'vertical_label' => self::VERTICAL_LABELS[$vertical] ?? 'تاجر',
            'document_number' => (string) ($source['document_number'] ?? $receipt->receipt_number),
            'status' => (string) ($source['status'] ?? $base['status']),
            'status_label' => $source['status_label'] ?? $base['status_label'],
            'seller' => $seller,
            'customer' => $customer,
            'items' => $items,
            'context_fields' => $source['context_fields'] ?? [],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'paid' => (string) ($source['paid'] ?? $total),
            'balance_due' => (string) ($source['balance_due'] ?? '0'),
            'payment_method' => $source['payment_method'] ?? $base['channel_label'],
            'amount_words' => ArabicTafqit::yer($total),
            'note' => $source['note'] ?? $base['note'],
            'paper_profile' => 'A4',
            'thermal_width' => (int) ($settings['paper_width'] ?? 80),
        ]);
    }

    /**
     * حمولة صغيرة للطباعة المباشرة في Flutter. تبقى القيم مشتقة من العقد
     * الموثوق نفسه، ولا يعيد التطبيق حساب الفاتورة من بيانات الشاشة.
     *
     * @return array<string,mixed>
     */
    private function thermalPrintPayload(array $document): array
    {
        $invoice = $document['kind'] === 'merchant_invoice';

        return [
            'kind' => $document['kind'],
            'title' => $document['title'],
            'document_number' => $document['document_number'],
            'issued_at' => $document['issued_at']?->toIso8601String(),
            'verification_code' => $document['verification_code'],
            'transaction_number' => $document['transaction_number'],
            'settings' => $invoice ? [
                'store_name' => $document['seller']['name'],
                'header_note' => $document['seller']['header_note'],
                'footer_note' => $document['seller']['footer_note'],
                'phone' => $document['seller']['phone'],
                'address' => $document['seller']['address'],
                'logo_url' => $document['seller']['logo_url'] ?? null,
                'show_logo' => !empty($document['seller']['logo_url']),
                'show_phone' => !empty($document['seller']['phone']),
                'show_address' => !empty($document['seller']['address']),
            ] : [
                'store_name' => 'أميال باي',
                'header_note' => $document['title'],
                'footer_note' => 'سند إلكتروني - الطباعة لا تنشئ معاملة جديدة',
                'show_logo' => false,
                'show_phone' => false,
                'show_address' => false,
            ],
            'lines' => $invoice
                ? collect($document['items'])->map(fn (array $item) => [
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                    'details' => implode(' | ', array_values(array_filter([
                        !empty($item['sku']) ? 'رمز: ' . $item['sku'] : null,
                        !empty($item['unit']) ? 'الوحدة: ' . $item['unit'] : null,
                        !empty($item['batch_number']) ? 'تشغيلة: ' . $item['batch_number'] : null,
                        !empty($item['expiry_date']) ? 'انتهاء: ' . $item['expiry_date'] : null,
                        $item['notes'] ?? null,
                    ]))),
                ])->values()->all()
                : [],
            'context_lines' => $invoice
                ? collect($document['context_fields'])->map(fn (array $field) =>
                    $field['label'] . ': ' . $field['value']
                )->values()->all()
                : [],
            'subtotal' => $invoice ? $document['subtotal'] : $document['amount'],
            'discount' => $invoice ? $document['discount'] : '0',
            'tax' => $invoice ? $document['tax'] : '0',
            'total' => $invoice ? $document['total'] : $document['final_amount'],
            'paid' => $invoice ? $document['paid'] : $document['final_amount'],
            'balance_due' => $invoice ? $document['balance_due'] : '0',
            'amount' => $document['amount'],
            'fee' => $document['fee'],
            'final_amount' => $document['final_amount'],
            'final_label' => $document['final_label'] ?? 'الإجمالي',
            'from_name' => $document['from']['name'] ?? null,
            'to_name' => $document['to']['name'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function resolveBusinessSource(Receipt $receipt, int $merchantId, string $vertical): array
    {
        $referenceType = (string) ($receipt->reference_type ?? '');
        $referenceId = (int) ($receipt->reference_id ?? 0);
        $tx = (string) $receipt->reference_transaction_id;

        if (($referenceType === 'fuel_sale' || $vertical === A::BIZ_FUEL)
            && Schema::hasTable('fuel_sales')) {
            $sale = $referenceType === 'fuel_sale' && $referenceId > 0
                ? FuelSale::where('merchant_user_id', $merchantId)->find($referenceId)
                : FuelSale::where('merchant_user_id', $merchantId)
                    ->where('paid_transaction_id', $tx)->latest('id')->first();
            if ($sale) return $this->fuelSource($sale);
        }

        if (($referenceType === 'pharmacy_sale' || $vertical === A::BIZ_PHARMACY)
            && Schema::hasTable('pharmacy_sales')) {
            $sale = $referenceType === 'pharmacy_sale' && $referenceId > 0
                ? PharmacySale::where('merchant_user_id', $merchantId)->find($referenceId)
                : PharmacySale::where('merchant_user_id', $merchantId)
                    ->where('paid_transaction_id', $tx)->latest('id')->first();
            if ($sale) return $this->pharmacySource($sale);
        }

        if (($referenceType === 'wholesale_invoice' || $vertical === A::BIZ_WHOLESALE)
            && Schema::hasTable('wholesale_invoices')) {
            $invoice = $referenceType === 'wholesale_invoice' && $referenceId > 0
                ? WholesaleInvoice::whereHas('business', fn ($query) =>
                    $query->where('merchant_user_id', $merchantId)
                )->find($referenceId)
                : null;
            if (!$invoice && Schema::hasTable('wholesale_collections')) {
                $collection = WholesaleCollection::where('paid_transaction_id', $tx)
                    ->whereHas('business', fn ($query) =>
                        $query->where('merchant_user_id', $merchantId)
                    )
                    ->latest('id')->first();
                $invoice = $collection?->invoice;
            }
            if ($invoice) return $this->wholesaleSource($invoice);
        }

        if (($referenceType === 'restaurant_order' || $vertical === A::BIZ_RESTAURANT)
            && Schema::hasTable('restaurant_orders')) {
            $order = $referenceType === 'restaurant_order' && $referenceId > 0
                ? RestaurantOrder::where('merchant_user_id', $merchantId)->find($referenceId)
                : null;
            if (!$order && Schema::hasTable('merchant_sales')) {
                $sale = MerchantSale::where('merchant_user_id', $merchantId)
                    ->where('paid_transaction_id', $tx)->latest('id')->first();
                if ($sale) {
                    $order = RestaurantOrder::where('merchant_user_id', $merchantId)
                        ->where('sale_ulid', $sale->sale_ulid)->latest('id')->first();
                }
            }
            if ($order) return $this->restaurantSource($order);
        }

        if (Schema::hasTable('merchant_sales')) {
            $sale = $referenceType === 'merchant_sale' && $referenceId > 0
                ? MerchantSale::where('merchant_user_id', $merchantId)->find($referenceId)
                : MerchantSale::where('merchant_user_id', $merchantId)
                    ->where('paid_transaction_id', $tx)->latest('id')->first();
            if ($sale) return $this->retailSource($sale, $vertical);
        }

        return [];
    }

    /** @return array<string,mixed> */
    private function retailSource(MerchantSale $sale, string $vertical): array
    {
        $sale->loadMissing('lines');
        $items = $sale->lines->map(fn ($line) => [
            'name' => (string) $line->name,
            'sku' => $line->barcode,
            'unit' => 'قطعة',
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount' => (string) $line->line_discount,
            'line_total' => (string) $line->line_total,
            'batch_number' => null,
            'expiry_date' => null,
            'notes' => null,
        ])->values()->all();

        return [
            'title' => $vertical === A::BIZ_QUICK_SALE ? 'فاتورة بيع سريع' : 'فاتورة بيع بالتجزئة',
            'document_number' => 'SALE-' . strtoupper(substr((string) $sale->sale_ulid, -10)),
            'items' => $items,
            'subtotal' => MoneyService::add((string) $sale->total_amount, (string) ($sale->discount_amount ?? '0')),
            'discount' => (string) ($sale->discount_amount ?? '0'),
            'tax' => '0',
            'total' => (string) $sale->total_amount,
            'paid' => (string) $sale->total_amount,
            'balance_due' => '0',
            'payment_method' => $this->paymentMethodLabel((string) $sale->payment_method),
            'status' => (string) $sale->status,
            'status_label' => $this->saleStatusLabel((string) $sale->status),
            'customer' => $this->namedCustomer($sale->customer_name, $sale->customer_phone),
        ];
    }

    /** @return array<string,mixed> */
    private function fuelSource(FuelSale $sale): array
    {
        $sale->loadMissing(['pump.station', 'product', 'companyAccount']);
        $context = [
            ['label' => 'المحطة', 'value' => $sale->pump?->station?->station_name ?: '—'],
            ['label' => 'المضخة', 'value' => (string) ($sale->pump?->pump_number ?? '—')],
        ];
        if ($sale->nozzle_id) $context[] = ['label' => 'المسدس', 'value' => (string) $sale->nozzle_id];
        if ($sale->vehicle_plate) $context[] = ['label' => 'لوحة المركبة', 'value' => (string) $sale->vehicle_plate];
        if ($sale->driver_name) $context[] = ['label' => 'السائق', 'value' => (string) $sale->driver_name];

        return [
            'title' => 'فاتورة بيع وقود',
            'document_number' => 'FUEL-' . strtoupper(substr((string) $sale->sale_ulid, -10)),
            'seller_name' => $sale->pump?->station?->station_name,
            'registration_number' => $sale->pump?->station?->license_number,
            'items' => [[
                'name' => (string) ($sale->product?->name ?? 'وقود'),
                'sku' => null,
                'unit' => 'لتر',
                'quantity' => (string) $sale->liters,
                'unit_price' => (string) $sale->price_per_liter,
                'discount' => '0',
                'line_total' => (string) $sale->total_amount,
                'batch_number' => null,
                'expiry_date' => null,
                'notes' => null,
            ]],
            'context_fields' => $context,
            'subtotal' => (string) $sale->total_amount,
            'discount' => '0',
            'tax' => '0',
            'total' => (string) $sale->total_amount,
            'paid' => (string) $sale->total_amount,
            'balance_due' => '0',
            'payment_method' => $this->paymentMethodLabel((string) $sale->payment_method),
            'status' => (string) $sale->status,
            'status_label' => $this->saleStatusLabel((string) $sale->status),
            'customer' => $sale->companyAccount
                ? $this->namedCustomer($sale->companyAccount->company_name, null)
                : null,
            'note' => $this->safeText($sale->notes, 240),
        ];
    }

    /** @return array<string,mixed> */
    private function pharmacySource(PharmacySale $sale): array
    {
        $sale->loadMissing(['items.batch', 'customer']);
        $items = $sale->items->map(fn ($item) => [
            'name' => (string) $item->product_trade_name,
            'sku' => null,
            'unit' => 'عبوة',
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount' => '0',
            'line_total' => (string) $item->total_price,
            'batch_number' => $item->batch?->batch_number,
            'expiry_date' => $item->batch?->expiry_date?->format('Y-m-d'),
            'notes' => $item->required_prescription ? 'بوصفة طبية' : null,
        ])->values()->all();

        $context = [];
        if ($sale->prescription_number) $context[] = ['label' => 'رقم الوصفة', 'value' => (string) $sale->prescription_number];
        if ($sale->prescribing_doctor) $context[] = ['label' => 'الطبيب', 'value' => (string) $sale->prescribing_doctor];
        if ($sale->prescription_date) $context[] = ['label' => 'تاريخ الوصفة', 'value' => $sale->prescription_date->format('Y-m-d')];

        return [
            'title' => 'فاتورة صيدلية',
            'document_number' => 'PH-' . strtoupper(substr((string) $sale->sale_ulid, -10)),
            'items' => $items,
            'context_fields' => $context,
            'subtotal' => (string) $sale->subtotal,
            'discount' => (string) $sale->discount_amount,
            'tax' => '0',
            'total' => (string) $sale->total_amount,
            'paid' => (string) $sale->total_amount,
            'balance_due' => '0',
            'payment_method' => $this->paymentMethodLabel((string) $sale->payment_method),
            'status' => (string) $sale->status,
            'status_label' => $this->saleStatusLabel((string) $sale->status),
            'customer' => $sale->customer
                ? $this->namedCustomer($sale->customer->full_name, $sale->customer->phone)
                : null,
            'note' => $this->safeText($sale->notes, 240),
        ];
    }

    /** @return array<string,mixed> */
    private function restaurantSource(RestaurantOrder $order): array
    {
        $items = collect((array) $order->items)->map(fn ($item) => [
            'name' => (string) ($item['name'] ?? 'صنف'),
            'sku' => null,
            'unit' => 'طلب',
            'quantity' => (string) ($item['qty'] ?? $item['quantity'] ?? 1),
            'unit_price' => (string) ($item['price'] ?? 0),
            'discount' => '0',
            'line_total' => (string) ($item['line_total'] ?? 0),
            'batch_number' => null,
            'expiry_date' => null,
            'notes' => $this->safeText($item['notes'] ?? null, 120),
        ])->values()->all();

        $context = [
            ['label' => 'رقم الطلب', 'value' => (string) $order->order_no],
            ['label' => 'نوع الطلب', 'value' => $order->table_id ? 'داخل المطعم' : 'سفري'],
        ];
        if ($order->table_id) $context[] = ['label' => 'الطاولة', 'value' => (string) $order->table_id];

        return [
            'title' => 'فاتورة مطعم',
            'document_number' => (string) $order->order_no,
            'items' => $items,
            'context_fields' => $context,
            'subtotal' => (string) $order->subtotal,
            'discount' => '0',
            'tax' => '0',
            'total' => (string) $order->total,
            'paid' => (string) $order->total,
            'balance_due' => '0',
            'payment_method' => 'أميال باي',
            'status' => (string) $order->status,
            'status_label' => $this->saleStatusLabel((string) $order->status),
            'note' => $this->safeText($order->notes, 240),
        ];
    }

    /** @return array<string,mixed> */
    private function wholesaleSource(WholesaleInvoice $invoice): array
    {
        $invoice->loadMissing(['business', 'customer', 'items', 'collections']);
        $items = $invoice->items->map(fn ($item) => [
            'name' => (string) $item->product_name,
            'sku' => $item->product_sku,
            'unit' => (string) $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount' => (string) $item->discount_per_unit,
            'line_total' => (string) $item->line_total,
            'batch_number' => null,
            'expiry_date' => null,
            'notes' => null,
        ])->values()->all();

        return [
            'title' => 'فاتورة بيع جملة',
            'document_number' => (string) $invoice->invoice_number,
            'seller_name' => $invoice->business?->business_name,
            'registration_number' => $invoice->business?->commercial_register,
            'tax_number' => $invoice->business?->tax_number,
            'items' => $items,
            'context_fields' => [
                ['label' => 'تاريخ الاستحقاق', 'value' => $invoice->due_date?->format('Y-m-d') ?: '—'],
                ['label' => 'نوع البيع', 'value' => $invoice->payment_type === 'credit' ? 'آجل' : 'نقدي'],
            ],
            'subtotal' => (string) $invoice->subtotal,
            'discount' => (string) $invoice->discount_amount,
            'tax' => (string) $invoice->tax_amount,
            'total' => (string) $invoice->total_amount,
            'paid' => (string) $invoice->paid_amount,
            'balance_due' => (string) $invoice->balance_due,
            'payment_method' => $invoice->payment_type === 'credit' ? 'آجل' : 'نقدي',
            'status' => (string) $invoice->status,
            'status_label' => $this->saleStatusLabel((string) $invoice->status),
            'customer' => $invoice->customer ? [
                'name' => (string) $invoice->customer->full_name,
                'phone' => $this->maskPhone($invoice->customer->phone),
                'account' => $invoice->customer->company_name,
                'address' => $invoice->customer->address,
                'tax_number' => $invoice->customer->tax_number,
            ] : null,
            'note' => $this->safeText($invoice->notes, 240),
        ];
    }

    /** @return array<string,mixed>|null */
    private function party($user): ?array
    {
        if (!$user) return null;
        $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));

        return [
            'name' => $name !== '' ? $name : 'حساب أميال باي',
            'phone' => $this->maskPhone($user->phone ?? null),
            'account' => $this->maskAccount($user->account_number ?? $user->unique_id ?? null),
            'address' => null,
            'tax_number' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function namedCustomer(?string $name, ?string $phone): ?array
    {
        $name = trim((string) $name);
        if ($name === '' && empty($phone)) return null;

        return [
            'name' => $name !== '' ? $name : 'عميل نقدي',
            'phone' => $this->maskPhone($phone),
            'account' => null,
            'address' => null,
            'tax_number' => null,
        ];
    }

    private function merchantLogoData(?Merchant $merchant): ?string
    {
        $logo = $merchant?->logo;
        if (!$logo) return null;
        $relative = 'merchant/' . ltrim((string) $logo, '/');
        if (!Storage::disk('public')->exists($relative)) return null;

        $mime = match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return $this->logoDataUri(
            (string) Storage::disk('public')->get($relative),
            $mime,
            'merchant:' . $relative,
        );
    }

    private function platformLogoData(): ?string
    {
        foreach (['branding/logo-full.png', 'branding/logo.png'] as $relative) {
            $absolute = public_path($relative);
            if (is_file($absolute) && is_readable($absolute)) {
                return $this->logoDataUri(
                    (string) file_get_contents($absolute),
                    'image/png',
                    'platform:' . $relative . ':' . (string) filemtime($absolute),
                );
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-DOCUMENTS-002 — **شعارٌ بحجمه الكامل داخل كلّ إيصال.**
    //
    //  **ما قِيس:** `public/branding/logo-full.png` يزن ٩٢٣ كيلوبايت
    //  (١٢٥٤×١٢٥٤)، ويُضمَّن في HTML مُرمَّزاً بـbase64 — فيصير ١٫٢
    //  ميغابايت. **والقالبُ يعرضه ١١٦×٦٦ بكسل.**
    //
    //  ```
    //  html bytes : 1,238,522     ← إيصالُ محفظةٍ واحد
    //  أكبر قطعة  : 1,231,xxx     ← <img src="data:image/png;base64,…">
    //  ```
    //
    //  **وmPDF يرفض ما يتجاوز `pcre.backtrack_limit`** (١٠٠٠٠٠٠ هنا):
    //
    //      MpdfException: The HTML code size is larger than
    //      pcre.backtrack_limit 1000000
    //
    //  فسقط توليدُ كلّ إيصالٍ PDF — **وإصدارُ الإيصال نفسُه يستدعيه**،
    //  فسقط معه ٧٦ اختباراً في مسارات التحويل والشبّاك ودفع الطلبات.
    //
    //  **والرسالةُ لا تدلّ على سببها**: تتحدّث عن حدٍّ في PCRE، والعطلُ
    //  صورةٌ غيرُ مصغَّرة. (وهو صنفُ الأعطال الذي مُلئ به هذا الملفّ.)
    //
    //  فيُصغَّر الشعارُ مرّةً إلى عرض الطباعة ويُخزَّن مُرمَّزاً. ولا
    //  يُستبدل بمسارٍ على القرص: **القالبُ نفسُه يُعرض في المتصفّح**
    //  للمعاينة والطباعة، ومسارُ ملفٍّ محلّيٍّ لا يُحمَّل هناك.
    // ══════════════════════════════════════════════════════════════════

    /** عرضُ الشعار في الطباعة ١١٦ بكسل — والضعفُ لدقّة الشاشات العالية. */
    private const LOGO_MAX_WIDTH = 240;

    private function logoDataUri(string $bytes, string $mime, string $cacheKey): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $key = 'receipt:logo:' . self::DOCUMENT_VERSION . ':' . md5($cacheKey);

        return Cache::remember($key, now()->addDay(), function () use ($bytes, $mime) {
            $small = $this->downscalePng($bytes);

            // **والتصغيرُ إن تعذّر لا يُسقط الإيصال**: يُرجَع الأصل، وهو
            // ما كان يقع قبل هذا الإصلاح — فالعطلُ يعود لا يختفي صامتاً.
            return 'data:' . ($small !== null ? 'image/png' : $mime) . ';base64,'
                . base64_encode($small ?? $bytes);
        });
    }

    /** يُرجع PNG مصغَّراً، أو `null` إن تعذّر — بلا رمي. */
    private function downscalePng(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $src = @imagecreatefromstring($bytes);

            if ($src === false) {
                return null;
            }

            $w = imagesx($src);

            if ($w <= self::LOGO_MAX_WIDTH) {
                imagedestroy($src);

                return null;   // صغيرٌ أصلاً — يُترك كما هو
            }

            $dst = imagescale($src, self::LOGO_MAX_WIDTH);
            imagedestroy($src);

            if ($dst === false) {
                return null;
            }

            // الشفافيّةُ تُحفظ — شعارٌ بخلفيّةٍ سوداء على ورقةٍ بيضاء
            // عطلٌ مرئيٌّ لا يرفعه أحد.
            imagesavealpha($dst, true);

            ob_start();
            imagepng($dst, null, 9);
            $out = (string) ob_get_clean();
            imagedestroy($dst);

            return $out !== '' ? $out : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeVertical(string $vertical): string
    {
        return in_array($vertical, A::ALL_BUSINESS_TYPES, true)
            ? $vertical
            : A::BIZ_QUICK_SALE;
    }

    private function invoiceTitle(string $vertical): string
    {
        return match ($vertical) {
            A::BIZ_RETAIL => 'فاتورة بيع بالتجزئة',
            A::BIZ_FUEL => 'فاتورة بيع وقود',
            A::BIZ_PHARMACY => 'فاتورة صيدلية',
            A::BIZ_WHOLESALE => 'فاتورة بيع جملة',
            A::BIZ_RESTAURANT => 'فاتورة مطعم',
            default => 'فاتورة بيع سريع',
        };
    }

    private function channelLabel(string $type): string
    {
        return match ($type) {
            'pos_payment' => 'نقطة بيع',
            'qr_payment' => 'رمز QR',
            'cash_in' => 'إيداع نقدي',
            'cash_out', 'withdraw' => 'سحب نقدي',
            'send_money' => 'تحويل محفظة',
            default => 'أميال باي',
        };
    }

    private function walletTitle(string $type): string
    {
        return match ($type) {
            'cash_in', 'add_money' => 'سند إيداع نقدي',
            'cash_out', 'withdraw' => 'سند سحب نقدي',
            'send_money', 'received_money' => 'سند تحويل أموال',
            'refund', 'safe_payment_refunded' => 'سند استرجاع',
            'bank_settlement' => 'سند تسوية',
            'fee_charge' => 'سند رسوم',
            'family_fund_contribute' => 'سند مساهمة',
            'family_fund_disburse' => 'سند صرف',
            default => 'سند عملية مالية',
        };
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'نقدي',
            'credit' => 'آجل',
            'amial_pay' => 'أميال باي',
            'company_card', 'corporate' => 'حساب شركة',
            'mixed' => 'مختلط',
            'bank_transfer' => 'تحويل بنكي',
            'check' => 'شيك',
            default => $method !== '' ? $method : 'أميال باي',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'مكتملة',
            'cancelled', 'voided' => 'ملغاة',
            'under_review' => 'قيد المراجعة',
            'pending' => 'قيد التنفيذ',
            default => $status,
        };
    }

    private function saleStatusLabel(string $status): string
    {
        return match ($status) {
            'completed', 'closed', 'paid', 'credit_paid' => 'مدفوعة',
            'partial_paid' => 'مدفوعة جزئياً',
            'issued', 'credit_unpaid' => 'مستحقة',
            'pending_payment', 'open', 'preparing', 'ready', 'served' => 'قيد التنفيذ',
            'refunded' => 'مسترجعة',
            'voided', 'cancelled' => 'ملغاة',
            'overdue' => 'متأخرة',
            'draft' => 'مسودة',
            default => $status,
        };
    }

    private function zoneLabel(string $zone): string
    {
        return match (strtoupper(trim($zone))) {
            'SOUTH' => 'الجنوب',
            'NORTH' => 'الشمال',
            'EAST' => 'الشرق',
            'WEST' => 'الغرب',
            'ALL' => 'كل المناطق',
            default => $zone !== '' ? $zone : '—',
        };
    }

    private function maskPhone(?string $phone): ?string
    {
        $phone = preg_replace('/\s+/', '', (string) $phone);
        if ($phone === '') return null;
        if (mb_strlen($phone) <= 7) return $phone;
        return mb_substr($phone, 0, 4) . '***' . mb_substr($phone, -3);
    }

    private function maskAccount(?string $account): ?string
    {
        $account = trim((string) $account);
        if ($account === '') return null;
        if (mb_strlen($account) <= 6) return $account;
        return mb_substr($account, 0, 3) . str_repeat('*', max(3, mb_strlen($account) - 6))
            . mb_substr($account, -3);
    }

    private function safeText(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim(strip_tags((string) $value));
        if ($value === '') return null;
        return mb_substr($value, 0, $max);
    }
}
