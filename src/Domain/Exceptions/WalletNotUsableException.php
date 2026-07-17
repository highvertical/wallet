<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Exceptions;

final class WalletNotUsableException extends WalletException
{
    public function statusCode(): int
    {
        return 423;
    }
}
