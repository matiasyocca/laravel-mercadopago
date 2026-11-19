<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Webhooks;

enum WebhookTopic: string
{
    case Payment = 'payment';
    case Subscription = 'subscription_preapproval';
    case SubscriptionPayment = 'subscription_authorized_payment';
    case SubscriptionPlan = 'subscription_preapproval_plan';
}
