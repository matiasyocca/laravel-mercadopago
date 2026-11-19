<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Exceptions\BillingException;

/**
 * Local mirror of a Mercado Pago subscription (preapproval).
 *
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $type
 * @property string|null $plan
 * @property string $preapproval_id
 * @property string|null $status
 * @property string|null $init_point
 * @property string|null $payer_email
 * @property string|null $amount
 * @property string|null $currency
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $next_payment_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Subscription extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_AUTHORIZED = 'authorized';

    public const string STATUS_PAUSED = 'paused';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $table = 'mercadopago_subscriptions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'trial_ends_at' => 'immutable_datetime',
            'next_payment_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether the subscription grants access right now.
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    public function active(): bool
    {
        return self::isActiveStatus($this->status);
    }

    public static function isActiveStatus(?string $status): bool
    {
        return in_array($status, self::statuses('active_statuses', [self::STATUS_AUTHORIZED]), true);
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at?->isFuture() === true
            && in_array($this->status, self::statuses('trial_statuses', [self::STATUS_AUTHORIZED, self::STATUS_PENDING]), true);
    }

    public function onGracePeriod(): bool
    {
        return $this->cancelled() && $this->ends_at?->isFuture() === true;
    }

    public function pending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function paused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function cancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function ended(): bool
    {
        return $this->cancelled() && ! $this->onGracePeriod();
    }

    /**
     * Cancel in Mercado Pago. Access continues until ends_at: the end of the
     * trial or of the period already paid for.
     */
    public function cancel(): static
    {
        if ($this->cancelled()) {
            return $this;
        }

        $preApproval = app(Gateway::class)->updateSubscription($this->preapproval_id, [
            'status' => self::STATUS_CANCELLED,
        ]);

        app(SyncSubscription::class)->apply($this, $preApproval);

        return $this;
    }

    /**
     * Move to another plan with the same billing cycle. Mercado Pago charges
     * the new amount from the next charge on, with no proration.
     */
    public function swap(string $plan): static
    {
        if ($this->cancelled()) {
            throw BillingException::cannotSwapCancelled();
        }

        $newPlan = Plan::find($plan);
        $gateway = app(Gateway::class);
        $current = $gateway->findSubscription($this->preapproval_id);

        if ($current->frequency !== null && $current->frequencyType !== null
            && ! $newPlan->billsEvery($current->frequency, $current->frequencyType)) {
            throw BillingException::incompatibleSwap($plan);
        }

        $preApproval = $gateway->updateSubscription($this->preapproval_id, [
            'reason' => $newPlan->reason,
            'auto_recurring' => [
                'transaction_amount' => $newPlan->amount,
                'currency_id' => $newPlan->currency,
            ],
        ]);

        $this->plan = $newPlan->key;

        app(SyncSubscription::class)->apply($this, $preApproval);

        return $this;
    }

    /**
     * Re-read the subscription from Mercado Pago.
     */
    public function syncFromMercadoPago(): static
    {
        app(SyncSubscription::class)->refresh($this);

        return $this;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function statuses(string $key, array $default): array
    {
        $statuses = config('mercadopago.billing.'.$key, $default);

        return is_array($statuses) ? array_values(array_filter($statuses, is_string(...))) : $default;
    }
}
