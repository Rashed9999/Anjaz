<?php

namespace App\Services;

use App\Models\MerchantSale;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-RESTAURANT-001 — منطق المطاعم.
 *
 * الطاولات: إنشاء/تعديل/حذف. الطلبات: فتح على طاولة (تُشغَل) → مطبخ (تحضير→جاهز)
 * → تقديم → إغلاق (يُسجّل بيعاً حقيقياً عبر الكاشير ويحرّر الطاولة).
 */
class RestaurantService
{
    // ---------- الطاولات ----------

    public function createTable(User $merchant, string $label, int $seats): RestaurantTable
    {
        $label = trim($label);
        if ($label === '') throw new InvalidArgumentException('اسم الطاولة مطلوب');
        if (RestaurantTable::where('merchant_user_id', $merchant->id)->where('label', $label)->exists()) {
            throw new InvalidArgumentException('طاولة بهذا الاسم موجودة');
        }
        return RestaurantTable::create([
            'merchant_user_id' => $merchant->id,
            'label' => $label,
            'seats' => max(1, $seats),
            'status' => 'free',
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    // ---------- الطلبات ----------

    /** فتح طلب جديد (طاولة أو سفري) — يشغل الطاولة إن وُجدت. */
    public function openOrder(User $merchant, ?int $tableId, array $items, ?string $notes, ?int $openedBy): RestaurantOrder
    {
        return DB::transaction(function () use ($merchant, $tableId, $items, $notes, $openedBy) {
            $table = null;
            if ($tableId) {
                $table = RestaurantTable::where('id', $tableId)
                    ->where('merchant_user_id', $merchant->id)->lockForUpdate()->first();
                if (!$table) throw new InvalidArgumentException('الطاولة غير موجودة');
            }

            [$subtotal, $clean] = $this->normalizeItems($items);

            $order = RestaurantOrder::create([
                'merchant_user_id' => $merchant->id,
                'table_id' => $table?->id,
                'order_no' => $this->orderNo(),
                'status' => 'open',
                'items' => $clean,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'notes' => $notes,
                'opened_by' => $openedBy ?? $merchant->id,
                'opened_at' => now(),
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            if ($table) {
                $table->status = 'occupied';
                $table->save();
            }
            return $order;
        });
    }

    /** تعديل أصناف طلب مفتوح (قبل الإغلاق). */
    public function updateItems(User $merchant, RestaurantOrder $order, array $items, ?string $notes = null): RestaurantOrder
    {
        if ($order->merchant_user_id !== $merchant->id) throw new InvalidArgumentException('الطلب لا يخصّك');
        if (!in_array($order->status, RestaurantOrder::ACTIVE, true)) {
            throw new RuntimeException('لا يمكن تعديل طلب مُغلق');
        }
        [$subtotal, $clean] = $this->normalizeItems($items);
        $order->items = $clean;
        $order->subtotal = $subtotal;
        $order->total = $subtotal;
        if ($notes !== null) $order->notes = $notes;
        $order->save();
        return $order->fresh();
    }

    /** تغيير حالة الطلب في تدفّق المطبخ. */
    public function setStatus(User $merchant, RestaurantOrder $order, string $status): RestaurantOrder
    {
        if ($order->merchant_user_id !== $merchant->id) throw new InvalidArgumentException('الطلب لا يخصّك');
        $allowed = ['open', 'preparing', 'ready', 'served', 'cancelled'];
        if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('حالة غير صحيحة');
        if ($order->status === 'closed') throw new RuntimeException('الطلب مُغلق');

        return DB::transaction(function () use ($order, $status) {
            $order->status = $status;
            $order->save();
            // إلغاء يحرّر الطاولة
            if ($status === 'cancelled' && $order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'free']);
            }
            return $order->fresh();
        });
    }

    /**
     * إغلاق الطلب: يسجّل بيعاً حقيقياً عبر الكاشير (مخزون/ولاء/تقارير)، يربط
     * الفاتورة بالطلب، ويحرّر الطاولة.
     */
    public function closeOrder(
        User $merchant,
        RestaurantOrder $order,
        string $paymentMethod,
        ?array $customer = null,
        ?string $paidTransactionId = null,
        ?int $posUserId = null,
    ): array {
        if ($order->merchant_user_id !== $merchant->id) throw new InvalidArgumentException('الطلب لا يخصّك');
        if ($order->status === 'closed') throw new RuntimeException('الطلب مُغلق مسبقاً');
        if ($order->status === 'cancelled') throw new RuntimeException('الطلب ملغى');
        if (empty($order->items)) throw new RuntimeException('لا يمكن إغلاق طلب فارغ');

        return DB::transaction(function () use ($merchant, $order, $paymentMethod, $customer, $paidTransactionId, $posUserId) {
            $locked = RestaurantOrder::where('id', $order->id)->lockForUpdate()->first();
            if ($locked->status === 'closed') throw new RuntimeException('الطلب مُغلق مسبقاً');

            $sale = app(CashierService::class)->recordSale(
                merchant: $merchant,
                total: (string) $locked->total,
                paymentMethod: $paymentMethod,
                items: $this->toSaleItems($locked->items ?? []),
                posUserId: $posUserId,
                customer: $customer,
                paidTransactionId: $paidTransactionId,
            );

            $locked->status = 'closed';
            $locked->closed_at = now();
            $locked->sale_ulid = $sale->sale_ulid;
            $locked->save();

            if ($locked->table_id) {
                RestaurantTable::where('id', $locked->table_id)->update(['status' => 'free']);
            }

            return ['order' => $locked->fresh(), 'sale' => $sale];
        });
    }

    public function activeOrders(User $merchant)
    {
        return RestaurantOrder::where('merchant_user_id', $merchant->id)
            ->whereIn('status', RestaurantOrder::ACTIVE)->orderByDesc('id')->get();
    }

    /** شاشة المطبخ: الطلبات التي تحتاج تحضيراً/جاهزة. */
    public function kitchenOrders(User $merchant)
    {
        return RestaurantOrder::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['open', 'preparing', 'ready'])
            ->orderBy('opened_at')->get();
    }

    // ---------- helpers ----------

    /** ينظّف الأصناف ويحسب المجموع. كل صنف: name, qty, price. */
    private function normalizeItems(array $items): array
    {
        $subtotal = '0';
        $clean = [];
        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            $qty = (float) ($it['qty'] ?? $it['quantity'] ?? 0);
            $price = (float) ($it['price'] ?? 0);
            if ($name === '' || $qty <= 0) continue;
            $line = round($qty * $price, 2);
            $clean[] = [
                'name' => $name,
                'qty' => $qty,
                'price' => $price,
                'line_total' => $line,
                'product_id' => $it['product_id'] ?? null,
                'notes' => isset($it['notes']) ? (string) $it['notes'] : null,
            ];
            $subtotal = MoneyService::add($subtotal, (string) $line);
        }
        if (empty($clean)) throw new InvalidArgumentException('الطلب يحتاج صنفاً واحداً على الأقل');
        return [MoneyService::normalize($subtotal), $clean];
    }

    /** يحوّل أصناف الطلب لصيغة أسطر بيع الكاشير (لخصم المخزون). */
    private function toSaleItems(array $items): array
    {
        return array_map(fn ($it) => [
            'name' => $it['name'] ?? '',
            'quantity' => $it['qty'] ?? 1,
            'price' => $it['price'] ?? 0,
            'product_id' => $it['product_id'] ?? null,
        ], $items);
    }

    private function orderNo(): string
    {
        return 'R' . now()->format('ymd') . strtoupper(Str::random(4));
    }
}
