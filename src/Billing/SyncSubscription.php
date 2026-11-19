<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Data\PreApproval;
use MatiasYocca\MercadoPago\Events\SubscriptionActivated;
use MatiasYocca\MercadoPago\Events\SubscriptionCancelled;
use MatiasYocca\MercadoPago\Events\SubscriptionCreated;
use MatiasYocca\MercadoPago\Events\SubscriptionPaymentReceived;
use MatiasYocca\MercadoPago\Events\SubscriptionUpdated;

/**
 * Stores Mercado Pago subscription state locally and fires the events.
 * Mercado Pago is the source of truth; this only ever copies from it.
 */
final readonly class SyncSubscription
{
    public function __construct(
        private Gateway $gateway,
        private Dispatcher $events,
    ) {}

    /**
     * Fetch a subscription by its Mercado Pago id and store it. Unknown ids
     * are adopted through external_reference; null when no billable matches.
     */
    public function fromPreApprovalId(string $preApprovalId): ?Subscription
    {
        $preApproval = $this->gateway->findSubscription($preApprovalId);

        $subscription = Subscription::query()->where('preapproval_id', $preApproval->id)->first()
            ?? $this->adopt($preApproval);

        return $subscription === null ? null : $this->apply($subscription, $preApproval);
    }

    /**
     * Handle a charge notification: refresh its subscription, then fire
     * SubscriptionPaymentReceived.
     */
    public function fromAuthorizedPaymentId(int $authorizedPaymentId): ?Subscription
    {
        $payment = $this->gateway->findAuthorizedPayment($authorizedPaymentId);

        $subscription = $payment->preApprovalId === null
            ? null
            : $this->fromPreApprovalId($payment->preApprovalId);

        $this->events->dispatch(new SubscriptionPaymentReceived($payment, $subscription));

        return $subscription;
    }

    public function refresh(Subscription $subscription): Subscription
    {
        return $this->apply($subscription, $this->gateway->findSubscription($subscription->preapproval_id));
    }

    /**
     * Copy Mercado Pago state onto the row, save it and fire events.
     */
    public function apply(Subscription $subscription, PreApproval $preApproval): Subscription
    {
        $previousStatus = $subscription->exists ? $subscription->getOriginal('status') : null;

        $subscription->forceFill([
            'preapproval_id' => $preApproval->id,
            'status' => $preApproval->status ?? $subscription->status,
            'init_point' => $preApproval->initPoint ?? $subscription->init_point,
            'amount' => $preApproval->amount ?? $subscription->amount,
            'currency' => $preApproval->currency ?? $subscription->currency,
            'next_payment_at' => $preApproval->nextPaymentAt ?? $subscription->next_payment_at,
        ]);

        if ($subscription->cancelled() && $subscription->ends_at === null) {
            $subscription->ends_at = $this->accessEndsAt($subscription);
        }

        $changed = array_keys(Arr::except($subscription->getDirty(), ['synced_at', 'updated_at']));

        $subscription->synced_at = CarbonImmutable::now();
        $subscription->save();

        $this->dispatchEvents($subscription, $previousStatus, $changed);

        return $subscription;
    }

    /**
     * @param  list<string>  $changed
     */
    private function dispatchEvents(Subscription $subscription, mixed $previousStatus, array $changed): void
    {
        if ($subscription->wasRecentlyCreated) {
            $this->events->dispatch(new SubscriptionCreated($subscription));
        } elseif ($changed !== []) {
            $this->events->dispatch(new SubscriptionUpdated($subscription, $changed));
        }

        if ($subscription->status === $previousStatus) {
            return;
        }

        if ($subscription->active() && ! Subscription::isActiveStatus(is_string($previousStatus) ? $previousStatus : null)) {
            $this->events->dispatch(new SubscriptionActivated($subscription));
        }

        if ($subscription->cancelled()) {
            $this->events->dispatch(new SubscriptionCancelled($subscription));
        }
    }

    /**
     * A cancelled subscription keeps access until the later of the trial end
     * and the next charge date (the end of the period already paid for).
     */
    private function accessEndsAt(Subscription $subscription): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        $candidates = array_filter(
            [$subscription->trial_ends_at, $subscription->next_payment_at],
            fn (?CarbonImmutable $date): bool => $date !== null && $date->isAfter($now),
        );

        return $candidates === [] ? $now : max($candidates);
    }

    private function adopt(PreApproval $preApproval): ?Subscription
    {
        $billable = Billables::fromExternalReference($preApproval->externalReference);

        if ($billable === null) {
            return null;
        }

        return $billable->subscriptions()->make([
            'type' => 'default',
            'plan' => null,
            'preapproval_id' => $preApproval->id,
        ]);
    }
}
