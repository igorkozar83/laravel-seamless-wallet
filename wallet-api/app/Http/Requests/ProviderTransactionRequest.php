<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validation lives here, not in the controller. A failure returns 422 with the field errors. */
class ProviderTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;                  // the provider is already authenticated by the signature middleware
    }

    public function rules(): array
    {
        return [
            'player_id' => ['required', 'integer', 'exists:users,id'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            // the provider's id = our idempotency key. No ':' — internal ledger keys are namespaced with it
            // (withdrawal:…, cashback:…), so a provider id can never collide with one of ours
            'transaction_id' => ['required', 'string', 'max:100', 'regex:/^[^:]+$/'],
            'round_id' => ['required', 'string', 'max:100'],
        ];
    }
}
