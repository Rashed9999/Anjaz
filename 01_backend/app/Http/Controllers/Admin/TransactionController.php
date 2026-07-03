<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Exceptions\TransactionFailedException;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\RequestMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalMethod;
use Barryvdh\DomPDF\Facade\Pdf;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Exception\InvalidArgumentException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function App\CentralLogics\translate;

class TransactionController extends Controller
{
    public function __construct(
        private EMoney $eMoney,
        private RequestMoney $requestMoney,
        private Transaction $transaction,
        private User $user,
        private WithdrawalMethod $withdrawalMethod
    ) {}

    public function index(Request $request): View
    {
        $trx_type = $request->filled('trx_type') ? $request->trx_type : 'all';
        $search = trim($request->get('search', ''));
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $dateType = $request->get('date_type', 'all');

        $queryParam = array_filter([
            'search'      => $search,
            'trx_type'    => $trx_type,
            'date_type'   => $dateType,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
        ]);

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
            ->when($trx_type !== 'all', function ($q) use ($trx_type) {
                if ($trx_type === 'debit') {
                    $q->where('debit', '>', 0);
                } elseif ($trx_type === 'credit') {
                    $q->where('credit', '>', 0);
                }
            })
            ->when($dateType === 'last_30', function ($q) {
                $q->whereBetween('created_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()]);
            })
            ->when($dateType === 'custom' && $startDate && $endDate, function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);
            });

        $transactions = $transactions->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);
        return view('admin-views.transaction.index', compact('transactions', 'search',  'trx_type'));
    }


    public function exportTransactions(Request $request)
    {
        $trx_type = $request->filled('trx_type') ? $request->trx_type : 'all';
        $transactionType = $request->filled('transaction_type') ? $request->transaction_type : 'all';
        $search = trim($request->get('search', ''));
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $dateType = $request->get('date_type', 'all');

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
            ->when($trx_type !== 'all', function ($q) use ($trx_type) {
                if ($trx_type === 'debit') {
                    $q->where('debit', '>', 0);
                } elseif ($trx_type === 'credit') {
                    $q->where('credit', '>', 0);
                }
            })
            ->when($dateType === 'last_30', function ($q) {
                $q->whereBetween('created_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()]);
            })
            ->when($dateType === 'custom' && $startDate && $endDate, function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);
            });

        $transactions = $transactions->latest()->get();

        // Prepare data for export
        $data = $transactions->map(function ($transaction, $index) {
            $sender = Helpers::get_user_info($transaction->from_user_id);
            $receiver = Helpers::get_user_info($transaction->to_user_id);

            return [
                'SL' => $index + 1,
                'Transaction Id' => $transaction->transaction_id,
                'Sender Info' => $sender ? ($sender->f_name . ' ' . $sender->l_name . ' ' . $sender->phone) : 'N/A',
                'Receiver Info' => $receiver ? ($receiver->f_name . ' ' . $receiver->l_name . ' ' . $receiver->phone) : 'N/A',
                'Debit' => Helpers::set_symbol($transaction['debit']),
                'Credit' => Helpers::set_symbol($transaction['credit']),
                'Charge' => Helpers::set_symbol($transaction['charge']),
                'Transaction_type' => $transaction->transaction_type,
                'Balance' => Helpers::set_symbol($transaction->balance),
                'Created At' => date('d M Y h:i A', strtotime($transaction->created_at)),
            ];
        });

        if (in_array($request->export_type, ['excel', 'csv'])) {
            $totalDebit = $transactions->sum('debit');
            $totalCredit = $transactions->sum('credit');

            // Prepare summary and filter rows
            $summaryRows = collect([
                [
                    'SL' => '',
                    'Transaction Id' => 'Export Summary',
                    'Sender Info' => '',
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ],
                [
                    'SL' => '',
                    'Transaction Id' => 'Search',
                    'Sender Info' => $search ?? 'N/A',
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ],
                [
                    'SL' => '',
                    'Transaction Id' => 'Transaction Type',
                    'Sender Info' => ucwords(str_replace('_', ' ', $transactionType)),
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ],
                [
                    'SL' => '',
                    'Transaction Id' => 'Date Range',
                    'Sender Info' => ($dateType === 'custom' && $startDate && $endDate)
                        ? ($startDate . ' to ' . $endDate)
                        : ($dateType === 'last_30' ? 'Last 30 Days' : 'All'),
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ],
                [
                    'SL' => '',
                    'Transaction Id' => 'Total Debit',
                    'Sender Info' => Helpers::set_symbol($totalDebit),
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ],
                [
                    'SL' => '',
                    'Transaction Id' => 'Total Credit',
                    'Sender Info' => Helpers::set_symbol($totalCredit),
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ],
                [
                    'SL' => '',
                    'Transaction Id' => '',
                    'Sender Info' => '',
                    'Receiver Info' => '',
                    'Debit' => '',
                    'Credit' => '',
                    'Charge' => '',
                    'Transaction_type' => '',
                    'Balance' => '',
                    'Created At' => ''
                ]
            ]);


            // Merge with actual data rows
            $exportRows = $summaryRows->merge($data);

            $filename = 'transactions.' . ($request->export_type === 'excel' ? 'xlsx' : 'csv');
            return (new FastExcel($exportRows))->download($filename);
        }

        if ($request->export_type == 'pdf') {
            $totalDebit = $transactions->sum('debit');
            $totalCredit = $transactions->sum('credit');

            $pdf = Pdf::loadView('admin-views.transaction.statement', [
                'transactions' => $transactions,
                'user' => $request->user(),
                'totalDebit' => $totalDebit,
                'totalCredit' => $totalCredit,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'transaction_type' => $transactionType,
            ]);

            return $pdf->download('transaction.pdf');
        }
    }

    public function requestMoney(Request $request): View
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

            $requestMoney = $this->requestMoney->where(function ($q) use ($key, $users) {
                foreach ($key as $value) {
                    $q->orWhereIn('from_user_id', $users)
                        ->orWhere('to_user_id', $users)
                        ->orWhere('type', 'like', "%{$value}%")
                        ->orWhere('amount', 'like', "%{$value}%")
                        ->orWhere('note', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $request['search']];
        } else {
            $requestMoney = $this->requestMoney;
        }

        if ($request->has('withdrawal_method') && $request->withdrawal_method != 'all') {
            $requestMoney = $requestMoney->where('withdrawal_method_id', $request->withdrawal_method);
        }
        $withdrawalMethods = $this->withdrawalMethod->get();

        $requestMoney = $requestMoney->with('withdrawal_method')->where('to_user_id', Helpers::get_admin_id())->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);
        return View('admin-views.transaction.request_money_list', compact('requestMoney', 'search', 'withdrawalMethods'));
    }

    public function requestMoneyStatusChange(Request $request, string $slug): RedirectResponse
    {
        $requestMoney = $this->requestMoney->find($request->id);

        if ($requestMoney->to_user_id != $request->user()->id) {
            Toastr::error(translate('unauthorized request'));
            return back();
        }

        if (strtolower($slug) == 'deny') {
            try {
                $requestMoney->type = 'denied';
                $requestMoney->save();
            } catch (\Exception $e) {
                Toastr::error(translate('Something went wrong'));
                return back();
            }

            Helpers::send_transaction_notification($requestMoney->from_user_id, $requestMoney->amount, 'denied_money');
            Helpers::send_transaction_notification($requestMoney->to_user_id, $requestMoney->amount, 'denied_money');

            Toastr::success(translate('Successfully changed the status'));
            return back();
        } elseif (strtolower($slug) == 'approve') {
            DB::beginTransaction();
            $data = [];
            $data['from_user_id'] = $requestMoney->to_user_id;
            $data['to_user_id'] = $requestMoney->from_user_id;

            try {
                $sendmoney_charge = 0;   //since agent transaction has no change
                $data['user_id'] = $data['from_user_id'];
                $data['type'] = 'debit';
                $data['transaction_type'] = SEND_MONEY;
                $data['ref_trans_id'] = null;
                $data['amount'] = $requestMoney->amount + $sendmoney_charge;

                if (strtolower($data['type']) == 'debit' && $this->eMoney->where('user_id', $data['from_user_id'])->first()->current_balance < $data['amount']) {
                    Toastr::error(translate('Insufficient Balance'));
                    return back();
                }

                $customer_transaction = Helpers::make_transaction($data);

                Helpers::send_transaction_notification($data['user_id'], $data['amount'], $data['transaction_type']);

                if ($customer_transaction == null) {
                    throw new TransactionFailedException('Transaction from sender is failed');
                }

                //customer(receiver) transaction
                $data['user_id'] = $data['to_user_id'];
                $data['type'] = 'credit';
                $data['transaction_type'] = RECEIVED_MONEY;
                $data['ref_trans_id'] = $customer_transaction;
                $data['amount'] = $requestMoney->amount;
                $agent_transaction = Helpers::make_transaction($data);

                Helpers::send_transaction_notification($data['user_id'], $data['amount'], $data['transaction_type']);

                if ($agent_transaction == null) {
                    throw new TransactionFailedException('Transaction to receiver is failed');
                }

                $requestMoney->type = 'approved';
                $requestMoney->save();

                DB::commit();
            } catch (TransactionFailedException $e) {
                DB::rollBack();
                Toastr::error(translate('Status change failed'));
                return back();
            }

            Toastr::success(translate('Successfully changed the status'));
            return back();
        } else {
            Toastr::error(translate('Status change failed'));
            return back();
        }
    }
}
