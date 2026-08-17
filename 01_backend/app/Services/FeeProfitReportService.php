<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\PlatformFeeEntry;
use App\Models\Transaction;
use App\Support\Fees\FeeOperationRegistry;
use Carbon\CarbonInterface;

/**
 * AMIAL-FEE-TRUTH-011 — **تقريرُ الأرباح: رقمٌ يُفسَّر أو يُقال إنّه لا يُفسَّر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي أدخل هذا الملفّ — عطلان في متحكّمٍ واحد:**
 *
 * **① الجمعُ كان بـ`float`:**
 *
 *     $periodGross = (string) $grossByType->sum(fn ($r) => (float) $r->gross);
 *
 * وكلُّ رقمٍ في هذا المشروع `DECIMAL(20,4)` عمداً. فتحويلُه إلى `float`
 * يُدخل خطأَ تمثيلٍ ثنائيّاً في **الرقم الذي يقرؤه صاحبُ المشروع ليعرف
 * كم ربح**. ولا ينكسر شيءٌ ظاهر: يختلف الرقمُ في المرتبة الرابعة، ثمّ
 * يتراكم على آلاف العمليّات فيصير فرقاً يُرى ولا يُفسَّر.
 *
 * **② و«عمولةُ الوكلاء» كانت طرحاً لا قياساً:**
 *
 *     $periodAgentCommissions = gross − net;
 *
 * أي أنّها **تُعرَّف بأنّها الفرق**. فإن ضاع ريالٌ بين الاثنين — قيدٌ لم
 * يُرحَّل، أو رسمٌ حُصّل ولم يُقيَّد — **ظهر في خانة عمولة الوكلاء
 * وكأنّه دُفع لهم**. والمعادلةُ تتوازن دائماً لأنّها مُعرَّفةٌ لتتوازن،
 * **فلا فرقَ يظهر أبداً مهما ضاع**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **فصار كلُّ طرفٍ يُقاس من مصدره** (‏القاعدة السادسة):
 *
 *   الإجمالي     `SUM(transactions.charge)`      — ما حُصّل من العملاء
 *   الصافي       `SUM(credit) WHERE ADMIN_CHARGE` — ما دخل جيبَ المنصّة
 *   العمولة      `SUM(credit) WHERE AGENT_COMMISSION` — ما خرج للوكلاء
 *
 * **ثمّ تُختبر المعادلة**: `الإجمالي − الصافي − العمولة = 0`.
 *
 * وما زاد أو نقص يُقال **فرقاً غيرَ مفسَّر** باسمه ورقمه — لا يُذاب في
 * خانةٍ أخرى. (‏القاعدة السابعة: «غير معروف» ليس صفراً.)
 */
class FeeProfitReportService
{
    /**
     * تقريرُ فترة.
     *
     * @return array{
     *   from:CarbonInterface, to:CarbonInterface,
     *   gross:string, net:string, agent_commission:string,
     *   unexplained:string, balanced:bool,
     *   by_type:array<int,array<string,mixed>>,
     *   daily:array<int,array<string,string>>,
     *   lifetime_net:string, reconciled:string, pending:string,
     *   today_net:string, transaction_count:int
     * }
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [$from, $to];

        // ── ① ما حُصّل من العملاء ─────────────────────────────────────
        //
        // صفٌّ واحدٌ فقط في كلّ عمليّةٍ يحمل `charge` (‏صفُّ الدافع)، وبقيّةُ
        // الصفوف تكتب `'0'` صراحةً — فلا ازدواجَ في الجمع.
        $grossRow = Transaction::query()
            ->where('charge', '>', 0)
            ->whereBetween('created_at', $range)
            ->selectRaw('COALESCE(SUM(charge),0) AS s, COUNT(*) AS c')
            ->first();

        $gross = MoneyService::normalize((string) ($grossRow->s ?? '0'));
        $count = (int) ($grossRow->c ?? 0);

        // ── ② ما دخل جيبَ المنصّة ─────────────────────────────────────
        $net = $this->sumCredit(ADMIN_CHARGE, $range);

        // ── ③ ما خرج للوكلاء — **يُقاس ولا يُطرح** ────────────────────
        $commission = $this->sumCredit(AGENT_COMMISSION, $range);

        // ── ④ والمعادلةُ تُختبر لا تُفترض ──────────────────────────────
        $unexplained = MoneyService::sub(MoneyService::sub($gross, $net), $commission);

        $lifetime = $this->lifetimeNet();

        return [
            'from' => $from,
            'to' => $to,
            'gross' => $gross,
            'net' => $net,
            'agent_commission' => $commission,
            'unexplained' => $unexplained,
            'balanced' => MoneyService::normalize($unexplained) === '0.0000',
            'transaction_count' => $count,
            'by_type' => $this->byType($range),
            'daily' => $this->daily($from, $to),
            'lifetime_net' => $lifetime['total'],
            'reconciled' => $lifetime['reconciled'],
            'pending' => $lifetime['pending'],
            'today_net' => $this->sumCredit(ADMIN_CHARGE,
                [now()->startOfDay(), now()->endOfDay()]),
        ];
    }

    /** مجموعُ دائنِ نوعٍ من الحركات في فترة — نصّاً لا `float`. */
    private function sumCredit(string $type, array $range): string
    {
        $v = Transaction::query()
            ->where('transaction_type', $type)
            ->whereBetween('created_at', $range)
            ->selectRaw('COALESCE(SUM(credit),0) AS s')
            ->value('s');

        return MoneyService::normalize((string) ($v ?? '0'));
    }

    /**
     * التفصيلُ بنوع العمليّة — **ومعه الاسمُ العربيّ**.
     *
     * فصفٌّ مكتوبٌ فيه `send_money` خاماً يجعل من يقرأ التقريرَ يترجم في
     * رأسه، ومن ترجم أخطأ.
     *
     * @return array<int,array<string,mixed>>
     */
    private function byType(array $range): array
    {
        $rows = Transaction::query()
            ->where('charge', '>', 0)
            ->whereBetween('created_at', $range)
            ->selectRaw('transaction_type, COALESCE(SUM(charge),0) AS gross, COUNT(*) AS cnt')
            ->groupBy('transaction_type')
            ->orderByRaw('SUM(charge) DESC')
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $type = (string) $r->transaction_type;

            $out[] = [
                'type' => $type,
                'label' => self::typeLabel($type),
                'fee_code' => self::feeCodeFor($type),
                'gross' => MoneyService::normalize((string) $r->gross),
                'count' => (int) $r->cnt,
            ];
        }

        return $out;
    }

    /**
     * منحنى الفترة — يوماً بيوم.
     *
     * @return array<int,array<string,string>>
     */
    private function daily(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Transaction::query()
            ->where('transaction_type', ADMIN_CHARGE)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) AS d, COALESCE(SUM(credit),0) AS s')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        // **حدٌّ على الحلقة**: نطاقٌ بعيدٌ يُدخل التقريرَ في آلاف التكرارات.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($end) && $guard++ < 400) {
            $key = $cursor->toDateString();

            $out[] = [
                'date' => $key,
                'net' => MoneyService::normalize((string) ($rows[$key]->s ?? '0')),
            ];

            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * الربحُ التراكميّ — المسوّى + غيرُ المسوّى بعد.
     *
     * @return array{reconciled:string, pending:string, total:string}
     */
    public function lifetimeNet(): array
    {
        // **ولا ذاكرةٌ ساكنةٌ هنا** — `static` داخل دالّةٍ تُبقي الرقمَ
        // حيّاً بعد أن يتغيّر في القاعدة، فيُعرض ربحٌ قديمٌ على أنّه الحاليّ.
        $adminId = Helpers::get_admin_id();

        $reconciled = MoneyService::normalize(
            (string) (EMoney::where('user_id', $adminId)->value('charge_earned') ?? '0'));

        $pending = MoneyService::normalize((string) (PlatformFeeEntry::query()
            ->where('admin_user_id', $adminId)
            ->where('reconciled', false)
            ->selectRaw('COALESCE(SUM(amount),0) AS s')
            ->value('s') ?? '0'));

        return [
            'reconciled' => $reconciled,
            'pending' => $pending,
            'total' => MoneyService::add($reconciled, $pending),
        ];
    }

    /**
     * **§19 — التنقّلُ إلى الأصل**: العمليّاتُ التي كوّنت رقماً.
     *
     * فرقمٌ ماليٌّ لا يُنقَر إليه رقمٌ لا يُفسَّر (`amial-admin-command`:
     * «Never display an unexplained financial number»).
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function transactionsBehind(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $type = null,
        int $perPage = 30,
    ) {
        $q = Transaction::query()
            ->where('charge', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id');

        if ($type !== null && $type !== '') {
            $q->where('transaction_type', $type);
        }

        return $q->paginate($perPage)->withQueryString();
    }

    // ══════════════════════════════════════════════════════════════════
    // الأسماء — عربيّةٌ في كلّ شاشة
    // ══════════════════════════════════════════════════════════════════

    /**
     * **نوعُ الحركة ⇒ رمزُ الرسم** — الجسرُ بين الدفتر وسجلّ العمليّات.
     *
     * فالدفترُ يكتب `send_money` وسجلُّ الرسوم يكتب `SEND_MONEY`، ولا
     * يلتقيان إلّا هنا. **وهذا بعينه شكلُ العطل الذي جعل عمليّاتِ الوكيل
     * مجّانيّةً شهوراً**: اسمان لواحدٍ لا يلتقيان.
     */
    public const TYPE_TO_FEE_CODE = [
        'send_money' => 'SEND_MONEY',
        'cash_out' => 'CASH_OUT',
        'cash_in' => 'CASH_IN',
        'withdraw' => 'WITHDRAW',
        'payment' => 'MERCHANT_QR',
    ];

    public static function feeCodeFor(string $type): ?string
    {
        return self::TYPE_TO_FEE_CODE[strtolower($type)] ?? null;
    }

    /** الاسمُ العربيّ لنوع الحركة — من سجلّ العمليّات إن عُرف. */
    public static function typeLabel(string $type): string
    {
        $code = self::feeCodeFor($type);

        if ($code !== null && FeeOperationRegistry::find($code) !== null) {
            return FeeOperationRegistry::label($code);
        }

        return match (strtolower($type)) {
            'add_money' => 'شحن رصيد',
            'received_money' => 'استلام تحويل',
            'admin_charge' => 'رسمُ المنصّة',
            'agent_commission' => 'عمولةُ وكيل',
            'expense' => 'مصروف',
            default => $type,
        };
    }
}
