<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Billing\Subscription;
use MatiasYocca\MercadoPago\Data\AuthorizedPayment;

/**
 * Mercado Pago reported a charge for a subscription. Check $payment->status:
 * it fires for scheduled, processed and rejected charges alike.
 */
final readonly class SubscriptionPaymentReceived
{
    public function __construct(
        public AuthorizedPayment $payment,
        public ?Subscription $subscription,
    ) {}
}
