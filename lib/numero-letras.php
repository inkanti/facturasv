<?php

declare(strict_types=1);

function amountToSpanishWords(float $amount): string
{
    $amount = round($amount, 2);
    $integer = (int) floor($amount);
    $cents = (int) round(($amount - $integer) * 100);
    return numberToSpanishWords($integer) . ' CON ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100 USD';
}

function numberToSpanishWords(int $number): string
{
    if ($number === 0) return 'CERO';
    if ($number < 0) return 'MENOS ' . numberToSpanishWords(abs($number));
    if ($number < 100) return spanishBelow100($number);
    if ($number < 1000) {
        if ($number === 100) return 'CIEN';
        $hundreds = [1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS'];
        return trim($hundreds[intdiv($number, 100)] . ' ' . numberToSpanishWords($number % 100));
    }
    if ($number < 1000000) {
        $thousands = intdiv($number, 1000);
        $prefix = $thousands === 1 ? 'MIL' : numberToSpanishWords($thousands) . ' MIL';
        return trim($prefix . ' ' . numberToSpanishWords($number % 1000));
    }
    if ($number < 1000000000) {
        $millions = intdiv($number, 1000000);
        $prefix = $millions === 1 ? 'UN MILLÓN' : numberToSpanishWords($millions) . ' MILLONES';
        return trim($prefix . ' ' . numberToSpanishWords($number % 1000000));
    }
    $billions = intdiv($number, 1000000000);
    $prefix = $billions === 1 ? 'MIL MILLONES' : numberToSpanishWords($billions) . ' MIL MILLONES';
    return trim($prefix . ' ' . numberToSpanishWords($number % 1000000000));
}

function spanishBelow100(int $number): string
{
    $words = [
        0 => '', 1 => 'UNO', 2 => 'DOS', 3 => 'TRES', 4 => 'CUATRO', 5 => 'CINCO', 6 => 'SEIS', 7 => 'SIETE', 8 => 'OCHO', 9 => 'NUEVE',
        10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE', 16 => 'DIECISÉIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE',
        20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDÓS', 23 => 'VEINTITRÉS', 24 => 'VEINTICUATRO', 25 => 'VEINTICINCO', 26 => 'VEINTISÉIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE',
    ];
    if (isset($words[$number])) return $words[$number];
    $tens = [30 => 'TREINTA', 40 => 'CUARENTA', 50 => 'CINCUENTA', 60 => 'SESENTA', 70 => 'SETENTA', 80 => 'OCHENTA', 90 => 'NOVENTA'];
    $ten = intdiv($number, 10) * 10;
    return $tens[$ten] . ($number % 10 ? ' Y ' . $words[$number % 10] : '');
}
