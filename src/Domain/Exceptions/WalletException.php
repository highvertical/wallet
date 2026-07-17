<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Exceptions;

use RuntimeException;

/**
 * Base for every exception this package throws as part of a normal business
 * flow (as opposed to a programming error). Deliberately not final so a
 * consuming app can catch this one type to mean "a wallet operation failed
 * for a reason the package understands." statusCode() is the only thing
 * subclasses must supply; HTTP rendering itself happens outside the Domain
 * layer (see WalletServiceProvider), since Domain must not depend on
 * Illuminate\Http\*.
 */
abstract class WalletException extends RuntimeException
{
    abstract public function statusCode(): int;
}
