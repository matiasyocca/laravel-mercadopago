<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add to the model that pays: usually the Filament tenant.
 *
 * @mixin Model
 */
trait Billable
{
    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable')->orderByDesc('id');
    }

    /**
     * The latest subscription of the given type.
     */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->subscriptions()->where('type', $type)->first();
    }

    /**
     * Whether the billable has access: active, on trial, or cancelled but
     * still inside the period it paid for.
     */
    public function subscribed(string $type = 'default', ?string $plan = null): bool
    {
        $subscription = $this->subscription($type);

        if ($subscription === null || ! $subscription->valid()) {
            return false;
        }

        return $plan === null || $subscription->plan === $plan;
    }

    public function onTrial(string $type = 'default'): bool
    {
        return $this->subscription($type)?->onTrial() ?? false;
    }

    /**
     * Start building a Mercado Pago checkout for a plan from config.
     */
    public function newSubscription(string $type, string $plan): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type, Plan::find($plan));
    }

    /**
     * The email Mercado Pago bills. Override when it lives elsewhere.
     */
    public function mercadoPagoPayerEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Sent as external_reference so webhooks can find the billable.
     */
    public function mercadoPagoExternalReference(): string
    {
        return $this->getMorphClass().':'.$this->getKey();
    }
}
