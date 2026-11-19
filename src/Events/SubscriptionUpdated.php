<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Billing\Subscription;

/**
 * Local subscription state changed after talking to Mercado Pago.
 */
final readonly class SubscriptionUpdated
{
    /**
     * @param  list<string>  $changed  The attributes that changed.
     */
    public function __construct(
        public Subscription $subscription,
        public array $changed,
    ) {}
}
