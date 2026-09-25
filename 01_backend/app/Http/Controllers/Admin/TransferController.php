<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Services\AdminWalletTransferService;
use App\Models\EMoney;
use App\Models\Transfer;
use App\Models\User;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        private EMoney $eMoney,
        private Transfer $transfer,
        private User $user,
        private AdminWalletTransferService $walletTransfers,
    ){}

    public function index(Request $request): View
    {
        $queryParam = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);

            $users = $this->user->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%")
                        ->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%");
                }
            })->get()->pluck('id')->toArray();

            $transfers = $this->transfer->where(function ($q) use ($key, $users) {
                foreach ($key as $value) {
                    $q->orWhereIn('sender', $users)
                        ->orWhereIn('receiver', $users)
                        ->orWhere('unique_id', 'like', "%{$value}%")
                        ->orWhere('receiver_type', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $request['search']];
        } else {
            $transfers = $this->transfer;
        }

        $unusedBalance = $this->eMoney->with('user')->whereHas('user', function ($q) {
            $q->where('type', '=', 0);
        })->sum('current_balance');

        $transfers = $transfers->orderBy('id', 'desc')->paginate(Helpers::pagination_limit())->appends($queryParam);
        return view('admin-views.transfer.index', compact('transfers', 'search', 'unusedBalance'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'to_user_id' => 'required|integer',
            'receiver_type' => '',
            'amount' => 'required|numeric|min:0.0001',
            'idempotency_key' => 'nullable|string|max:255',
        ], [
            'amount.min' => translate('Amount must be greater than zero!'),
        ]);

        try {
            $sender = $this->user->findOrFail(Helpers::get_admin_id());
            $recipient = $this->user->findOrFail((int) $request->input('to_user_id'));
            $idempotencyKey = trim((string) (
                $request->header('Idempotency-Key')
                ?: $request->input('idempotency_key', '')
            ));

            $result = $this->walletTransfers->transfer(
                sender: $sender,
                recipient: $recipient,
                amount: (string) $request->input('amount'),
                reason: 'تحويل من محفظة الإدارة',
                requestIdempotencyKey: $idempotencyKey !== '' ? $idempotencyKey : null,
                actor: $request->user() instanceof User ? $request->user() : $sender,
            );

            if ($result['duplicate']) {
                Toastr::info(translate('This transfer request was already processed.'));
                return back();
            }
        } catch (\Throwable $e) {
            report($e);
            Toastr::error(translate('Failed!'));
            return back();
        }

        $recipientToken = $recipient->fcm_token;
        $value = Helpers::order_status_update_message('money_transfer_message');

        try {
            if ($value) {
                Helpers::send_push_notif_to_device($recipientToken, [
                    'title' => translate('Transaction'),
                    'description' => $value,
                    'image' => '',
                    'type' => CASH_IN,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            Toastr::warning(translate('Push notification failed for Customer!'));
        }

        Toastr::success(translate('Transferred Successfully!'));
        return back();
    }

    public function getUser(Request $request): JsonResponse
    {
        $key = explode(' ', $request['q']);
        $receiverType = $request['receiver_type'];
        $data = $this->user
            ->where('type', $receiverType)
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            })
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);

        $data[] = (object)['id' => false, 'text' => translate('Choose')];

        return response()->json($data);
    }
}
