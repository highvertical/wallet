<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Exceptions;

final class LimitExceededException extends WalletException
{
    public function statusCode(): int
    {
        return 429;
    }
}
