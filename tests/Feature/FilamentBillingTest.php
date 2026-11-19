<?php

declare(strict_types=1);

namespace Tests\Feature;

use MatiasYocca\MercadoPago\Billing\Billables;
use MatiasYocca\MercadoPago\Exceptions\BillingException;
use MatiasYocca\MercadoPago\Facades\MercadoPago;
use MatiasYocca\MercadoPago\Filament\MercadoPagoBillingProvider;
use MatiasYocca\MercadoPago\Filament\RedirectIfNotSubscribed;
use Tests\Fixtures\Team;
use Tests\Fixtures\User;
use Tests\Fixtures\Workspace;
use Tests\TestCase;

final class FilamentBillingTest extends TestCase
{
    public function test_the_provider_points_filament_at_the_package(): void
    {
        $provider = new MercadoPagoBillingProvider(plan: 'pro', type: 'main');

        $this->assertSame(RedirectIfNotSubscribed::class.':main', $provider->getSubscribedMiddleware());
        $this->assertIsString($provider->getRouteAction());
    }

    public function test_unsubscribed_tenants_are_sent_to_billing(): void
    {
        [$user, $team] = $this->member(['email' => 'billing@acme.test']);

        $this->actingAs($user)
            ->get('/app/'.$team->getKey())
            ->assertRedirect('/app/'.$team->getKey().'/billing');
    }

    public function test_the_billing_route_starts_the_mercado_pago_checkout(): void
    {
        $mercadoPago = MercadoPago::fake();
        [$user, $team] = $this->member(['email' => 'billing@acme.test']);

        $response = $this->actingAs($user)->get('/app/'.$team->getKey().'/billing');

        $subscription = $team->subscription();
        $response->assertRedirect($subscription->init_point);
        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => $payload['payer_email'] === 'billing@acme.test'
            && $payload['back_url'] === url('/app/'.$team->getKey())
            && $payload['reason'] === 'Acme Pro');
    }

    public function test_the_billing_route_falls_back_to_the_user_email(): void
    {
        $mercadoPago = MercadoPago::fake();
        [$user, $team] = $this->member(['email' => null]);

        $this->actingAs($user)->get('/app/'.$team->getKey().'/billing')->assertRedirect();

        $mercadoPago->assertSubscriptionCreated(fn (array $payload): bool => $payload['payer_email'] === $user->email);
    }

    public function test_trialing_tenants_get_in_and_can_finish_the_checkout(): void
    {
        $mercadoPago = MercadoPago::fake();
        [$user, $team] = $this->member(['email' => 'billing@acme.test']);
        $this->actingAs($user)->get('/app/'.$team->getKey().'/billing');

        $this->get('/app/'.$team->getKey())->assertOk();
        $this->get('/app/'.$team->getKey().'/billing')->assertRedirect($team->subscription()->init_point);
        $mercadoPago->assertSubscriptionCreatedTimes(1);
    }

    public function test_subscribed_tenants_get_in_and_billing_sends_them_to_the_manage_url(): void
    {
        $mercadoPago = MercadoPago::fake();
        [$user, $team] = $this->member(['email' => 'billing@acme.test']);
        $team->subscriptions()->create(['preapproval_id' => 'preapproval_1', 'plan' => 'pro', 'status' => 'authorized']);

        $this->actingAs($user)->get('/app/'.$team->getKey())->assertOk();
        $this->get('/app/'.$team->getKey().'/billing')->assertRedirect(url('/app/'.$team->getKey()));

        config()->set('mercadopago.billing.manage_url', 'https://www.mercadopago.com.ar/subscriptions');
        $this->get('/app/'.$team->getKey().'/billing')->assertRedirect('https://www.mercadopago.com.ar/subscriptions');
        $mercadoPago->assertNothingCreated();
    }

    public function test_non_billable_tenants_fail_loudly(): void
    {
        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('must use the');

        Billables::ensure(new Workspace);
    }

    /**
     * @param  array<string, mixed>  $teamAttributes
     * @return array{User, Team}
     */
    private function member(array $teamAttributes): array
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@acme.test']);
        $team = Team::create(['name' => 'Acme', ...$teamAttributes]);
        $user->teams()->attach($team);

        return [$user, $team];
    }
}
