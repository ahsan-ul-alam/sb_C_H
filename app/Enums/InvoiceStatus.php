<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partially paid',
            self::Paid => 'Paid',
        };
    }

    /**
     * Stamp text: DUE with no payments, PARTIALLY PAID with some, PAID when settled.
     */
    public function stamp(): string
    {
        return match ($this) {
            self::Unpaid => 'DUE',
            self::Partial => 'PARTIALLY PAID',
            self::Paid => 'PAID',
        };
    }

    /**
     * CSS modifier for the stamp colour (stamp-due, stamp-partial, stamp-paid).
     */
    public function stampClass(): string
    {
        return match ($this) {
            self::Unpaid => 'stamp-due',
            self::Partial => 'stamp-partial',
            self::Paid => 'stamp-paid',
        };
    }
}
