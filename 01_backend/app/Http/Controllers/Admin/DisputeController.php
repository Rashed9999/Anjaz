<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeReason;
use App\Models\User;
use App\Traits\TransactionTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisputeController extends Controller
{
    use TransactionTrait;
    public function list(Request $request): View
    {
        $transactionType = $request->filled('trx_type') ? $request->get('trx_type') : 'pending';
        $search = trim($request->get('search', ''));
        $senderType = $request->filled('sender_type') ? $request->get('sender_type') : 'all';

        $queryParam = array_filter([
            'trx_type'    => $transactionType,
            'search'      => $search,
            'sender_type'   => $senderType
        ]);

        $senders = [];
        if ($search !== '') {
            $senders = User::where(function ($q) use ($search) {
                    $q->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('f_name', 'like', "%{$search}%")
                        ->orWhere('l_name', 'like', "%{$search}%");
                })
                ->pluck('id')
                ->toArray();
        }

        $disputes = Dispute::query()
            ->with('sender')
            ->when($transactionType, function ($query) use ($transactionType) {
                $query->where('status', $transactionType);
            })
            ->when($senderType != 'all', function ($query) use ($senderType) {
                $query->where('sender_type', $senderType);
            })
            ->when(!empty($search), function ($query) use ($search, $senders) {
                $query->where(function ($subQuery) use ($search, $senders) {
                    $subQuery->whereIn('sender_id', $senders)
                        ->orWhere('trx_id', 'like', "%{$search}%")
                        ->orWhere('comment', 'like', "%{$search}%")
                        ->orWhere('report_reason', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(Helpers::pagination_limit())
            ->appends($queryParam);

        return view('admin-views.disputes.index', compact('disputes', 'transactionType', 'search', 'senderType'));
    }

    public function changeStatus(Request $request): RedirectResponse
    {
        $request->validate([
            'dispute_id' => 'required|exists:disputes,id',
            'status' => 'required|in:approved,disputed,denied',
            'denied_note' => 'nullable|string|max:150',
        ]);

        $dispute = Dispute::find($request->dispute_id);

        if (!$dispute) {
            Toastr::error(translate('Dispute not found.'));
            return back();
        }

        $disputedSender = User::find($dispute->sender_id);

        if (!$disputedSender) {
            Toastr::error(translate('Disputed sender not found.'));
            return back();
        }

        $disputedUser = User::find($dispute->disputed_user_id);

        if (!$disputedUser) {
            Toastr::error(translate('Disputed user not found.'));
            return back();
        }

        $dispute->status = $request->status;

        if ($request->status == 'denied') {
            $dispute->denied_note = $request->denied_note;
        }

        $dispute->save();

        $senderFcmToken = $dispute->sender->fcm_token ?? null;

        // If status is disputed, process transaction reversal
        if ($request->status == 'disputed') {
            $this->disputeTransaction(ref_transaction_id: $dispute->trx_id, dispute_claimed_user_id: $dispute->sender_id, disputed_user_id: $dispute->disputed_user_id, amount: $dispute->amount);

            $disputedUserData = [
                'title' => translate('Disputed Transaction'),
                'description' => helpers::set_symbol($dispute->amount) . ' ' . translate('has been deducted from your balance'),
                'image' => '',
                'type' => DEDUCTED_DISPUTE_MONEY,
            ];

            if ($disputedUser->fcm_token) {
                try {
                    Helpers::send_push_notif_to_device($disputedUser->fcm_token, $disputedUserData);
                } catch (\Exception $e) {
                    //
                }
            }

            $disputedSenderData = [
                'title' => translate('Disputed Transaction'),
                'description' => helpers::set_symbol($dispute->amount) . ' ' . translate('has been added to your balance'),
                'image' => '',
                'type' => ADDED_DISPUTE_MONEY,
            ];

            if ($senderFcmToken) {
                try {
                    Helpers::send_push_notif_to_device($senderFcmToken, $disputedSenderData);
                } catch (\Exception $e) {
                    //
                }
            }
        }


        if ($senderFcmToken && in_array($request->status, ['approved', 'denied'])) {
            $titles = [
                'approved' => translate('Approved Dispute Request'),
                'denied' => translate('Denied Dispute Request'),
            ];

            $descriptions = [
                'approved' => 'Your dispute for transaction '. $dispute?->transaction?->transaction_id .' has been reviewed and approved by the admin',
                'denied' => 'Your dispute for transaction '. $dispute?->transaction?->transaction_id .' has been reviewed and denied by the admin'
            ];

            $data = [
                'title' => $titles[$request->status],
                'description' => $descriptions[$request->status],
                'image' => '',
                'type' => $dispute?->transaction?->transaction_type,
            ];

            try {
                Helpers::send_push_notif_to_device($senderFcmToken, $data);
            } catch (\Exception $e) {
                //
            }
        }

        Toastr::success(translate('Dispute status updated successfully.'));
        return back();
    }

    public function disputesReasonIndex(Request $request): View
    {
        $queryParam = $request->only(['search', 'user_type']);

        $disputeReasons = DisputeReason::query()
            ->when($request->filled('user_type') && $request->user_type != 'all', function ($query) use ($request) {
                $query->where('user_type', $request->user_type);
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $keywords = explode(' ', $request->search); // Split by space
                $query->where(function ($q) use ($keywords) {
                    foreach ($keywords as $word) {
                        $q->orWhere('reason', 'like', "%{$word}%");
                    }
                });
            })
            ->latest()
            ->paginate(Helpers::pagination_limit())
            ->appends($queryParam);

        return view('admin-views.business-settings.report-disputes', compact('disputeReasons', 'queryParam'));
    }

    public function disputesReasonSettingsStatus(Request $request): RedirectResponse
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'report_disputes_status'], [
            'value' => $request['report_disputes_status'] ?? 0
        ]);

        Toastr::success(translate('successfully_updated'));
        return back();
    }

    public function disputesReasonTimeUpdate(Request $request): RedirectResponse
    {
        $request->validate([
            'report_dispute_time' => 'required|integer|min:1',
            'report_dispute_time_type' => 'required|in:day,hour,minute',
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'report_dispute_time'], [
            'value' => $request['report_dispute_time']
        ]);

        DB::table('business_settings')->updateOrInsert(['key' => 'report_dispute_time_type'], [
            'value' => $request['report_dispute_time_type']
        ]);

        Toastr::success(translate('successfully_updated'));
        return back();
    }

    public function disputesReasonStore(Request $request): RedirectResponse
    {
        $request->validate([
            'reason' => 'required|string|max:150',
            'user_type' => 'required|string|max:30',
        ]);

        DisputeReason::create([
            'reason' => $request->reason,
            'user_type' => $request->user_type,
        ]);

        Toastr::success(translate('Added Successfully!'));
        return redirect()->route('admin.business-settings.report-disputes');

    }

    public function disputesReasonUpdate(Request $request): RedirectResponse
    {
        $request->validate([
            'reason_id' => 'required',
            'reason' => 'required|string|max:150',
            'user_type' => 'required|string|max:30',
        ]);

        $reason = DisputeReason::findOrFail($request->reason_id);

        $reason->update([
            'reason' => $request->reason,
            'user_type' => $request->user_type,
        ]);

        Toastr::success(translate('Updated successfully!'));
        return redirect()->back();

    }

    public function disputesReasonStatus(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $reason = DisputeReason::find($id);

        if (!$reason) {
            Toastr::error(translate('Dispute Reason not found.'));
            return back();
        }

        $reason->status = !$reason->status;
        $reason->save();

        return response()->json([
            'message' => translate('Status update successfully')
        ]);
    }
    public function disputesReasonDelete(Request $request, int $id): RedirectResponse
    {
        $reason = DisputeReason::find($id);

        if (!$reason) {
            Toastr::error(translate('Dispute Reason not found.'));
            return back();
        }

        $reason->delete();

        Toastr::success(translate('Successfully Deleted!'));
        return back();
    }
}
