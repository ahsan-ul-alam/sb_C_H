<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MobileBanking = 'mobile_banking';
    case Cheque = 'cheque';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::MobileBanking => 'Mobile banking',
            self::Cheque => 'Cheque',
            self::Card => 'Card',
            self::Other => 'Other',
        };
    }
}
