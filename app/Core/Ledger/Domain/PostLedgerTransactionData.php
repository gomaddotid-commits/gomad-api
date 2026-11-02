<?php

namespace App\Core\Ledger\Domain;

use DateTimeInterface;

final readonly class PostLedgerTransactionData
{
    /**
     * @param  list<LedgerEntryInput>  $entries
     */
    public function __construct(
        public LedgerCategory $category,
        public string $idempotencyKey,
        public array $entries,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?string $description = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?int $createdBy = null,
        public ?int $reversalOf = null,
    ) {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('Kunci idempotensi wajib diisi dan maksimal 128 karakter.');
        }
    }
}
