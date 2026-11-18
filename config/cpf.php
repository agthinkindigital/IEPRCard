<?php
declare(strict_types=1);

function cpfNormalizeBr(mixed $value): ?string
{
    if ($value === null || is_string($value) && trim($value) === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('CPF_INVALID');
    }
    $digits = preg_replace('/[.\-\/\s]/', '', trim($value));
    if (!is_string($digits) || preg_match('/^[0-9]{11}$/D', $digits) !== 1 || !cpfValidateBr($digits)) {
        throw new InvalidArgumentException('CPF_INVALID');
    }

    return $digits;
}

function cpfValidateBr(string $cpf): bool
{
    if (preg_match('/^([0-9])\1{10}$/D', $cpf) === 1) {
        return false;
    }
    for ($position = 9; $position <= 10; ++$position) {
        $sum = 0;
        for ($index = 0; $index < $position; ++$index) {
            $sum += ((int) $cpf[$index]) * ($position + 1 - $index);
        }
        $remainder = $sum % 11;
        $digit = $remainder < 2 ? 0 : 11 - $remainder;
        if ((int) $cpf[$position] !== $digit) {
            return false;
        }
    }

    return true;
}
