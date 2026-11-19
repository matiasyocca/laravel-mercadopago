<?php

declare(strict_types=1);

namespace Tests\Feature;

use MatiasYocca\MercadoPago\Billing\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Team;
use Tests\TestCase;

final class SubscriptionStateTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('states')]
    public function test_access_rules(array $attributes, bool $valid, bool $onTrial, bool $onGracePeriod): void
    {
        $subscription = $this->subscription($attributes);

        $this->assertSame($valid, $subscription->valid(), 'valid()');
        $this->assertSame($onTrial, $subscription->onTrial(), 'onTrial()');
        $this->assertSame($onGracePeriod, $subscription->onGracePeriod(), 'onGracePeriod()');
        $this->assertSame($valid, $subscription->billable->subscribed());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, bool, bool}>
     */
    public static function states(): iterable
    {
        yield 'authorized' => [['status' => 'authorized'], true, false, false];
        yield 'authorized on trial' => [['status' => 'authorized', 'trial_ends_at' => '+3 days'], true, true, false];
        yield 'pending on trial' => [['status' => 'pending', 'trial_ends_at' => '+3 days'], true, true, false];
        yield 'pending after trial' => [['status' => 'pending', 'trial_ends_at' => '-1 day'], false, false, false];
        yield 'pending without trial' => [['status' => 'pending'], false, false, false];
        yield 'paused' => [['status' => 'paused', 'trial_ends_at' => '+3 days'], false, false, false];
        yield 'cancelled with time left' => [['status' => 'cancelled', 'ends_at' => '+5 days'], true, false, true];
        yield 'cancelled and ended' => [['status' => 'cancelled', 'ends_at' => '-1 minute'], false, false, false];
    }

    public function test_active_statuses_are_configurable(): void
    {
        config()->set('mercadopago.billing.active_statuses', ['authorized', 'paused']);

        $this->assertTrue($this->subscription(['status' => 'paused'])->valid());
    }

    public function test_pending_trials_can_be_disabled(): void
    {
        config()->set('mercadopago.billing.trial_statuses', ['authorized']);

        $this->assertFalse($this->subscription(['status' => 'pending', 'trial_ends_at' => '+3 days'])->valid());
    }

    public function test_subscribed_can_require_a_plan(): void
    {
        $team = $this->subscription(['status' => 'authorized', 'plan' => 'pro'])->billable;

        $this->assertTrue($team->subscribed('default', 'pro'));
        $this->assertFalse($team->subscribed('default', 'business'));
        $this->assertFalse($team->subscribed('addons'));
    }

    public function test_the_latest_subscription_of_a_type_wins(): void
    {
        $old = $this->subscription(['status' => 'cancelled', 'ends_at' => '-1 day']);
        $team = $old->billable;
        $team->subscriptions()->create(['preapproval_id' => 'newer', 'status' => 'authorized']);

        $this->assertSame('newer', $team->subscription()->preapproval_id);
        $this->assertTrue($team->subscribed());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscription(array $attributes): Subscription
    {
        $team = Team::create(['name' => 'Acme']);

        foreach (['trial_ends_at', 'ends_at'] as $date) {
            if (isset($attributes[$date])) {
                $attributes[$date] = now()->modify($attributes[$date]);
            }
        }

        return $team->subscriptions()->create([
            'preapproval_id' => 'preapproval_'.$team->getKey(),
            'plan' => 'pro',
            ...$attributes,
        ]);
    }
}
