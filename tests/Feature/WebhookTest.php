<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use MatiasYocca\MercadoPago\Billing\Subscription;
use MatiasYocca\MercadoPago\Events\PaymentUpdated;
use MatiasYocca\MercadoPago\Events\SubscriptionActivated;
use MatiasYocca\MercadoPago\Events\SubscriptionCancelled;
use MatiasYocca\MercadoPago\Events\SubscriptionCreated;
use MatiasYocca\MercadoPago\Events\SubscriptionPaymentReceived;
use MatiasYocca\MercadoPago\Events\SubscriptionUpdated;
use MatiasYocca\MercadoPago\Events\WebhookReceived;
use MatiasYocca\MercadoPago\Facades\MercadoPago;
use MatiasYocca\MercadoPago\Testing\FakeGateway;
use Tests\Fixtures\Team;
use Tests\Fixtures\Workspace;
use Tests\TestCase;

final class WebhookTest extends TestCase
{
    private FakeGateway $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mercadoPago = MercadoPago::fake();
    }

    public function test_a_subscription_webhook_syncs_the_status_from_the_api(): void
    {
        Event::fake([SubscriptionActivated::class, SubscriptionUpdated::class, SubscriptionCancelled::class]);
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);
        $subscription = $team->newSubscription('default', 'pro')->checkout()->subscription;
        $this->mercadoPago->addSubscription(['id' => $subscription->preapproval_id, 'status' => 'authorized']);

        // The body says "cancelled", but only the API is trusted.
        $this->postSignedWebhook('subscription_preapproval', $subscription->preapproval_id, ['status' => 'cancelled'])
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('authorized', $subscription->refresh()->status);
        $this->assertNotNull($subscription->synced_at);
        Event::assertDispatched(SubscriptionActivated::class);
        Event::assertDispatched(SubscriptionUpdated::class, fn (SubscriptionUpdated $event): bool => $event->changed === ['status']);
        Event::assertNotDispatched(SubscriptionCancelled::class);
    }

    public function test_repeated_webhooks_do_not_fire_events_again(): void
    {
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);
        $subscription = $team->newSubscription('default', 'pro')->checkout()->subscription;
        $this->mercadoPago->addSubscription(['id' => $subscription->preapproval_id, 'status' => 'authorized']);
        $this->postSignedWebhook('subscription_preapproval', $subscription->preapproval_id)->assertOk();

        Event::fake([SubscriptionActivated::class, SubscriptionUpdated::class]);
        $this->postSignedWebhook('subscription_preapproval', $subscription->preapproval_id)->assertOk();

        Event::assertNotDispatched(SubscriptionActivated::class);
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    public function test_a_cancellation_from_mercado_pago_starts_the_grace_period(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $team = Team::create(['name' => 'Acme', 'email' => 'billing@acme.test']);
        $subscription = $team->subscriptions()->create(['preapproval_id' => 'preapproval_9', 'plan' => 'pro', 'status' => 'authorized']);
        $this->mercadoPago->addSubscription([
            'id' => 'preapproval_9',
            'status' => 'cancelled',
            'next_payment_date' => now()->addDays(9)->toIso8601String(),
        ]);

        $this->postSignedWebhook('subscription_preapproval', 'preapproval_9')->assertOk();

        $subscription->refresh();
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertSame(now()->addDays(9)->toDateString(), $subscription->ends_at->toDateString());
        Event::assertDispatched(SubscriptionCancelled::class);
    }

    public function test_an_unknown_subscription_is_adopted_through_its_external_reference(): void
    {
        Event::fake([SubscriptionCreated::class]);
        $team = Team::create(['name' => 'Acme']);
        $this->mercadoPago->addSubscription([
            'id' => 'preapproval_lost',
            'status' => 'authorized',
            'external_reference' => $team->mercadoPagoExternalReference(),
            'auto_recurring' => ['transaction_amount' => 12000, 'currency_id' => 'ARS'],
        ]);

        $this->postSignedWebhook('subscription_preapproval', 'preapproval_lost')->assertOk();

        $subscription = $team->subscription();
        $this->assertNotNull($subscription);
        $this->assertSame('preapproval_lost', $subscription->preapproval_id);
        $this->assertTrue($team->subscribed());
        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_external_references_to_non_billable_models_are_ignored(): void
    {
        $workspace = Workspace::create(['name' => 'Not billable']);
        $this->mercadoPago->addSubscription([
            'id' => 'preapproval_other',
            'status' => 'authorized',
            'external_reference' => Workspace::class.':'.$workspace->getKey(),
        ]);

        $this->postSignedWebhook('subscription_preapproval', 'preapproval_other')->assertOk();

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_a_subscription_charge_refreshes_the_subscription(): void
    {
        Event::fake([SubscriptionPaymentReceived::class]);
        $team = Team::create(['name' => 'Acme']);
        $subscription = $team->subscriptions()->create(['preapproval_id' => 'preapproval_5', 'status' => 'authorized']);
        $nextPayment = now()->addMonth()->startOfSecond();
        $this->mercadoPago
            ->addSubscription(['id' => 'preapproval_5', 'status' => 'authorized', 'next_payment_date' => $nextPayment->toIso8601String()])
            ->addAuthorizedPayment(['id' => 777, 'preapproval_id' => 'preapproval_5', 'status' => 'processed', 'transaction_amount' => 12000, 'currency_id' => 'ARS']);

        $this->postSignedWebhook('subscription_authorized_payment', 777)->assertOk();

        $this->assertTrue($subscription->refresh()->next_payment_at->equalTo($nextPayment));
        Event::assertDispatched(SubscriptionPaymentReceived::class, fn (SubscriptionPaymentReceived $event): bool => $event->payment->id === 777
            && $event->payment->status === 'processed'
            && $event->subscription?->is($subscription) === true);
    }

    public function test_payment_webhooks_fetch_the_payment_only_when_someone_listens(): void
    {
        $this->mercadoPago->addPayment(['id' => 4242, 'status' => 'approved', 'external_reference' => 'order-1', 'transaction_amount' => 500]);

        $this->postSignedWebhook('payment', 4242)->assertOk();
        $this->assertSame([], $this->mercadoPago->calls('payments.find'));

        $received = null;
        Event::listen(PaymentUpdated::class, function (PaymentUpdated $event) use (&$received): void {
            $received = $event->payment;
        });

        $this->postSignedWebhook('payment', 4242)->assertOk();

        $this->assertNotNull($received);
        $this->assertTrue($received->approved());
        $this->assertSame('order-1', $received->externalReference);
    }

    public function test_unsigned_or_forged_webhooks_are_rejected(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postJson('/mercadopago/webhook?data.id=1&type=payment', ['type' => 'payment', 'data' => ['id' => '1']])
            ->assertUnauthorized();
        $this->postSignedWebhook('payment', 1, secret: 'wrong-secret')
            ->assertUnauthorized();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_other_topics_are_acknowledged_and_passed_on(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postSignedWebhook('merchant_order', 99)->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->notification->topic === 'merchant_order'
            && $event->notification->resourceId === '99');
    }

    public function test_api_failures_return_an_error_so_mercado_pago_retries(): void
    {
        $this->withoutExceptionHandling();
        $this->expectExceptionMessage('was not found');

        $this->postSignedWebhook('subscription_preapproval', 'preapproval_missing');
    }
}
