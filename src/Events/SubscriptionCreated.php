<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Billing\Subscription;

/**
 * A subscription row was created, either at checkout or adopted from a webhook.
 */
final readonly class SubscriptionCreated
{
    public function __construct(public Subscription $subscription) {}
}
