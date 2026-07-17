<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Highvertical\Wallet\Http\Requests\Concerns\ValidatesMeta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferRequest extends FormRequest
{
    use ValidatesMeta;

    public function rules(): array
    {
        return [
            'recipient_id' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! is_string($value)) {
                    $fail('The '.$attribute.' field must be a string or integer.');
                }
            }],
            'recipient_type' => ['sometimes', 'string', 'max:255'],
            'amount' => ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(array_keys((array) config('wallet.currencies', [])))],
            'recipient_currency' => ['sometimes', 'string', 'size:3', Rule::in(array_keys((array) config('wallet.currencies', [])))],
            'wallet_name' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'meta' => $this->metaRules(),
        ];
    }
}
