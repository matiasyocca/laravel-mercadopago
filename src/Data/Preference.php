<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Data;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MatiasYocca\MercadoPago\Support\Value;

/**
 * A Checkout Pro preference. Return it from a controller to redirect the buyer.
 */
final readonly class Preference implements Responsable
{
    /**
     * @param  array<string, mixed>  $attributes  The raw API response.
     */
    public function __construct(
        public string $id,
        public string $initPoint,
        public ?string $sandboxInitPoint,
        public ?string $externalReference,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Value::string($data['id'] ?? null) ?? throw RequestFailed::missingId('preference'),
            initPoint: Value::string($data['init_point'] ?? null) ?? throw new RequestFailed('Mercado Pago returned a preference without an init_point.'),
            sandboxInitPoint: Value::string($data['sandbox_init_point'] ?? null),
            externalReference: Value::string($data['external_reference'] ?? null),
            attributes: $data,
        );
    }

    public function redirect(): RedirectResponse
    {
        return redirect()->away($this->initPoint);
    }

    public function toResponse($request): RedirectResponse
    {
        return $this->redirect();
    }
}
