<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlaceHoldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_after_hours' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
