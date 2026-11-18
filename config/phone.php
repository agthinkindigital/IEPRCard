<?php
declare(strict_types=1);

function phoneNormalizeBr(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits)) {
        throw new InvalidArgumentException('PHONE_INVALID');
    }
    if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
        $digits = substr($digits, 2);
    }
    if (!in_array(strlen($digits), [10, 11], true)
        || preg_match('/^[1-9][0-9][0-9]{8,9}$/D', $digits) !== 1
        || strlen($digits) === 11 && $digits[2] !== '9') {
        throw new InvalidArgumentException('PHONE_INVALID');
    }

    return '+55' . $digits;
}
