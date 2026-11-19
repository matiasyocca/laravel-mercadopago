<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Data;

use Carbon\CarbonImmutable;
use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MatiasYocca\MercadoPago\Support\Value;

/**
 * A Mercado Pago subscription (preapproval).
 */
final readonly class PreApproval
{
    /**
     * @param  array<string, mixed>  $attributes  The raw API response.
     */
    public function __construct(
        public string $id,
        public ?string $status,
        public ?string $initPoint,
        public ?string $externalReference,
        public ?string $payerEmail,
        public ?float $amount,
        public ?string $currency,
        public ?int $frequency,
        public ?string $frequencyType,
        public ?CarbonImmutable $nextPaymentAt,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Value::string($data['id'] ?? null) ?? throw RequestFailed::missingId('subscription'),
            status: Value::string($data['status'] ?? null),
            initPoint: Value::string($data['init_point'] ?? null),
            externalReference: Value::string($data['external_reference'] ?? null),
            payerEmail: Value::string($data['payer_email'] ?? null),
            amount: Value::float(data_get($data, 'auto_recurring.transaction_amount')),
            currency: Value::string(data_get($data, 'auto_recurring.currency_id')),
            frequency: Value::int(data_get($data, 'auto_recurring.frequency')),
            frequencyType: Value::string(data_get($data, 'auto_recurring.frequency_type')),
            nextPaymentAt: Value::date($data['next_payment_date'] ?? null),
            attributes: $data,
        );
    }
}
