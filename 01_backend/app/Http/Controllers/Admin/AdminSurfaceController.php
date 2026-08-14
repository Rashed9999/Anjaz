<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillProvider;
use App\Models\BillPaymentOrder;
use App\Models\FamilyFund;
use App\Models\PaymentRequest;
use App\Models\PosUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * AMIAL-SURFACE-002 — إظهار الأنظمة اليتيمة (كانت بلا لوحة إدارية):
 * مزوّدو الفواتير، صناديق العائلة، طلبات الأموال، صلاحيات RBAC.
 * عرض من الخادم مباشرةً (بلا JS) — أبسط وأمتن.
 */
class AdminSurfaceController extends Controller
{
    public function billProviders(): View
    {
        $providers = BillProvider::withCount('orders')->orderBy('name')->get();
        $ordersToday = BillPaymentOrder::whereDate('created_at', today())->count();
        return view('admin-views.amial.surface.bill-providers', compact('providers', 'ordersToday'));
    }

    public function toggleBillProvider(int $id): RedirectResponse
    {
        $p = BillProvider::findOrFail($id);
        $p->update(['is_active' => ! $p->is_active]);
        return back()->with('success', 'تم تحديث حالة المزوّد');
    }

    public function funds(): View
    {
        $funds = FamilyFund::withCount('members')
            ->orderByDesc('id')->paginate(25);
        return view('admin-views.amial.surface.funds', compact('funds'));
    }

    /**
     * **صندوقُ عائلةٍ بحركته كاملةً — «أين اختفى المال ومن سحبه؟».**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن الذي دُفع:** سأل صاحبُ المشروع سؤالاً يسأله كلُّ مستعمل:
     * «مستخدمٌ يسأل: أين اختفى المال من الصندوق؟ مَن سحبه؟» — وشاشةُ
     * الصناديق كانت خمسةَ أعمدة: الاسم · الأعضاء · **الرصيد** · الحالة ·
     * تاريخ الإنشاء.
     *
     * **ورصيدٌ بلا حركةٍ لا يُجيب عن شيء.** يرى المديرُ ٧٥٠٠ ولا يعرف
     * كيف صارت كذلك، ولا من أودع، ولا من سحب، ولا متى.
     *
     * **والبياناتُ كانت موجودةً كلُّها**: `family_fund_transactions` فيها
     * `tx_type` و`amount` و`balance_before/after` و`beneficiary_user_id`
     * و`approved_by_user_id`. **مبنيٌّ ولا يُوصَل إليه** — نمطُ العطل
     * الأكثر تكراراً في هذا المشروع.
     *
     * و`balance_before/after` هما ما يجعل الجواب قاطعاً: كلُّ سطرٍ يقول
     * الرصيدَ قبله وبعده، فالفجوةُ — إن وُجدت — تُرى بالعين.
     */
    public function fundDetail(int $id): View
    {
        $fund = FamilyFund::withCount('members')->findOrFail($id);

        $members = DB::table('family_fund_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.fund_id', $fund->id)
            ->orderByDesc('m.total_contributed')
            ->get([
                'm.role', 'm.status', 'm.total_contributed', 'm.total_disbursed',
                'm.joined_at', 'u.id as user_id', 'u.f_name', 'u.l_name', 'u.phone',
            ]);

        $txs = DB::table('family_fund_transactions as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('users as b', 'b.id', '=', 't.beneficiary_user_id')
            ->leftJoin('users as a', 'a.id', '=', 't.approved_by_user_id')
            ->where('t.fund_id', $fund->id)
            ->orderByDesc('t.id')
            ->paginate(50, [
                't.tx_ulid', 't.tx_type', 't.amount', 't.balance_before', 't.balance_after',
                't.note', 't.status', 't.created_at', 't.wallet_transaction_id',
                'u.f_name as actor_f', 'u.l_name as actor_l', 'u.id as actor_id',
                'b.f_name as ben_f', 'b.l_name as ben_l', 'b.id as ben_id',
                'a.f_name as app_f', 'a.id as app_id',
            ]);

        // **الرقمُ يُحسب من مصدره** (القاعدة السادسة): مجموعُ الحركة لا
        // عمودُ الرصيد. فلو اختلفا فذاك بعينه ما يبحث عنه من يسأل «أين
        // اختفى المال».
        $sums = DB::table('family_fund_transactions')
            ->where('fund_id', $fund->id)
            ->where('status', 'completed')
            // **قيمُ `tx_type` تُقرأ من الـenum لا من الذاكرة.** كُتبت أوّلاً
            // `contribution/deposit/topup` — ولا واحدةَ منها موجودة، فكان
            // المجموعان صفرين والفجوةُ تساوي الرصيدَ كلَّه: شاشةٌ تتّهم كلَّ
            // صندوقٍ سليمٍ بضياع ماله. (القاعدة الثالثة: يُقاس ثمّ يُقال.)
            ->selectRaw("
                SUM(CASE WHEN tx_type = 'contribute' THEN amount ELSE 0 END) as inflow,
                SUM(CASE WHEN tx_type IN ('disburse_to_member','disburse_to_external')
                    THEN amount ELSE 0 END) as outflow,
                SUM(balance_after - balance_before) as net
            ")->first();

        $inflow = (string) ($sums->inflow ?? '0');
        $outflow = (string) ($sums->outflow ?? '0');

        // **والمحصّلةُ تُجمَع من فرق الرصيدين لا من تصنيف النوع.** فالإيداعُ
        // والسحبُ عمودان للعرض، أمّا الميزانُ فيجب أن يشمل كلَّ صفٍّ —
        // و`adjustment` نوعٌ رابعٌ لا يقع في أيّهما. ولو حُسب الميزانُ من
        // النوعين وحدهما لاتُّهم كلُّ صندوقٍ فيه تسويةٌ بضياع ماله.
        $derived = (string) ($sums->net ?? '0');
        $stored = (string) ($fund->balance ?? '0');

        return view('admin-views.amial.surface.fund-detail', [
            'fund' => $fund,
            'members' => $members,
            'txs' => $txs,
            'inflow' => $inflow,
            'outflow' => $outflow,
            'derived' => $derived,
            'stored' => $stored,
            // **والفرقُ يُقال صراحةً** — صفرٌ يعني «حُسب فتطابق»، لا «لم يُفحص».
            'gap' => bccomp($derived, $stored, 4) === 0 ? null : bcsub($stored, $derived, 4),
        ]);
    }

    public function paymentRequests(Request $request): View|StreamedResponse
    {
        $q = $this->paymentRequestQuery($request)
            ->with([
                'requester:id,f_name,l_name,phone',
                'recipient:id,f_name,l_name,phone',
                'paidBy:id,f_name,l_name,phone',
            ]);

        if ($request->query('export') === 'csv') {
            return response()->streamDownload(function () use ($q): void {
                $out = fopen('php://output', 'wb');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [
                    'رقم الطلب', 'الرمز', 'الطالب', 'هاتف الطالب',
                    'المطلوب منه', 'هاتف المطلوب منه', 'الطريقة', 'المبلغ',
                    'الحالة', 'رقم العملية', 'الملاحظة', 'أُنشئ', 'دُفع',
                ]);

                $q->chunkById(500, function ($rows) use ($out): void {
                    foreach ($rows as $r) {
                        fputcsv($out, array_map([$this, 'safeCsvCell'], [
                            $r->request_ulid,
                            $r->short_code,
                            trim(($r->requester?->f_name ?? '') . ' ' . ($r->requester?->l_name ?? '')),
                            $r->requester?->phone,
                            trim(($r->recipient?->f_name ?? '') . ' ' . ($r->recipient?->l_name ?? ''))
                                ?: $r->recipient_name,
                            $r->recipient?->phone ?? $r->recipient_phone,
                            $r->share_method,
                            $r->amount,
                            $r->status,
                            $r->paid_transaction_id,
                            $r->note,
                            $r->created_at?->toIso8601String(),
                            $r->paid_at?->toIso8601String(),
                        ]));
                    }
                }, 'id');
                fclose($out);
            }, 'amial-payment-requests-' . now()->format('Ymd-His') . '.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        // مؤشرات حقيقية من جدول الميزة نفسه، وليست أرقاماً ثابتة في الواجهة.
        $summary = PaymentRequest::query()->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count")
            ->selectRaw("SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) AS declined_count")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS paid_amount")
            ->first();

        $requests = $q->orderByDesc('id')->paginate(25)->withQueryString();
        return view('admin-views.amial.surface.payment-requests', compact('requests', 'summary'));
    }

    private function paymentRequestQuery(Request $request): Builder
    {
        $q = PaymentRequest::query();
        $status = (string) $request->query('status', '');
        if (in_array($status, PaymentRequest::STATUSES, true)) {
            $q->where('status', $status);
        }

        $method = (string) $request->query('share_method', '');
        if (in_array($method, PaymentRequest::SHARE_METHODS, true)) {
            $q->where('share_method', $method);
        }

        if ($this->validDate((string) $request->query('from'))) {
            $q->whereDate('created_at', '>=', (string) $request->query('from'));
        }
        if ($this->validDate((string) $request->query('to'))) {
            $q->whereDate('created_at', '<=', (string) $request->query('to'));
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(function (Builder $nested) use ($search): void {
                $like = "%{$search}%";
                $nested->where('request_ulid', 'like', $like)
                    ->orWhere('short_code', 'like', $like)
                    ->orWhere('paid_transaction_id', 'like', $like)
                    ->orWhere('recipient_phone', 'like', $like)
                    ->orWhereHas('requester', function (Builder $user) use ($like): void {
                        $user->where('phone', 'like', $like)
                            ->orWhere('f_name', 'like', $like)
                            ->orWhere('l_name', 'like', $like);
                    })
                    ->orWhereHas('recipient', function (Builder $user) use ($like): void {
                        $user->where('phone', 'like', $like)
                            ->orWhere('f_name', 'like', $like)
                            ->orWhere('l_name', 'like', $like);
                    });
            });
        }

        return $q;
    }

    private function validDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** يمنع أن تتحول قيمة تبدأ بعلامة إلى معادلة عند فتح CSV في Excel. */
    private function safeCsvCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/u', $cell) === 1 ? "'{$cell}" : $cell;
    }

    public function rbac(): View
    {
        $managers = PosUser::with('merchant:id,f_name,l_name')
            ->where(function ($q) {
                $q->whereJsonContains('permissions', 'operations_manager')
                  ->orWhereJsonContains('permissions', 'financial_manager');
            })->orderByDesc('id')->get();
        $staffCount = PosUser::count();
        return view('admin-views.amial.surface.rbac', compact('managers', 'staffCount'));
    }
}
