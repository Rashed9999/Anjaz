<?php

namespace App\Services;

use App\Models\HeldSale;
use App\Models\PosUser;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-HELD-SALE-001 — **تعليقُ الفاتورة واستئنافُها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * البقّالُ الذي يقول له الزبون «انتظر، نسيتُ الحليب» كان أمامه بابان: أن
 * يُوقف الطابورَ كلَّه، أو أن يُلغي السلّةَ ويُعيد مسحَ عشرين صنفاً.
 * **وثالثُهما أن يحفظها ويخدم التالي** — وهو هذا.
 *
 * **وأربعةُ حدودٍ تحكمها:**
 *
 *   ① **لا تحجز مخزوناً.** التذكرةُ قد تُهجَر، وحجزُها يُفرّغ الرفَّ
 *      لبيعةٍ قد لا تقع — فيُمنَع زبونٌ يدفع الآن. والبضاعةُ تخرج عند
 *      الدفع كما هي اليوم.
 *   ② **وسقفٌ للمفتوح** (`MAX_OPEN`). لا لضيق الجدول بل لأنّ قائمةً بلا
 *      حدٍّ لا تُقرأ: أربعون تذكرةً تجعل الكاشيرَ يفتح واحدةً جديدةً بدل
 *      أن يبحث. والحدُّ يُجبر على الحسم.
 *   ③ **والاستئنافُ يقفل التذكرة فوراً** داخل قفل. تذكرةٌ تُستأنَف من
 *      شبّاكين معاً تُنتج **بيعتين لسلّةٍ واحدة** — وهو ضعفُ المبلغ على
 *      زبونٍ لم يشترِ مرّتين.
 *   ④ **وأيُّ كاشيرٍ يستأنف تذكرةَ زميله** — الزبونُ انتقل إلى الصندوق
 *      الآخر، وهذا عينُ الغرض. لكنّ **اسمَ من فتحها يبقى محفوظاً لقطةً**.
 */
class HeldSaleService
{
    /**
     * تعليقُ السلّة الحاليّة.
     *
     * @param  array  $items  بصيغة سلّة الكاشير: [['name','qty','price','product_id']]
     */
    public function hold(User $merchant, ?int $posUserId, array $items, array $opts = []): HeldSale
    {
        $items = $this->cleanItems($items);

        if ($items === []) {
            throw new DomainException('لا أصناف في السلة — لا شيء يُعلَّق');
        }

        $open = HeldSale::where('merchant_user_id', $merchant->id)
            ->where('status', HeldSale::OPEN)->count();

        if ($open >= HeldSale::MAX_OPEN) {
            throw new DomainException(sprintf(
                'بلغتَ %d تذكرة مفتوحة — أكمل دفع واحدة أو ألغِها قبل تعليق أخرى',
                HeldSale::MAX_OPEN));
        }

        [$name, $shiftId] = $this->context($merchant, $posUserId);

        $total = '0';
        foreach ($items as $line) {
            $total = MoneyService::add($total,
                bcmul((string) $line['price'], (string) $line['qty'], 4));
        }

        return HeldSale::create([
            'ticket_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'pos_user_id' => $posUserId,
            'opened_by' => $posUserId ?? $merchant->id,
            'opened_by_name' => $name,
            'shift_id' => $shiftId,
            'label' => $this->text($opts['label'] ?? null, 120),
            'customer_name' => $this->text($opts['customer_name'] ?? null, 190),
            'customer_phone' => $this->text($opts['customer_phone'] ?? null, 32),
            'items' => $items,
            'total' => MoneyService::normalize($total),
            'notes' => $this->text($opts['notes'] ?? null, 500),
            'status' => HeldSale::OPEN,
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    /** التذاكرُ المفتوحةُ للمنشأة — ④ لا للجهاز. */
    public function open(User $merchant): array
    {
        return HeldSale::where('merchant_user_id', $merchant->id)
            ->where('status', HeldSale::OPEN)
            ->orderBy('id')
            ->get()
            ->map(fn (HeldSale $t) => $this->arr($t))
            ->all();
    }

    /**
     * ③ **الاستئنافُ يقفلها فوراً** — ولا تُستأنَف مرّتين.
     *
     * ويُعاد محتواها لتُملأ به السلّة. **ولا تُحذَف**: بقاؤها بحالة
     * `resumed` هو الأثرُ الذي يُعرف منه أنّ تذكرةً صارت بيعةً، ومن
     * فتحها ومن أكملها.
     */
    public function resume(User $merchant, string $ticketUlid): array
    {
        return DB::transaction(function () use ($merchant, $ticketUlid) {
            $ticket = HeldSale::where('merchant_user_id', $merchant->id)
                ->where('ticket_ulid', $ticketUlid)
                ->lockForUpdate()->first();

            if (! $ticket) {
                throw new DomainException('التذكرة غير موجودة');
            }

            if ($ticket->status !== HeldSale::OPEN) {
                throw new DomainException($ticket->status === HeldSale::VOIDED
                    ? 'هذه التذكرة أُلغيت'
                    : 'هذه التذكرة استُؤنفت من شبّاك آخر — راجِع مبيعات اليوم قبل إعادة البيع');
            }

            $ticket->update([
                'status' => HeldSale::RESUMED,
                'resumed_at' => now(),
            ]);

            return $this->arr($ticket->fresh());
        });
    }

    /**
     * **وتُعاد إلى «مفتوحة» إن لم تكتمل البيعة.**
     *
     * الاستئنافُ يقفلها قبل الدفع (وذاك صواب: لا تُستأنَف مرّتين). لكنّ
     * الكاشيرَ قد يتراجع أو يسقط الدفع، **فتضيع السلّةُ بلا رجعة** إن لم
     * يكن هناك بابُ عودة — وهو أسوأُ من العطل الذي بُنيت الميزةُ لحلّه.
     */
    public function reopen(User $merchant, string $ticketUlid): array
    {
        $ticket = HeldSale::where('merchant_user_id', $merchant->id)
            ->where('ticket_ulid', $ticketUlid)->first();

        if (! $ticket) {
            throw new DomainException('التذكرة غير موجودة');
        }

        if ($ticket->status !== HeldSale::RESUMED) {
            throw new DomainException('لا تُعاد إلّا تذكرةٌ استُؤنفت ولم تكتمل');
        }

        if ($ticket->sale_ulid) {
            throw new DomainException('هذه التذكرة صارت بيعة — لا تُعاد فتحاً');
        }

        $ticket->update(['status' => HeldSale::OPEN, 'resumed_at' => null]);

        return $this->arr($ticket->fresh());
    }

    /** **والإلغاءُ بسببٍ مكتوب** — تذكرةٌ تختفي بلا سببٍ تُقرأ عطلاً. */
    public function void(User $merchant, string $ticketUlid, string $reason): array
    {
        $ticket = HeldSale::where('merchant_user_id', $merchant->id)
            ->where('ticket_ulid', $ticketUlid)->first();

        if (! $ticket) {
            throw new DomainException('التذكرة غير موجودة');
        }

        if ($ticket->status === HeldSale::VOIDED) {
            throw new DomainException('هذه التذكرة مُلغاة مسبقاً');
        }

        if ($ticket->sale_ulid) {
            throw new DomainException('هذه التذكرة صارت بيعة — الإلغاء يكون بمرتجع');
        }

        $ticket->update([
            'status' => HeldSale::VOIDED,
            'voided_at' => now(),
            'void_reason' => $this->text($reason, 300) ?: 'بلا سبب',
        ]);

        return $this->arr($ticket->fresh());
    }

    /**
     * **تُختَم بالبيعة التي وُلدت منها** — فيُعرف مصيرُها.
     *
     * ولا يُرمى استثناءٌ إن لم تُوجَد: البيعةُ وقعت والمالُ تحرّك، **وربطُ
     * الأثر لا يُسقط بيعةً ناجحة**. (وهو الحدُّ نفسُه الذي يحكم
     * `AuditService::record`: عقوبةُ خطأٍ صغيرٍ لا تكون بفقد ما نجح.)
     */
    public function linkSale(User $merchant, ?string $ticketUlid, string $saleUlid): void
    {
        if (! $ticketUlid) {
            return;
        }

        HeldSale::where('merchant_user_id', $merchant->id)
            ->where('ticket_ulid', $ticketUlid)
            ->whereNull('sale_ulid')
            ->update(['sale_ulid' => $saleUlid, 'status' => HeldSale::RESUMED]);
    }

    // ── داخليّ ─────────────────────────────────────────────────────────

    /**
     * @return array{0:string,1:?int}  اسمُ الفاتح، ورقمُ ورديّته
     */
    private function context(User $merchant, ?int $posUserId): array
    {
        $shiftId = app(CashierShiftService::class)->current($merchant, $posUserId)?->id;

        if ($posUserId === null) {
            $name = trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? ''));

            return [$name !== '' ? $name : 'صاحب المتجر', $shiftId];
        }

        $pos = PosUser::find($posUserId);
        $user = $pos?->user_id ? User::find($pos->user_id) : null;
        $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));

        if ($name === '') {
            $name = trim((string) ($pos->display_name ?? '')) ?: 'موظّف غير معروف';
        }

        return [$name, $shiftId];
    }

    /**
     * **الأسطرُ تُنظَّف ولا تُصدَّق كما وصلت.**
     *
     * تأتي من جهازٍ يمكن العبثُ به (القاعدة الثامنة). وسطرٌ بكمّيّةٍ سالبةٍ
     * أو سعرٍ سالب **يجعل مجموعَ التذكرة أقلَّ ممّا فيها**، فيُقرأ الرقمُ
     * في قائمة الانتظار خطأً — والبيعُ نفسُه يُعيد التسعيرَ من المنتج عند
     * الدفع، لكنّ الرقمَ المعروضَ هنا يجب أن يصدق أيضاً.
     */
    private function cleanItems(array $items): array
    {
        $out = [];

        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $qty = (string) ($raw['qty'] ?? $raw['quantity'] ?? '0');
            $price = (string) ($raw['price'] ?? $raw['unit_price'] ?? '0');

            if (bccomp($qty, '0', 3) <= 0 || bccomp($price, '0', 4) < 0) {
                continue;
            }

            $name = $this->text($raw['name'] ?? null, 200) ?: 'صنف';

            $out[] = [
                'name' => $name,
                'qty' => (float) $qty,
                'price' => MoneyService::normalize($price),
                'product_id' => isset($raw['product_id']) ? (int) $raw['product_id'] : null,
            ];
        }

        return $out;
    }

    private function text(?string $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function arr(HeldSale $t): array
    {
        return [
            'ticket_ulid' => $t->ticket_ulid,
            'label' => $t->label,
            'customer_name' => $t->customer_name,
            'customer_phone' => $t->customer_phone,
            'items' => $t->items ?? [],
            'items_count' => count($t->items ?? []),
            'total' => (string) $t->total,
            'notes' => $t->notes,
            'status' => $t->status,
            'opened_by_name' => $t->opened_by_name,
            'opened_at' => $t->created_at?->toIso8601String(),
            'resumed_at' => $t->resumed_at?->toIso8601String(),
            'sale_ulid' => $t->sale_ulid,
        ];
    }
}
