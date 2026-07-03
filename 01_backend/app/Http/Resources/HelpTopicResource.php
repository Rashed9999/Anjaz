<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class HelpTopicResource extends JsonResource
{
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        return [
            'id' => (int)$this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'ranking' => (int)$this->ranking,
            'created_at' => $this->created_at,
        ];
    }
}
