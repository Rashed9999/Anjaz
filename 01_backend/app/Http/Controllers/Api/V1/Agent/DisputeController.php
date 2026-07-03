<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Models\Dispute;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\DisputeReason;
use App\CentralLogics\helpers;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Collection;

class DisputeController extends Controller
{
    public function reasonList(Request $request): Collection
    {
        return DisputeReason::active()
            ->agent()
            ->get();
    }

    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4',
            'comment' => 'max:255',
            'dispute_reason_id' => 'nullable|min:1',
            'dispute_reason_id.*' => 'exists:dispute_reasons,id',
            'transaction_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('Complete your account verification')], 403); //kyc check

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('PIN is incorrect')], 403); //PIN Check

        $transaction = Transaction::find($request->transaction_id);

        if (!isset($transaction))
            return response()->json(['message' => translate('Transaction not found')], 403);

        if ($transaction->user_id != $request->user()->id) {
            return response()->json(['message' => translate('Unauthorized access to this transaction')], 403);
        }

        // Dispute time settings
        $reportDisputeTime = Helpers::get_business_settings('report_dispute_time') ?? 0;
        $reportDisputeTimeType = Helpers::get_business_settings('report_dispute_time_type') ?? 'day';

        // Check if dispute is still allowed based on time frame
        $disputeDeadline = $transaction->created_at->copy()->add($reportDisputeTime, $reportDisputeTimeType);
        if (now()->greaterThan($disputeDeadline)) {
            return response()->json(['message' => translate('Dispute request time has expired.')], 403);
        }

        $disputeReasons = [];
        if (!empty($request->dispute_reason_id)) {
            $disputeReasons = DisputeReason::whereIn('id', $request->dispute_reason_id)->pluck('reason')->toArray();
        }

        $dispute = new Dispute();
        $dispute->sender_id = $request->user()->id;
        $dispute->sender_type = 'agent';
        $dispute->transaction_id = $transaction->id;
        $dispute->amount = max($transaction->credit, $transaction->debit);
        $dispute->disputed_user_id = $transaction->to_user_id;
        $dispute->trx_id = $transaction->transaction_id;
        $dispute->status = 'pending';
        $dispute->sending_time = now();
        $dispute->report_reason = json_encode($disputeReasons);
        $dispute->comment = $request->comment;
        $dispute->save();

        return response()->json(response_formatter(DISPUTE_STORE_200, null, null), 200);
    }
}
