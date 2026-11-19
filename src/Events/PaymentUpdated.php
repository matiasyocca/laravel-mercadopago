<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Data\Payment;
use MatiasYocca\MercadoPago\Webhooks\WebhookNotification;

/**
 * Fired after a "payment" webhook, with the payment fetched from the API.
 * The package only fetches it when something listens to this event.
 */
final readonly class PaymentUpdated
{
    public function __construct(
        public Payment $payment,
        public WebhookNotification $notification,
    ) {}
}
