<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Exceptions;

final class InvalidAmountException extends WalletException
{
    public function statusCode(): int
    {
        return 422;
    }
}
