<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;

/**
 * Where to send the payer to authorize a subscription. Return it from a
 * controller to redirect.
 */
final readonly class Checkout implements Responsable
{
    public function __construct(
        public Subscription $subscription,
        public string $url,
    ) {}

    public function redirect(): RedirectResponse
    {
        return redirect()->away($this->url);
    }

    public function toResponse($request): RedirectResponse
    {
        return $this->redirect();
    }
}
