<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Filament;

use Filament\Billing\Providers\Contracts\BillingProvider;

/**
 * Filament tenant billing backed by Mercado Pago subscriptions.
 *
 *     $panel
 *         ->tenant(Workshop::class)
 *         ->tenantBillingProvider(new MercadoPagoBillingProvider(plan: 'pro'))
 *         ->requiresTenantSubscription();
 */
final readonly class MercadoPagoBillingProvider implements BillingProvider
{
    public function __construct(
        public string $plan,
        public string $type = 'default',
    ) {}

    /**
     * A controller, not a closure, so `route:cache` keeps working.
     */
    public function getRouteAction(): string
    {
        return BillingController::class;
    }

    public function getSubscribedMiddleware(): string
    {
        return RedirectIfNotSubscribed::class.':'.$this->type;
    }
}
