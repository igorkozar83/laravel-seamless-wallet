<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Bet = 'bet';
    case Win = 'win';
    case Rollback = 'rollback';
    case Cashback = 'cashback';
    case Withdrawal = 'withdrawal';

    public function isDebit(): bool
    {
        return $this === self::Bet;
    }
}
