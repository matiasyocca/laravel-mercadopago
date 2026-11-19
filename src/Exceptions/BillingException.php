<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Exceptions;

use MatiasYocca\MercadoPago\Billing\Billable;

final class BillingException extends MercadoPagoException
{
    public static function unknownPlan(string $plan): self
    {
        return new self(sprintf('The Mercado Pago plan [%s] is not defined in mercadopago.billing.plans.', $plan));
    }

    public static function invalidPlan(string $plan, string $reason): self
    {
        return new self(sprintf('The Mercado Pago plan [%s] is invalid: %s', $plan, $reason));
    }

    public static function missingPayerEmail(): self
    {
        return new self('A payer email is required to start a Mercado Pago subscription. Pass one with payerEmail() or override mercadoPagoPayerEmail().');
    }

    public static function missingCheckoutUrl(): self
    {
        return new self('Mercado Pago did not return a checkout URL (init_point) for the subscription.');
    }

    public static function alreadySubscribed(string $type): self
    {
        return new self(sprintf('The billable already has an authorized [%s] subscription. Use swap() to change its plan.', $type));
    }

    public static function cannotSwapCancelled(): self
    {
        return new self('A cancelled subscription cannot change plans. Start a new checkout instead.');
    }

    public static function incompatibleSwap(string $plan): self
    {
        return new self(sprintf('Mercado Pago cannot change the billing frequency of a subscription; [%s] bills on a different cycle. Cancel and start a new checkout instead.', $plan));
    }

    public static function notBillable(mixed $model): self
    {
        return new self(sprintf(
            '[%s] must use the %s trait to be billed with Mercado Pago.',
            get_debug_type($model),
            Billable::class,
        ));
    }

    public static function providerNotConfigured(): self
    {
        return new self('The current Filament panel does not use the Mercado Pago billing provider.');
    }
}
