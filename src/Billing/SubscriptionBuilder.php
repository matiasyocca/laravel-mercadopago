<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Exceptions\BillingException;

/**
 * Creates a Mercado Pago subscription without an associated plan and
 * returns the checkout URL where the payer authorizes it.
 */
final class SubscriptionBuilder
{
    private ?string $payerEmail = null;

    private ?string $backUrl = null;

    private bool $withTrial = true;

    /**
     * @param  Model&Billable  $billable
     */
    public function __construct(
        private readonly Model $billable,
        private readonly string $type,
        private readonly Plan $plan,
    ) {}

    public function payerEmail(?string $email): self
    {
        $this->payerEmail = $email === '' ? null : $email;

        return $this;
    }

    /**
     * Where Mercado Pago sends the payer after the checkout.
     */
    public function backUrl(string $url): self
    {
        $this->backUrl = $url;

        return $this;
    }

    public function skipTrial(): self
    {
        $this->withTrial = false;

        return $this;
    }

    /**
     * Reuses a pending checkout for the same plan and payer email, replaces
     * a pending one for another plan or email (Mercado Pago only lets the
     * email it was created for pay it) or a paused one, and refuses when
     * already authorized.
     */
    public function checkout(): Checkout
    {
        $lock = 'mercadopago:checkout:'.$this->billable->mercadoPagoExternalReference().':'.$this->type;

        return Cache::lock($lock, 30)->block(10, fn (): Checkout => $this->createCheckout());
    }

    private function createCheckout(): Checkout
    {
        $current = $this->billable->subscription($this->type);
        $payerEmail = $this->payerEmail ?? $this->billable->mercadoPagoPayerEmail();
        $carriedTrialEndsAt = null;

        if ($current !== null && ! $current->cancelled()) {
            if ($current->pending() && $current->plan === $this->plan->key && $current->payer_email === $payerEmail && filled($current->init_point)) {
                return new Checkout($current, (string) $current->init_point);
            }

            if (! $current->pending() && ! $current->paused()) {
                throw BillingException::alreadySubscribed($this->type);
            }

            // Changing plan or payer email before finishing the checkout keeps the trial days left.
            $carriedTrialEndsAt = $current->onTrial() ? $current->trial_ends_at : null;

            $current->cancel();
        }

        $payerEmail ??= throw BillingException::missingPayerEmail();

        $withTrial = $this->withTrial && $this->plan->hasTrial() && ! $this->hadTrial();
        $autoRecurring = $this->plan->autoRecurring($withTrial);
        $trialEndsAt = $withTrial ? $this->plan->trialEndsAt(now()) : null;

        if ($this->withTrial && $carriedTrialEndsAt !== null) {
            $autoRecurring['free_trial'] = [
                'frequency' => max(1, (int) ceil(now()->diffInDays($carriedTrialEndsAt))),
                'frequency_type' => 'days',
            ];
            $trialEndsAt = $carriedTrialEndsAt;
        }

        $preApproval = app(Gateway::class)->createSubscription([
            'reason' => $this->plan->reason,
            'payer_email' => $payerEmail,
            'back_url' => $this->backUrl ?? $this->plan->backUrl ?? url('/'),
            'external_reference' => $this->billable->mercadoPagoExternalReference(),
            'status' => Subscription::STATUS_PENDING,
            'auto_recurring' => $autoRecurring,
        ], (string) Str::uuid());

        if ($preApproval->initPoint === null) {
            throw BillingException::missingCheckoutUrl();
        }

        $subscription = $this->billable->subscriptions()->make([
            'type' => $this->type,
            'plan' => $this->plan->key,
            'preapproval_id' => $preApproval->id,
            'payer_email' => $payerEmail,
            'trial_ends_at' => $trialEndsAt,
        ]);

        app(SyncSubscription::class)->apply($subscription, $preApproval);

        return new Checkout($subscription, $preApproval->initPoint);
    }

    /**
     * One free trial per billable, whatever the plan or type.
     */
    private function hadTrial(): bool
    {
        return $this->billable->subscriptions()->whereNotNull('trial_ends_at')->exists();
    }
}
