<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use MatiasYocca\MercadoPago\Billing\Subscription;
use MatiasYocca\MercadoPago\Exceptions\BillingException;
use MatiasYocca\MercadoPago\Facades\MercadoPago;
use MatiasYocca\MercadoPago\Testing\FakeGateway;
use Tests\Fixtures\Team;
use Tests\TestCase;

final class CancelAndSwapTest extends TestCase
{
    private FakeGateway $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-18 12:00:00');
        $this->mercadoPago = MercadoPago::fake();
    }

    public function test_cancelling_keeps_access_until_the_paid_period_ends(): void
    {
        $subscription = $this->authorizedSubscription(nextPaymentAt: now()->addDays(20));

        $subscription->cancel();

        $this->mercadoPago->assertSubscriptionUpdated('preapproval_live', fn (array $payload): bool => $payload === ['status' => 'cancelled']);
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(20)));
        $this->assertTrue($subscription->billable->subscribed());

        CarbonImmutable::setTestNow(now()->addDays(21));

        $this->assertFalse($subscription->billable->subscribed());
        $this->assertTrue($subscription->refresh()->ended());
    }

    public function test_cancelling_during_a_trial_keeps_the_trial(): void
    {
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);
        $subscription = $team->newSubscription('default', 'pro')->checkout()->subscription;

        $subscription->cancel();

        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(14)));
        $this->assertTrue($team->subscribed());
    }

    public function test_cancelling_twice_does_not_call_mercado_pago_again(): void
    {
        $subscription = $this->authorizedSubscription()->cancel()->cancel();

        $this->assertCount(1, $this->mercadoPago->calls('subscriptions.update'));
        $this->assertTrue($subscription->cancelled());
    }

    public function test_swapping_updates_the_amount_and_plan(): void
    {
        $subscription = $this->authorizedSubscription()->swap('business');

        $this->mercadoPago->assertSubscriptionUpdated('preapproval_live', fn (array $payload): bool => $payload === [
            'reason' => 'Acme Business',
            'auto_recurring' => ['transaction_amount' => 25000.0, 'currency_id' => 'ARS'],
        ]);
        $this->assertSame('business', $subscription->refresh()->plan);
        $this->assertSame('25000.00', $subscription->amount);
    }

    public function test_swapping_to_another_billing_cycle_is_refused(): void
    {
        $subscription = $this->authorizedSubscription();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('different cycle');

        $subscription->swap('yearly');
    }

    public function test_swapping_a_cancelled_subscription_is_refused(): void
    {
        $subscription = $this->authorizedSubscription()->cancel();

        $this->expectException(BillingException::class);

        $subscription->swap('business');
    }

    private function authorizedSubscription(?CarbonInterface $nextPaymentAt = null): Subscription
    {
        $this->mercadoPago->addSubscription([
            'id' => 'preapproval_live',
            'status' => 'authorized',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => 12000,
                'currency_id' => 'ARS',
            ],
            'next_payment_date' => ($nextPaymentAt ?? now()->addMonth())->toIso8601String(),
        ]);

        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        return $team->subscriptions()->create([
            'preapproval_id' => 'preapproval_live',
            'plan' => 'pro',
            'status' => 'authorized',
            'next_payment_at' => $nextPaymentAt ?? now()->addMonth(),
        ]);
    }
}
