<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Events\PaymentUpdated;
use MatiasYocca\MercadoPago\Events\WebhookReceived;
use MatiasYocca\MercadoPago\Support\Value;
use MatiasYocca\MercadoPago\Webhooks\WebhookTopic;

/**
 * Turns "payment" webhooks into PaymentUpdated events, but only fetches the
 * payment when the app listens to PaymentUpdated.
 */
final readonly class FetchUpdatedPayment
{
    public function __construct(
        private Gateway $gateway,
        private Dispatcher $events,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $notification = $event->notification;
        $paymentId = Value::int($notification->resourceId);

        if (! $notification->is(WebhookTopic::Payment) || $paymentId === null) {
            return;
        }

        if (! $this->events->hasListeners(PaymentUpdated::class)) {
            return;
        }

        $this->events->dispatch(new PaymentUpdated($this->gateway->findPayment($paymentId), $notification));
    }
}
