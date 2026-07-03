<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        private User        $user,
        private Transaction $transaction
    )
    {
    }

    public function index(Request $request): View
    {
        $search = $request['search'];
        $queryParams = ['search' => $search, 'date_range' => $request['date_range']];

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

        $transactions = $this->transaction->where('transaction_type', 'expense')
            ->when($request->has('search'), function ($q) use ($users) {
                $q->whereIn('to_user_id', $users);
            })
            ->when($request->has('date_range'), function ($query) use ($request) {
                if ($request['date_range'] == 'this_week') {
                    $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);

                } elseif ($request['date_range'] == 'this_month') {
                    $query->whereMonth('created_at', Carbon::now()->month);

                } elseif ($request['date_range'] == 'last_month') {
                    $query->whereMonth('created_at', Carbon::now()->subMonth()->month);

                } elseif ($request['date_range'] == 'this_year') {
                    $query->whereYear('created_at', Carbon::now()->year);

                } elseif ($request['date_range'] == 'last_year') {
                    $query->whereYear('created_at', Carbon::now()->subYear()->year);
                }
            })
            ->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);

        $totalExpense = $this->transaction->when($request->has('search'), function ($q) use ($users) {
            $q->whereIn('to_user_id', $users);
        })
            ->when($request->has('date_range'), function ($query) use ($request) {
                if ($request['date_range'] == 'this_week') {
                    $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                } elseif ($request['date_range'] == 'this_month') {
                    $query->whereMonth('created_at', Carbon::now()->month);

                } elseif ($request['date_range'] == 'last_month') {
                    $query->whereMonth('created_at', Carbon::now()->subMonth()->month);

                } elseif ($request['date_range'] == 'this_year') {
                    $query->whereYear('created_at', Carbon::now()->year);

                } elseif ($request['date_range'] == 'last_year') {
                    $query->whereYear('created_at', Carbon::now()->subYear()->year);
                }
            })
            ->where('transaction_type', 'expense')
            ->sum(\DB::raw('debit + credit'));

        $userTransactions = $this->transaction->
        join('users', function ($join) {
            $join->on('transactions.to_user_id', '=', 'users.id');
        })
            ->when($request->has('search'), function ($q) use ($users) {
                $q->whereIn('transactions.to_user_id', $users);
            })
            ->when($request->has('date_range'), function ($query) use ($request) {
                if ($request['date_range'] == 'this_week') {
                    $query->whereBetween('transactions.created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);

                } elseif ($request['date_range'] == 'this_month') {
                    $query->whereMonth('transactions.created_at', Carbon::now()->month);

                } elseif ($request['date_range'] == 'last_month') {
                    $query->whereMonth('transactions.created_at', Carbon::now()->subMonth()->month);

                } elseif ($request['date_range'] == 'this_year') {
                    $query->whereYear('transactions.created_at', Carbon::now()->year);

                } elseif ($request['date_range'] == 'last_year') {
                    $query->whereYear('transactions.created_at', Carbon::now()->subYear()->year);
                }
            })
            ->where('transactions.transaction_type', 'expense')
            ->select('users.*')
            ->where('users.type', '!=', 0)
            ->distinct()
            ->get();

        $totalUsers = count($userTransactions);

        return view('admin-views.transaction.expense', compact('transactions', 'totalExpense', 'search', 'totalUsers', 'queryParams'));
    }
}
