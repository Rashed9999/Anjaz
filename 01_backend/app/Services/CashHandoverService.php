<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-CASH-HANDOVER-001 — **دورةُ النقد الورقيّ بين الوكيل وأميال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قيدٌ في الدفتر يقول «الوكيل سلّم ٥٠٠٬٠٠٠ نقداً» **لا يُثبت أنّ حقيبةً
 * انتقلت**. والوثيقةُ تطلب حركةً قابلةً للتتبّع بأحدَ عشرَ عنصراً، وتنهى
 * صراحةً عن أن تكون `note` نصّيّةٌ هي الدليلَ الوحيد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والضابط: المستلِمُ يؤكّد، لا المُسلِّم.**
 *
 * تسليمٌ يعلنه المُرسِلُ وحدَه **دعوى**. وسائقٌ يقول «سلّمتُ» لا يُغلق
 * مبلغاً؛ يُغلقه من عدّ المالَ في يده. **ومن أكّد لا يُؤكّد ثانيةً عن
 * نفسه**: المُسلِّمُ لا يستطيع أن يستلم.
 *
 * **ولا يُحذف تسليمٌ ولا يُعدَّل مبلغُه.** خلافٌ في العدّ يُرفَع
 * `disputed` بسببٍ مكتوب — والحذفُ يمحو أثرَ ما وقع.
 */
class CashHandoverService
{
    public const DIRECTIONS = [
        'agent_to_platform' => 'الوكيل سلّم نقداً لأميال',
        'platform_to_agent' => 'أميال سلّمت نقداً للوكيل',
    ];

    public const STATUSES = ['pending', 'confirmed', 'disputed', 'cancelled'];

    /**
     * يفتح تسليماً **معلّقاً** — لا مؤكَّداً.
     *
     * @param  array{location?:?string,reference?:?string,note?:?string,settlement_ulid?:?string,evidence_path?:?string}  $meta
     * @return array<string,mixed>
     */
    public function open(
        string $direction,
        string $amount,
        ?User $from,
        ?User $to,
        User $deliveredBy,
        array $meta = [],
    ): array {
        $this->assertTable();

        if (! isset(self::DIRECTIONS[$direction])) {
            throw new DomainException('اتّجاهُ التسليم غيرُ معروف: '.$direction);
        }

        $amount = MoneyService::normalize($amount);

        if (! MoneyService::isPositive($amount)) {
            throw new DomainException('مبلغُ التسليم يجب أن يكون موجباً');
        }

        $row = [
            'handover_ulid' => (string) Str::ulid(),
            'direction' => $direction,
            'amount' => $amount,
            'settlement_ulid' => $meta['settlement_ulid'] ?? null,
            'from_user_id' => $from?->id,
            'to_user_id' => $to?->id,
            'location' => isset($meta['location']) ? mb_substr((string) $meta['location'], 0, 160) : null,
            'status' => 'pending',
            'reference' => isset($meta['reference']) ? mb_substr((string) $meta['reference'], 0, 120) : null,
            'delivered_by_user_id' => $deliveredBy->id,
            'delivered_at' => now(),
            'evidence_path' => $meta['evidence_path'] ?? null,
            'note' => isset($meta['note']) ? mb_substr((string) $meta['note'], 0, 2000) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // **يُعاد الصفُّ المخزَّنُ لا المصفوفةُ المُدرَجة.** فالمُدرَجةُ
        // تفتقد كلَّ عمودٍ لم يُذكر (`received_by_user_id` مثلاً)، ومن
        // قرأها ظنّ الحقلَ غيرَ موجودٍ لا فارغاً — **وفرقٌ بين «لا مستلِمَ
        // بعد» و«لا حقلَ للمستلِم أصلاً»**.
        $id = DB::table('cash_handovers')->insertGetId($row);

        return (array) DB::table('cash_handovers')->find($id);
    }

    /**
     * **المستلِمُ يؤكّد.** ولا يؤكّد المُسلِّمُ تسليمَ نفسِه.
     *
     * @return array<string,mixed>
     */
    public function confirm(string $handoverUlid, User $receiver, ?string $note = null): array
    {
        $this->assertTable();

        return DB::transaction(function () use ($handoverUlid, $receiver, $note) {
            $h = DB::table('cash_handovers')->where('handover_ulid', $handoverUlid)
                ->lockForUpdate()->first();

            if (! $h) {
                throw new DomainException('لا تسليمَ بهذا المرجع');
            }

            if ($h->status === 'confirmed') {
                // تأكيدٌ مكرّرٌ نجاحٌ صامت — ولا يُرمى خطأٌ يمنع إغلاق شاشة.
                return (array) $h;
            }

            if ($h->status !== 'pending') {
                throw new DomainException('التسليمُ ليس معلّقاً — حالتُه: '.$h->status);
            }

            // **ولا يستلم من سلّم.** وتسليمٌ يؤكّده صاحبُه ليس إثباتاً —
            // هو الدعوى نفسُها مكتوبةً مرّتين.
            if ((int) $h->delivered_by_user_id === (int) $receiver->id) {
                throw new DomainException(
                    'HANDOVER_SELF_CONFIRM_FORBIDDEN: من سلّم لا يستلم — '
                    . 'يؤكّد التسليمَ من عدّ المالَ في يده'
                );
            }

            DB::table('cash_handovers')->where('id', $h->id)->update([
                'status' => 'confirmed',
                'received_by_user_id' => $receiver->id,
                'received_at' => now(),
                'note' => $note !== null
                    ? trim(((string) $h->note)."\n".mb_substr($note, 0, 1000))
                    : $h->note,
                'updated_at' => now(),
            ]);

            return (array) DB::table('cash_handovers')->find($h->id);
        });
    }

    /** خلافٌ في العدّ — **يُرفَع ولا يُحذَف**. */
    public function dispute(string $handoverUlid, User $by, string $reason): array
    {
        $this->assertTable();

        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('سببُ الخلاف مطلوبٌ وواضح');
        }

        DB::table('cash_handovers')->where('handover_ulid', $handoverUlid)
            ->whereIn('status', ['pending', 'confirmed'])
            ->update([
                'status' => 'disputed',
                'dispute_reason' => mb_substr(trim($reason), 0, 2000),
                'updated_at' => now(),
            ]);

        return (array) DB::table('cash_handovers')
            ->where('handover_ulid', $handoverUlid)->first();
    }

    /**
     * تسليماتٌ لم تُؤكَّد بعد.
     *
     * **وهذه هي القيمةُ التشغيليّة:** رصيدٌ رقميٌّ سُلّم مقابل نقدٍ لم
     * يؤكّد أحدٌ استلامَه هو **مالٌ في الطريق** — وقد يكون في جيبٍ.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unconfirmed(int $limit = 200): array
    {
        if (! Schema::hasTable('cash_handovers')) {
            return [];
        }

        return DB::table('cash_handovers')
            ->where('status', 'pending')
            ->orderBy('delivered_at')
            ->limit($limit)->get()
            ->map(fn ($h) => (array) $h + [
                'direction_label' => self::DIRECTIONS[$h->direction] ?? $h->direction,
                // **`diffInHours` تُعيد سالباً في هذا الإصدار من Carbon**
                // حين يكون الطرفُ الآخرُ في الماضي. وعمرٌ سالبٌ يُعرَض
                // «‎−٧٢ ساعة» فيُقرأ تسليماً في المستقبل — ويُرتَّب خطأً
                // فيسقط الأقدمُ إلى آخر القائمة، **وهو أخطرُها**.
                'age_hours' => $h->delivered_at
                    ? (int) abs(\Illuminate\Support\Carbon::parse($h->delivered_at)
                        ->diffInHours(now()))
                    : null,
            ])->all();
    }

    /** أمؤكَّدٌ تسليمُ هذه التسوية؟ — «غيرُ معروف» ليس «نعم». */
    public function settlementIsPhysicallyConfirmed(string $settlementUlid): bool
    {
        if (! Schema::hasTable('cash_handovers')) {
            return false;
        }

        return DB::table('cash_handovers')
            ->where('settlement_ulid', $settlementUlid)
            ->where('status', 'confirmed')
            ->exists();
    }

    private function assertTable(): void
    {
        if (! Schema::hasTable('cash_handovers')) {
            throw new DomainException(
                'جدولُ تسليمات النقد غيرُ مهاجَر — شغّل الهجرات قبل تسجيل تسليم'
            );
        }
    }
}
