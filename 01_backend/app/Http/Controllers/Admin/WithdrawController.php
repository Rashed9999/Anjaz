<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\User;
use App\Models\WithdrawalMethod;
use App\Models\WithdrawRequest;
use App\Traits\TransactionTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithdrawController extends Controller
{
    use TransactionTrait;

    public function __construct(
        private WithdrawRequest $withdrawRequest,
        private WithdrawalMethod $withdrawalMethod,
        private User $user,
        private EMoney $eMoney
    )
    {}

    public function index(Request $request): View
    {
        $queryParam = [];
        $search = $request['search'];
        $requestStatus =  $request->has('request_status') ? $request['request_status'] : 'all';;

        $method = $request->withdrawal_method;
        $withdrawRequests = $this->withdrawRequest->with('user', 'withdrawal_method')
            ->when($request->has('search'), function ($query) use ($request) {
                $key = explode(' ', $request['search']);

                $userIds = $this->user->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('id', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%")
                            ->orWhere('f_name', 'like', "%{$value}%")
                            ->orWhere('l_name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%");
                    }
                })->get()->pluck('id')->toArray();

                return $query->whereIn('user_id', $userIds);
            })
            ->when($request->has('request_status') && $request->request_status != 'all', function ($query) use ($request) {
                return $query->where('request_status', $request->request_status);
            })
            ->when($request->has('withdrawal_method') && $request->withdrawal_method != 'all', function ($query) use ($request) {
                return $query->where('withdrawal_method_id', $request->withdrawal_method);
            });

        $queryParam = ['search' => $request['search'], 'request_status' => $request['request_status']];
        $withdrawRequests = $withdrawRequests->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);
        $withdrawalMethods = $this->withdrawalMethod->latest()->get();

        return view('admin-views.withdraw.index', compact('withdrawRequests', 'withdrawalMethods', 'method', 'search', 'requestStatus'));
    }

    public function status_update(Request $request): RedirectResponse
    {
        $request->validate([
            'request_id' => 'required',
            'request_status' => 'required|in:approve,deny',
        ]);

        $withdrawRequest = $this->withdrawRequest->with(['user'])->find($request['request_id']);

        if (!isset($withdrawRequest->user)) {
            Toastr::error(translate('The request sender is unavailable'));
            return back();
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-WITHDRAW-DOUBLE-001 — **طلبٌ يُبتّ فيه مرّتين يُعيد المال
        // مرّتين.**
        //
        // لم يكن هنا فحصٌ واحدٌ لحالة الطلب. فضغطتان على «رفض» — أو
        // تحديثُ الصفحة بعد الإرسال، أو موظّفان يفتحان القائمة نفسها —
        // تُنفّذان المسار مرّتين:
        //
        //     pending_balance -= total   ← مرّتين، فيصير سالباً
        //     current_balance += total   ← مرّتين، **فيُعاد المبلغ مرّتين**
        //
        // والمعاملةُ لا تحمي من هذا: كلٌّ منهما معاملةٌ صحيحةٌ بذاتها.
        // والذي يمنعه فحصُ الحالة **داخل** المعاملة مع قفل الصفّ.
        //
        // ولا يظهر في أيّ سجلّ: عمليّتا رفضٍ ناجحتان، والدفترُ يستقبل
        // قيدين، والرصيدُ زاد ضعفَ ما يجب — بلا خطأ.
        //
        // والفحصُ هنا **مبكّرٌ وودود**: يقول للموظّف إنّ الطلب بُتّ فيه
        // بدل أن يصمت. والفحصُ الحقيقيّ داخل المعاملة أدناه.
        if ($withdrawRequest->request_status !== 'pending') {
            Toastr::warning(translate('This request has already been processed'));
            return back();
        }

        if ($request->request_status == 'deny'){
            $total = (string) ($withdrawRequest['amount'] + $withdrawRequest['admin_charge']);

            // AMIAL-LEDGER-WITHDRAW-001 + سلامة الذرّية.
            //
            // كان هذا يُحرّك رصيدين ويحفظ صفّين **خارج أي معاملة**: لو سقط
            // الاتصال بين الحفظين لعاد المال إلى الرصيد المتاح والطلبُ ما
            // زال معلّقاً — فيسحب العميل المبلغ نفسه مرّتين.
            //
            // فصار الثلاثة (فكّ الحجز، ختم الطلب، القيد) في معاملة واحدة.
            // **والرفضُ يُقال رسالةً لا ٥٠٠.**
            //
            // درسٌ مكتوبٌ في `CLAUDE.md`: «الحمايةُ كانت قائمةً والرسالةُ
            // مفقودة — رفضٌ سليمٌ يخرج بـ٥٠٠ إنجليزيّ في وجه الموظّف».
            try {
            DB::transaction(function () use ($withdrawRequest, $request, $total) {
                // **الفحصُ داخل القفل لا قبله** — القاعدة الأولى في
                // `CLAUDE.md`: «فحصٌ يقع خارج القفل لا يظهر إلّا بالتوازي».
                // فالفحصُ أعلاه رسالةٌ للموظّف، وهذا هو الحارس.
                $fresh = $this->withdrawRequest->where('id', $withdrawRequest->id)
                    ->lockForUpdate()->first();

                if (! $fresh || $fresh->request_status !== 'pending') {
                    throw new \App\Exceptions\WithdrawAlreadyProcessedException();
                }

                $account = $this->eMoney->where(['user_id' => $withdrawRequest->user->id])
                    ->lockForUpdate()->first();
                $account->pending_balance -= $total;
                $account->current_balance += $total;
                $account->save();

                $withdrawRequest->request_status = 'denied';
                $withdrawRequest->is_paid = 0;
                $withdrawRequest->admin_note = $request->admin_note ?? null;
                $withdrawRequest->save();

                $this->ledgerWithdrawDenied(
                    userId: (int) $withdrawRequest->user->id,
                    total: $total,
                    sourceId: (string) $withdrawRequest->id,
                );
            });
            } catch (\App\Exceptions\WithdrawAlreadyProcessedException $e) {
                Toastr::warning(translate('This request has already been processed'));
                return back();
            }
        }

        if ($request->request_status == 'approve')
        {
            $admin = $this->user->with(['emoney'])->where('type', 0)->first();
            if ($admin->emoney->current_balance < ($withdrawRequest['amount'] + $withdrawRequest['admin_charge'])) {
                Toastr::warning(translate('You do not have enough balance. Please generate eMoney first.'));
                return back();
            }

            // **وختمُ الطلب داخل معاملةٍ بقفل** — وإلّا فاعتمادان متزامنان
            // يُحرّكان المال مرّتين. و`accept_withdraw_transaction` قافلةٌ
            // في نفسها، لكنّ **ختمَ الطلب** كان خارج أيّ حماية: يُنفَّذ
            // التحويلُ ثمّ يُكتب `approved` — ولا شيء يمنع تنفيذاً ثانياً
            // بينهما.
            //
            // ولمّا كان التحويلُ نفسُه لا يُردّ بعد إتمامه، يُختم الطلبُ
            // **أوّلاً** داخل قفل: من يظفر بالقفل يملك الحقّ في التنفيذ،
            // والثاني يجد الحالةَ غيرَ `pending` فينسحب قبل أن يُحرّك ريالاً.
            try {
                DB::transaction(function () use ($withdrawRequest, $request) {
                    $fresh = $this->withdrawRequest->where('id', $withdrawRequest->id)
                        ->lockForUpdate()->first();

                    if (! $fresh || $fresh->request_status !== 'pending') {
                        throw new \App\Exceptions\WithdrawAlreadyProcessedException();
                    }

                    $withdrawRequest->request_status = 'approved';
                    $withdrawRequest->is_paid = 1;
                    $withdrawRequest->admin_note = $request->admin_note ?? null;
                    $withdrawRequest->save();
                });
            } catch (\App\Exceptions\WithdrawAlreadyProcessedException $e) {
                Toastr::warning(translate('This request has already been processed'));
                return back();
            }

            // والتحويلُ بعد الختم: لو سقط، بقي الطلبُ مختوماً بلا مال —
            // وهو خطأٌ **يُكتشف ويُصحَّح**، بخلاف مالٍ يخرج مرّتين.
            $this->accept_withdraw_transaction($withdrawRequest->user_id, $withdrawRequest['amount'], $withdrawRequest['admin_charge']);
        }

        $type = $request->request_status == 'approve' ? 'withdraw_money_approved' : 'withdraw_money_denied';

        $data = [
            'title' => $request->request_status == 'approve' ? translate('Withdraw_request_accepted') : translate('Withdraw_request_denied'),
            'description' => '',
            'image' => '',
            'type'=> $type,
        ];
        Helpers::send_push_notif_to_device($withdrawRequest->user->fcm_token, $data);

        Toastr::success(translate('The request has been successfully updated'));
        return back();
    }

    public function download(Request $request): StreamedResponse
    {
        $withdrawRequests = $this->withdrawRequest
            ->with('user', 'withdrawal_method')
            ->when($request->has('search'), function ($query) use ($request) {
                $key = explode(' ', $request['search']);

                $userIds = $this->user->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('id', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%")
                            ->orWhere('f_name', 'like', "%{$value}%")
                            ->orWhere('l_name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%");
                    }
                })->get()->pluck('id')->toArray();

                return $query->whereIn('user_id', $userIds);
            })
            ->when($request->has('request_status') && $request->request_status != 'all', function ($query) use ($request) {
                return $query->where('request_status', $request->request_status);
            })
            ->when($request->has('withdrawal_method') && $request->withdrawal_method != 'all', function ($query) use ($request) {
                return $query->where('withdrawal_method_id', $request->withdrawal_method);
            })->get();

        $storage = [];

        foreach ($withdrawRequests as $key=>$withdrawRequest) {
                $field_string = null;
                foreach($withdrawRequest->withdrawal_method_fields as $key2=>$item) {
                    $field_string .= $key2 . ':' . $item . ', ';
                }
                $data = [
                    translate('No') => $key+1,
                    translate('UserName') => $withdrawRequest->user?->f_name . ' ' . $withdrawRequest->user?->l_name,
                    translate('UserPhone') => $withdrawRequest->user?->phone,
                    translate('UserEmail') => $withdrawRequest->user?->email,
                    translate('MethodName') => $withdrawRequest->withdrawal_method?->method_name??'',
                    translate('Amount') => $withdrawRequest->amount,
                    translate('Request Status') => $withdrawRequest->request_status,
                    translate('Withdrawal Method Fields') => $field_string
                ];
                $storage[] = $data;
            }
        return (new FastExcel($storage))->download(time() . '-file.xlsx');
    }
}
