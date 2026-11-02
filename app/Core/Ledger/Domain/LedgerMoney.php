<?php

namespace App\Core\Ledger\Domain;

use InvalidArgumentException;
use OverflowException;

final readonly class LedgerMoney
{
    private const MAX_CENTS = 999999999999999999;

    private function __construct(private int $cents) {}

    public static function from(string|int $amount): self
    {
        $value = (string) $amount;

        if (! preg_match('/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Nominal harus positif dan menggunakan maksimal dua angka desimal.');
        }

        $whole = (int) $matches[1];
        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;
        $cents = ($whole * 100) + $fraction;

        if ($cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Nominal berada di luar rentang yang didukung.');
        }

        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        if ($other->cents > self::MAX_CENTS - $this->cents) {
            throw new OverflowException('Jumlah mutasi melebihi batas ledger.');
        }

        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        if ($other->cents > $this->cents) {
            throw new LedgerException('Saldo akun tidak mencukupi.');
        }

        return new self($this->cents - $other->cents);
    }

    public function compare(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function toDecimal(): string
    {
        return intdiv($this->cents, 100).'.'.str_pad((string) ($this->cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function toCents(): int
    {
        return $this->cents;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }
}
