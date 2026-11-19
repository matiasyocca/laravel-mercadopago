<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Billing\Subscription;

/**
 * The subscription was cancelled. It keeps access until ends_at.
 */
final readonly class SubscriptionCancelled
{
    public function __construct(public Subscription $subscription) {}
}
