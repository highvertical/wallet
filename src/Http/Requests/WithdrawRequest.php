<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WithdrawRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(array_keys((array) config('wallet.currencies', [])))],
            'wallet_name' => ['sometimes', 'string', 'max:255'],
            'reference' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'meta' => ['sometimes', 'array'],
        ];
    }
}
