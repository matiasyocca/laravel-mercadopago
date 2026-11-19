<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use MatiasYocca\MercadoPago\Billing\Subscription;
use MatiasYocca\MercadoPago\Events\SubscriptionCancelled;
use MatiasYocca\MercadoPago\Events\SubscriptionCreated;
use MatiasYocca\MercadoPago\Exceptions\BillingException;
use MatiasYocca\MercadoPago\Facades\MercadoPago;
use Tests\Fixtures\Team;
use Tests\TestCase;

final class CheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-18 12:00:00');
    }

    public function test_it_creates_a_pending_subscription_with_the_plan_trial(): void
    {
        Event::fake([SubscriptionCreated::class]);
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        $checkout = $team->newSubscription('default', 'pro')
            ->backUrl('https://app.acme.test/app/1')
            ->checkout();

        $mercadoPago->assertSubscriptionCreated(fn (array $payload, ?string $idempotencyKey): bool => $payload === [
            'reason' => 'Acme Pro',
            'payer_email' => 'billing@acme.test',
            'back_url' => 'https://app.acme.test/app/1',
            'external_reference' => $team->getMorphClass().':'.$team->getKey(),
            'status' => 'pending',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => 12000.0,
                'currency_id' => 'ARS',
                'free_trial' => ['frequency' => 14, 'frequency_type' => 'days'],
            ],
        ] && $idempotencyKey !== null);

        $subscription = $checkout->subscription;

        $this->assertSame($checkout->url, $subscription->init_point);
        $this->assertSame('billing@acme.test', $subscription->payer_email);
        $this->assertSame('pro', $subscription->plan);
        $this->assertSame('default', $subscription->type);
        $this->assertSame('pending', $subscription->status);
        $this->assertSame('12000.00', $subscription->amount);
        $this->assertSame('ARS', $subscription->currency);
        $this->assertTrue($subscription->trial_ends_at->equalTo(CarbonImmutable::now()->addDays(14)));
        $this->assertTrue($team->subscribed());
        $this->assertTrue($team->onTrial());
        Event::assertDispatched(SubscriptionCreated::class, fn (SubscriptionCreated $event): bool => $event->subscription->is($subscription));
    }

    public function test_it_reuses_a_pending_checkout_for_the_same_plan(): void
    {
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        $first = $team->newSubscription('default', 'pro')->checkout();
        $second = $team->newSubscription('default', 'pro')->checkout();

        $mercadoPago->assertSubscriptionCreatedTimes(1);
        $this->assertSame($first->url, $second->url);
        $this->assertSame(1, $team->subscriptions()->count());
    }

    public function test_changing_plan_before_paying_replaces_the_checkout_and_keeps_the_trial(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        $first = $team->newSubscription('default', 'pro')->checkout();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(4));
        $second = $team->newSubscription('default', 'business')->checkout();

        $this->assertSame('cancelled', $first->subscription->refresh()->status);
        $mercadoPago->assertSubscriptionUpdated($first->subscription->preapproval_id, fn (array $payload): bool => $payload === ['status' => 'cancelled']);
        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => $payload['reason'] === 'Acme Business'
            && $payload['auto_recurring']['free_trial'] === ['frequency' => 10, 'frequency_type' => 'days']);
        $this->assertTrue($second->subscription->trial_ends_at->equalTo($first->subscription->trial_ends_at));
        $this->assertSame('business', $team->subscription()->plan);
        Event::assertDispatched(SubscriptionCancelled::class);
    }

    public function test_changing_payer_email_before_paying_replaces_the_checkout_and_keeps_the_trial(): void
    {
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        $first = $team->newSubscription('default', 'pro')->payerEmail('typo@acme.test')->checkout();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(4));
        $second = $team->newSubscription('default', 'pro')->payerEmail('owner@acme.test')->checkout();

        $this->assertNotSame($first->url, $second->url);
        $this->assertSame('cancelled', $first->subscription->refresh()->status);
        $mercadoPago->assertSubscriptionCreatedTimes(2);
        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => $payload['payer_email'] === 'owner@acme.test'
            && $payload['auto_recurring']['free_trial'] === ['frequency' => 10, 'frequency_type' => 'days']);
        $this->assertSame('owner@acme.test', $team->subscription()->payer_email);
        $this->assertTrue($second->subscription->trial_ends_at->equalTo($first->subscription->trial_ends_at));

        // The same email again finds the new checkout.
        $third = $team->newSubscription('default', 'pro')->payerEmail('owner@acme.test')->checkout();

        $this->assertSame($second->url, $third->url);
        $mercadoPago->assertSubscriptionCreatedTimes(2);
    }

    public function test_it_refuses_a_second_checkout_when_already_authorized(): void
    {
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);
        $team->newSubscription('default', 'pro')->checkout()->subscription->update(['status' => 'authorized']);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('already has an authorized [default] subscription');

        try {
            $team->newSubscription('default', 'business')->checkout();
        } finally {
            $mercadoPago->assertSubscriptionCreatedTimes(1);
        }
    }

    public function test_a_billable_only_gets_one_free_trial(): void
    {
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);
        $team->newSubscription('default', 'pro')->checkout()->subscription->update([
            'status' => 'cancelled',
            'trial_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ]);

        $checkout = $team->newSubscription('default', 'pro')->checkout();

        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => ! isset($payload['auto_recurring']['free_trial']));
        $this->assertNull($checkout->subscription->trial_ends_at);
        $this->assertFalse($team->subscribed());
    }

    public function test_skip_trial_starts_billing_right_away(): void
    {
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        $checkout = $team->newSubscription('default', 'pro')->skipTrial()->checkout();

        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => ! isset($payload['auto_recurring']['free_trial']));
        $this->assertNull($checkout->subscription->trial_ends_at);
    }

    public function test_a_payer_email_is_required(): void
    {
        $mercadoPago = MercadoPago::fake();
        $team = Team::create(['name' => 'No email']);

        try {
            $team->newSubscription('default', 'pro')->checkout();
            $this->fail('Expected a BillingException.');
        } catch (BillingException $exception) {
            $this->assertStringContainsString('payer email is required', $exception->getMessage());
        }

        $mercadoPago->assertNothingCreated();

        $team->newSubscription('default', 'pro')->payerEmail('owner@acme.test')->checkout();
        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => $payload['payer_email'] === 'owner@acme.test');
    }

    public function test_subscription_types_are_independent(): void
    {
        MercadoPago::fake();
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);

        $team->newSubscription('default', 'pro')->checkout()->subscription->update(['status' => 'authorized']);
        $team->newSubscription('addons', 'business')->checkout();

        $this->assertSame('authorized', $team->subscription('default')->status);
        $this->assertSame('pending', $team->subscription('addons')->status);
        $this->assertSame(2, Subscription::query()->count());
    }
}
