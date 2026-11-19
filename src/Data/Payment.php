<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Data;

use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MatiasYocca\MercadoPago\Support\Value;

/**
 * A Mercado Pago payment.
 */
final readonly class Payment
{
    /**
     * @param  array<string, mixed>  $attributes  The raw API response.
     */
    public function __construct(
        public int $id,
        public ?string $status,
        public ?string $statusDetail,
        public ?string $externalReference,
        public ?float $amount,
        public ?string $currency,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Value::int($data['id'] ?? null) ?? throw RequestFailed::missingId('payment'),
            status: Value::string($data['status'] ?? null),
            statusDetail: Value::string($data['status_detail'] ?? null),
            externalReference: Value::string($data['external_reference'] ?? null),
            amount: Value::float($data['transaction_amount'] ?? null),
            currency: Value::string($data['currency_id'] ?? null),
            attributes: $data,
        );
    }

    public function approved(): bool
    {
        return $this->status === 'approved';
    }
}
