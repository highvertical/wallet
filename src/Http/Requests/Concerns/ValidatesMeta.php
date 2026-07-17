<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Requests\Concerns;

/**
 * meta is stored verbatim as JSON on the transaction ledger; without a cap
 * an oversized payload here becomes an oversized, permanent row on a table
 * that's read on every history/export request.
 */
trait ValidatesMeta
{
    protected function metaRules(): array
    {
        return [
            'sometimes',
            'array',
            'max:50',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen(json_encode($value)) > 8192) {
                    $fail('The '.$attribute.' field must not exceed 8192 bytes when JSON-encoded.');
                }
            },
        ];
    }
}
