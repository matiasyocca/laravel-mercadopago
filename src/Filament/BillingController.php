<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Filament;

use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use MatiasYocca\MercadoPago\Billing\Billables;
use MatiasYocca\MercadoPago\Exceptions\BillingException;

/**
 * The panel's tenant billing route. Sends tenants without a usable
 * subscription to the Mercado Pago checkout, and subscribed tenants to
 * mercadopago.billing.manage_url or back to the panel.
 */
final class BillingController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $provider = Filament::getTenantBillingProvider();

        if (! $provider instanceof MercadoPagoBillingProvider) {
            throw BillingException::providerNotConfigured();
        }

        $tenant = Billables::ensure(Filament::getTenant());
        $panelUrl = Filament::getUrl($tenant) ?? url('/');
        $subscription = $tenant->subscription($provider->type);

        // A pending subscription is valid during its trial but still needs
        // the checkout, so only skip the checkout for the other states.
        if ($subscription?->valid() === true && ! $subscription->pending()) {
            $manageUrl = config('mercadopago.billing.manage_url');

            return redirect()->to(is_string($manageUrl) && $manageUrl !== '' ? $manageUrl : $panelUrl);
        }

        $userEmail = $request->user()?->getAttribute('email');

        return $tenant->newSubscription($provider->type, $provider->plan)
            ->payerEmail($tenant->mercadoPagoPayerEmail() ?? (is_string($userEmail) ? $userEmail : null))
            ->backUrl($panelUrl)
            ->checkout()
            ->redirect();
    }
}
