<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Data;

use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MatiasYocca\MercadoPago\Support\Value;

/**
 * A charge Mercado Pago made for a subscription (an "authorized payment" or invoice).
 */
final readonly class AuthorizedPayment
{
    /**
     * @param  array<string, mixed>  $attributes  The raw API response.
     */
    public function __construct(
        public int $id,
        public ?string $preApprovalId,
        public ?string $status,
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
            id: Value::int($data['id'] ?? null) ?? throw RequestFailed::missingId('authorized payment'),
            preApprovalId: Value::string($data['preapproval_id'] ?? null),
            status: Value::string($data['status'] ?? null),
            amount: Value::float($data['transaction_amount'] ?? null),
            currency: Value::string($data['currency_id'] ?? null),
            attributes: $data,
        );
    }
}
