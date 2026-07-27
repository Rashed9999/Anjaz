<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\ReceiptNoticeService;
use App\Support\ArabicPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * AMIAL-STATEMENT-001 — كشف حساب المحفظة.
 *
 * الفرق عن «الإيصالات»: الإيصال مستند عن عملية واحدة، والكشف **بيان محاسبي**
 * لفترة — كل الحركات بعمودَي مدين ودائن ورصيد جارٍ، كما تُصدره شركات الصرافة
 * والبنوك. وهو المستند الذي يُطلب عند إثبات الدخل أو تسوية خلاف.
 *
 * المصدر جدول `transactions` لا `receipts`: القيود المحاسبية هي الحقيقة،
 * والإيصالات مشتقّة منها وقد تفشل في التوليد. الكشف يجب أن يعكس المال فعلاً.
 */
class AccountStatementController extends Controller
{
    /** أقصى فترة يسمح بها الكشف — حماية من استعلام يمسح الجدول كلّه. */
    private const MAX_DAYS = 366;

    /** أقصى عدد حركات في استجابة واحدة. */
    private const MAX_ROWS = 500;

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();

        $rows = $this->rows($user->id, $from, $to);

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($rows as $r) {
            $totalDebit = bcadd($totalDebit, $r['debit'], 4);
            $totalCredit = bcadd($totalCredit, $r['credit'], 4);
        }

        return response()->json([
            'success' => true,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => 'YER',
                'opening_balance' => $this->openingBalance($user->id, $from),
                'closing_balance' => $rows ? end($rows)['balance'] : $this->openingBalance($user->id, $from),
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'count' => count($rows),
                'truncated' => count($rows) >= self::MAX_ROWS,
                'items' => $rows,
            ],
        ]);
    }

    /** تصدير الكشف نفسه إلى PDF بنفس هوية الإشعارات. */
    public function pdf(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();
        $rows = $this->rows($user->id, $from, $to);

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($rows as $r) {
            $totalDebit = bcadd($totalDebit, $r['debit'], 4);
            $totalCredit = bcadd($totalCredit, $r['credit'], 4);
        }

        $html = view('receipts.statement', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'ownerName' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')),
            'accountNumber' => $user->account_number ?? $user->unique_id ?? '',
            'openingBalance' => $this->openingBalance($user->id, $from),
            'closingBalance' => $rows ? end($rows)['balance'] : $this->openingBalance($user->id, $from),
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
        ])->render();

        // AMIAL-PDF-CACHE-001: كان يُصيَّر في كل طلب. وكشف الحساب أثقل ما
        // يُصيَّر في التطبيق — A4 عرضيّ بصفوف بعدد حركات الفترة — فهو أوّل
        // من يُقطع اتصاله على شبكة جوّال.
        //
        // والمفتاح تلبيدُ محتوى الكشف نفسه لا تاريخُه: الفترة قد تمتدّ إلى
        // اليوم، فتدخلها حركة جديدة بعد لحظة. وتلبيد المحتوى يجعل أي تبدّل
        // — صفّاً أو رصيداً أو مجموعاً — يُنتج مفتاحاً جديداً حتماً. فلا
        // يُخدَم كشف قديم، وهو خطأ أخطر من البطء: يُبنى عليه تحصيل ومحاسبة.
        $bytes = app(\App\Services\PdfCacheService::class)->remember(
            "stmt_{$user->id}_{$from->format('Ymd')}_{$to->format('Ymd')}_"
                . sha1(json_encode([$rows, $totalDebit, $totalCredit])),
            fn () => ArabicPdf::render($html, ['format' => 'A4-L', 'margin' => 10]),
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="statement-'
                . $from->format('Ymd') . '-' . $to->format('Ymd') . '.pdf"',
        ]);
    }

    /**
     * الفترة المطلوبة، مضبوطة ضمن الحدّ الأقصى.
     * الافتراضي آخر 30 يوماً — الفترة الأكثر طلباً.
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : (clone $to)->subDays(30)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = (clone $to)->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$from, $to];
    }

    /** رصيد ما قبل بداية الفترة — يجعل الكشف مقروءاً بذاته. */
    private function openingBalance(int $userId, Carbon $from): string
    {
        $prev = Transaction::where('user_id', $userId)
            ->where('created_at', '<', $from)
            ->orderByDesc('id')
            ->value('balance');

        return (string) ($prev ?? '0');
    }

    /**
     * حركات الفترة مع بيان كل حركة.
     *
     * البيان يُبنى من `ReceiptNoticeService` نفسه المستعمل في الإشعارات، فلا
     * يتفرّق الوصف بين المستند والكشف — ما يقرؤه المستخدم في الإيصال هو ما
     * يجده في الكشف حرفياً.
     */
    private function rows(int $userId, Carbon $from, Carbon $to): array
    {
        $txns = Transaction::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get();

        if ($txns->isEmpty()) {
            return [];
        }

        // إيصالات الفترة مفهرسة بمرجع العملية — لبناء البيان بلا استعلام لكل صفّ.
        $receipts = Receipt::where('user_id', $userId)
            ->whereIn('reference_transaction_id', $txns->pluck('transaction_id')->filter()->all())
            ->get()
            ->keyBy('reference_transaction_id');

        $notice = app(ReceiptNoticeService::class);

        return $txns->map(function (Transaction $t) use ($receipts, $notice) {
            $receipt = $receipts->get($t->transaction_id);

            $statement = $receipt
                ? $notice->narrative($receipt)
                : $this->fallbackStatement($t);

            return [
                'id' => $t->id,
                'date' => optional($t->created_at)->toIso8601String(),
                'transaction_no' => $t->transaction_no,
                'type' => $t->transaction_type,
                'type_label' => $receipt ? $notice->title($receipt) : $this->typeLabel($t->transaction_type),
                'statement' => $statement,
                'debit' => (string) ($t->debit ?? '0'),
                'credit' => (string) ($t->credit ?? '0'),
                'charge' => (string) ($t->charge ?? '0'),
                'balance' => (string) ($t->balance ?? '0'),
            ];
        })->all();
    }

    /** بيان بديل حين لا يوجد إيصال للحركة (قيود داخلية مثلاً). */
    private function fallbackStatement(Transaction $t): string
    {
        $label = $this->typeLabel($t->transaction_type);
        $amount = (string) (($t->debit ?? '0') > 0 ? $t->debit : $t->credit);

        return trim($label . ' بمبلغ ' . \App\CentralLogics\Helpers::money($amount) . ' ر.ي');
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'send_money' => 'تحويل صادر',
            'received_money' => 'تحويل وارد',
            'cash_out' => 'سحب نقدي',
            'cash_in' => 'إيداع نقدي',
            'add_money' => 'إضافة رصيد',
            'pay_merchant', 'pos_payment', 'qr_payment' => 'دفع لتاجر',
            'bill_payment' => 'سداد فاتورة',
            'refund' => 'استرجاع',
            default => 'عملية',
        };
    }
}
