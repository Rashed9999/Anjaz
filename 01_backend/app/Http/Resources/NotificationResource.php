<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        $return = parent::toArray($request);
        unset($return['updated_at']);
        return $return;
    }
}
