<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * amount is optional: omitting it captures the hold's full held amount
 * (see CaptureHoldAction).
 */
final class CaptureHoldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'nullable', 'regex:/^\d+(\.\d+)?$/'],
        ];
    }
}
