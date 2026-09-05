<?php

namespace App\Support\Accounting;

final class AmountInWords
{
    private const ONES = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen',
        17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
    ];

    private const TENS = [
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
    ];

    public static function money(float $amount, string $currency = 'PKR'): string
    {
        $amount = round(max(0, $amount), 2);
        $whole = (int) floor($amount);
        $fraction = (int) round(($amount - $whole) * 100);

        $currencyLabel = strtoupper($currency) === 'PKR'
            ? 'Pakistani Rupees'
            : strtoupper($currency);

        $text = $currencyLabel.' '.self::integer($whole);

        if ($fraction > 0) {
            $text .= ' and '.self::integer($fraction).' Paisa';
        }

        return trim($text).' Only';
    }

    public static function integer(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        if ($number < 100) {
            $tens = intdiv($number, 10) * 10;
            $rest = $number % 10;
            return self::TENS[$tens].($rest ? ' '.self::ONES[$rest] : '');
        }

        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $rest = $number % 100;
            return self::ONES[$hundreds].' Hundred'.($rest ? ' '.self::integer($rest) : '');
        }

        $groups = [
            10000000 => 'Crore',
            100000 => 'Lakh',
            1000 => 'Thousand',
        ];

        foreach ($groups as $value => $label) {
            if ($number >= $value) {
                $head = intdiv($number, $value);
                $rest = $number % $value;
                return self::integer($head).' '.$label.($rest ? ' '.self::integer($rest) : '');
            }
        }

        return (string) $number;
    }
}
