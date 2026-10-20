<?php

namespace App\Exceptions;

use RuntimeException;

final class InsufficientFunds extends RuntimeException
{
    public function __construct(public readonly int $balanceCents, public readonly int $requestedCents)
    {
        parent::__construct("Insufficient funds: balance {$balanceCents}, requested {$requestedCents}");
    }
}
