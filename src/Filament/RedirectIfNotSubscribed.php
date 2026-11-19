<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Filament;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use MatiasYocca\MercadoPago\Billing\Billables;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied by Filament to tenant routes when the panel calls
 * requiresTenantSubscription().
 */
final class RedirectIfNotSubscribed
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $type = 'default'): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return $next($request);
        }

        $tenant = Billables::ensure($tenant);

        if ($tenant->subscribed($type)) {
            return $next($request);
        }

        $billingUrl = Filament::getTenantBillingUrl(tenant: $tenant);

        abort_if($billingUrl === null, 403);

        return redirect()->to($billingUrl);
    }
}
