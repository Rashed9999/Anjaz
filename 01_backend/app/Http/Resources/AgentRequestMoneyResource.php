<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class AgentRequestMoneyResource extends JsonResource
{
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        return [
            'id' => $this->id,
            'receiver' => User::select(['phone', DB::raw("CONCAT(f_name, ' ' ,l_name) AS name")])->find($this->from_user_id),
            'type' => $this->type,
            'amount' => (float)$this->amount,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
