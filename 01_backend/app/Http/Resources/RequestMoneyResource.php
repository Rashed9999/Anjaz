<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use JsonSerializable;

class RequestMoneyResource extends JsonResource
{
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        return [
            'id' => $this->id,
            'sender' => User::select(['phone', DB::raw("CONCAT(f_name, ' ' ,l_name) AS name")])->find($this->from_user_id),
            'receiver' => User::select(['phone', DB::raw("CONCAT(f_name, ' ' ,l_name) AS name")])->find($this->to_user_id),
            'type' => $this->type,
            'amount' => (float)$this->amount,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
