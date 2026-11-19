<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Listeners;

use MatiasYocca\MercadoPago\Billing\SyncSubscription;
use MatiasYocca\MercadoPago\Events\WebhookReceived;
use MatiasYocca\MercadoPago\Support\Value;
use MatiasYocca\MercadoPago\Webhooks\WebhookTopic;

/**
 * Re-fetches subscriptions named in webhooks and stores their state.
 */
final readonly class SyncSubscriptionFromWebhook
{
    public function __construct(private SyncSubscription $sync) {}

    public function handle(WebhookReceived $event): void
    {
        $notification = $event->notification;

        if ($notification->resourceId === null) {
            return;
        }

        if ($notification->is(WebhookTopic::Subscription)) {
            $this->sync->fromPreApprovalId($notification->resourceId);

            return;
        }

        $authorizedPaymentId = Value::int($notification->resourceId);

        if ($notification->is(WebhookTopic::SubscriptionPayment) && $authorizedPaymentId !== null) {
            $this->sync->fromAuthorizedPaymentId($authorizedPaymentId);
        }
    }
}
