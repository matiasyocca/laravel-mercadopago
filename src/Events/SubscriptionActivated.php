<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Billing\Subscription;

/**
 * The subscription moved into an active status (authorized by default): the payer completed the checkout.
 */
final readonly class SubscriptionActivated
{
    public function __construct(public Subscription $subscription) {}
}
