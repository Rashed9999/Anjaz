<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'transaction_type' => $this->transaction_type,
            'debit' => (float)$this->debit,
            'credit' => (float)$this->credit,
            'charge' => (float)$this->charge,
            'user_info' => User::select(['image','phone', 'type', DB::raw("CONCAT(f_name, ' ' ,l_name) AS name")])->find($this->to_user_id),
            'sender' => User::select(['image', 'phone', 'type', DB::raw("CONCAT(f_name, ' ' ,l_name) AS name")])->find($this->from_user_id),
            'receiver' => User::select(['image','phone', 'type', DB::raw("CONCAT(f_name, ' ' ,l_name) AS name")])->find($this->to_user_id),
            'created_at' => $this->created_at,
            'amount' => (float)($this->debit + $this->credit),
            'dispute' => $this->dispute,
        ];
    }
}
