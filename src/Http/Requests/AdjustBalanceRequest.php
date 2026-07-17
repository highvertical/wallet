<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Amount may be signed (credit or debit adjustment); AdjustBalanceAction
 * itself rejects a zero amount, so that business rule isn't duplicated here.
 */
final class AdjustBalanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^-?\d+(\.\d+)?$/'],
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['sometimes', 'string', 'max:255'],
            'meta' => ['sometimes', 'array'],
        ];
    }
}
