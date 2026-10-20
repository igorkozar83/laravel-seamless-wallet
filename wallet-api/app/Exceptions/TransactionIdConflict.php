<?php

namespace App\Exceptions;

use RuntimeException;

/** The provider reused a transaction id for a different wallet, type or amount: a provider bug, never a retry. */
final class TransactionIdConflict extends RuntimeException
{
    public function __construct(public readonly string $transactionId)
    {
        parent::__construct("Transaction id {$transactionId} was already used for a different movement");
    }
}
