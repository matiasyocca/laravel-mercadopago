<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Contracts;

use MatiasYocca\MercadoPago\Data\AuthorizedPayment;
use MatiasYocca\MercadoPago\Data\Payment;
use MatiasYocca\MercadoPago\Data\PreApproval;
use MatiasYocca\MercadoPago\Data\Preference;

/**
 * The Mercado Pago API calls the package makes. Bound to the SDK in
 * production and to Testing\FakeGateway by MercadoPago::fake().
 */
interface Gateway
{
    /**
     * Create a Checkout Pro preference.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createPreference(array $payload, ?string $idempotencyKey = null): Preference;

    public function findPayment(int $id): Payment;

    /**
     * Create a subscription (preapproval) without an associated plan.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createSubscription(array $payload, ?string $idempotencyKey = null): PreApproval;

    public function findSubscription(string $id): PreApproval;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateSubscription(string $id, array $payload): PreApproval;

    public function findAuthorizedPayment(int $id): AuthorizedPayment;
}
