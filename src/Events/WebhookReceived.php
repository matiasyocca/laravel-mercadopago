<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Events;

use MatiasYocca\MercadoPago\Webhooks\WebhookNotification;

/**
 * Fired for every verified webhook, whatever its topic.
 */
final readonly class WebhookReceived
{
    public function __construct(public WebhookNotification $notification) {}
}
