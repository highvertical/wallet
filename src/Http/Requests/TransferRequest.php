<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'recipient_id' => ['required'],
            'recipient_type' => ['sometimes', 'string', 'max:255'],
            'amount' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(array_keys((array) config('wallet.currencies', [])))],
            'wallet_name' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'meta' => ['sometimes', 'array'],
        ];
    }
}
