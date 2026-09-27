<?php

namespace App\Support;

use Carbon\Carbon;

final class PartyStatementPresentation
{
    public static function type(string $value): string
    {
        $key = strtolower(trim($value));
        return [
            'advance' => 'ADV', 'customer advance receipt' => 'ADV', 'supplier advance payment' => 'ADV',
            'invoice' => 'INV', 'receipt' => 'RCT', 'payment' => 'PAY', 'refund' => 'RFD',
            'adjustment' => 'ADJ', 'advance adjustment' => 'ADJ', 'journal' => 'JRN',
            'credit note' => 'CN', 'debit note' => 'DN',
        ][$key] ?? (strlen($value) > 4 ? strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $value), 0, 4)) : strtoupper($value));
    }

    public static function date(?string $value): string
    {
        if (! $value) return '';
        try { return Carbon::parse($value)->format('d M Y'); } catch (\Throwable) { return $value; }
    }
}
