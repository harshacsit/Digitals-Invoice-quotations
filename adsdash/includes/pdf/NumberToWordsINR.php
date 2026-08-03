<?php

declare(strict_types=1);

/**
 * Convert numerical monetary amount to Indian Rupee (INR) words.
 *
 * Example:
 * 213391.20 -> "Rupees Two Lakh Thirteen Thousand Three Hundred Ninety-One and Twenty Paise Only"
 * 90000.00  -> "Rupees Ninety Thousand Only"
 * 0.00      -> "Rupees Zero Only"
 */
function convertNumberToWordsINR(float|int|string $amount): string
{
    $amount = (float) $amount;
    if ($amount < 0) {
        $amount = abs($amount);
    }

    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);

    // Handle exact 100 paise rounding edge-case
    if ($paise >= 100) {
        $rupees += 1;
        $paise = 0;
    }

    $rupeesWords = convertWholeNumberToWordsINR($rupees);
    $paiseWords = $paise > 0 ? convertWholeNumberToWordsINR($paise) : '';

    $result = 'Rupees ' . ($rupeesWords !== '' ? $rupeesWords : 'Zero');

    if ($paise > 0) {
        $result .= ' and ' . $paiseWords . ' Paise';
    }

    $result .= ' Only';

    return $result;
}

/**
 * Convert positive whole number to Indian numbering words (Crore, Lakh, Thousand, Hundred, Units)
 */
function convertWholeNumberToWordsINR(int $num): string
{
    if ($num === 0) {
        return '';
    }

    $units = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];

    $tens = [
        0 => '', 2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];

    $words = [];

    // Crores (10,000,000)
    if ($num >= 10000000) {
        $crores = (int) floor($num / 10000000);
        $words[] = convertWholeNumberToWordsINR($crores) . ' Crore';
        $num %= 10000000;
    }

    // Lakhs (100,000)
    if ($num >= 100000) {
        $lakhs = (int) floor($num / 100000);
        $words[] = convertWholeNumberToWordsINR($lakhs) . ' Lakh';
        $num %= 100000;
    }

    // Thousands (1,000)
    if ($num >= 1000) {
        $thousands = (int) floor($num / 1000);
        $words[] = convertWholeNumberToWordsINR($thousands) . ' Thousand';
        $num %= 1000;
    }

    // Hundreds (100)
    if ($num >= 100) {
        $hundreds = (int) floor($num / 100);
        $words[] = $units[$hundreds] . ' Hundred';
        $num %= 100;
    }

    // Tens and Units (1-99)
    if ($num > 0) {
        if ($num < 20) {
            $words[] = $units[$num];
        } else {
            $t = (int) floor($num / 10);
            $u = $num % 10;
            $words[] = $tens[$t] . ($u > 0 ? '-' . $units[$u] : '');
        }
    }

    return implode(' ', $words);
}
