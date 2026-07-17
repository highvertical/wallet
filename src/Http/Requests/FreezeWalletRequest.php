<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FreezeWalletRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
