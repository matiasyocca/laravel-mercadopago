<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Facades;

use Illuminate\Support\Facades\Facade;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Testing\FakeGateway;

/**
 * @method static \MatiasYocca\MercadoPago\Data\Preference createPreference(array<string, mixed> $payload, ?string $idempotencyKey = null)
 * @method static \MatiasYocca\MercadoPago\Data\Payment findPayment(int $id)
 * @method static \MatiasYocca\MercadoPago\Data\PreApproval createSubscription(array<string, mixed> $payload, ?string $idempotencyKey = null)
 * @method static \MatiasYocca\MercadoPago\Data\PreApproval findSubscription(string $id)
 * @method static \MatiasYocca\MercadoPago\Data\PreApproval updateSubscription(string $id, array<string, mixed> $payload)
 * @method static \MatiasYocca\MercadoPago\Data\AuthorizedPayment findAuthorizedPayment(int $id)
 *
 * @see Gateway
 */
final class MercadoPago extends Facade
{
    /**
     * Replace the gateway with an in-memory fake for the rest of the test.
     */
    public static function fake(): FakeGateway
    {
        self::swap($fake = new FakeGateway);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Gateway::class;
    }
}
