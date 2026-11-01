<?php

namespace App\Http\Controllers\Merchant;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private User        $user,
        private Transaction $transaction
    ){}

    public function transaction(Request $request): View
    {
        $transactionTypes = $request->has('trx_type') ? $request['trx_type'] : 'all';
        $queryParams = [];
        $search = $request['search'];

        $key = explode(' ', $request['search']);

        $users = [];
        if ($search !== '') {
            $users = $this->user
                ->where(function ($q) use ($search) {
                    $q->orWhere('id', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('f_name', 'like', "%{$search}%")
                        ->orWhere('l_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->pluck('id')
                ->toArray();
        }

        $transactions = $this->transaction
            ->when($search !== '', function ($q) use ($search, $users) {
                $q->where(function ($x) use ($search, $users) {
                    $x->orWhereIn('from_user_id', $users)
                        ->orWhereIn('to_user_id', $users)
                        ->orWhere('transaction_id', 'like', "%{$search}%");
                });
            })
            ->when($request['trx_type'] != 'all', function ($query) use ($request) {
                if ($request['trx_type'] == 'debit') {
                    return $query->where('debit', '!=', 0);
                } else {
                    return $query->where('credit', '!=', 0);
                }
            });

        $queryParams = ['search' => $search, 'trx_type' => $transactionTypes];
        $transactions = $transactions
            ->where('user_id', auth()->user()->id)
            ->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);

        return view('merchant-views.transaction.list', compact('transactions', 'search', 'transactionTypes'));
    }
}
