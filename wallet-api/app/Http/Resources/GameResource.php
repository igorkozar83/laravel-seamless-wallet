<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'rtp' => $this->rtp,
            // whenLoaded: only included if the controller eager-loaded it — never triggers a lazy query
            'provider' => $this->whenLoaded('provider', fn () => $this->provider->name),
        ];
    }
}
