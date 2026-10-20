<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The public shape of a ledger row. Internal columns (wallet_id, idempotency_key) never leak out. */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_cents' => $this->amount_cents,
            'balance_after_cents' => $this->balance_after_cents,
            'round_id' => $this->round_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
