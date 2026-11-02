<?php

namespace App\Core\Identity\Domain;

use InvalidArgumentException;

final readonly class PhoneNumber
{
    private function __construct(public string $value) {}

    public static function fromInput(string $input): self
    {
        $digits = preg_replace('/[\s().-]+/', '', trim($input));

        if (! is_string($digits)) {
            throw new InvalidArgumentException('Nomor telepon tidak valid.');
        }

        if (str_starts_with($digits, '0')) {
            $digits = '+62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '62')) {
            $digits = '+'.$digits;
        } elseif (str_starts_with($digits, '8')) {
            $digits = '+62'.$digits;
        }

        if (! preg_match('/^\+62[0-9]{8,13}$/', $digits)) {
            throw new InvalidArgumentException('Gunakan nomor telepon Indonesia yang valid.');
        }

        return new self($digits);
    }
}
