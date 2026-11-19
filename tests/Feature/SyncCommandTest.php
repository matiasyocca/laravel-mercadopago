<?php

declare(strict_types=1);

namespace Tests\Feature;

use MatiasYocca\MercadoPago\Facades\MercadoPago;
use Tests\Fixtures\Team;
use Tests\TestCase;

final class SyncCommandTest extends TestCase
{
    public function test_it_corrects_local_state_and_skips_cancelled_subscriptions(): void
    {
        $mercadoPago = MercadoPago::fake()
            ->addSubscription(['id' => 'preapproval_a', 'status' => 'paused'])
            ->addSubscription(['id' => 'preapproval_b', 'status' => 'authorized']);
        $team = Team::create(['name' => 'Acme']);
        $drifted = $team->subscriptions()->create(['preapproval_id' => 'preapproval_a', 'status' => 'authorized']);
        $cancelled = $team->subscriptions()->create(['preapproval_id' => 'preapproval_b', 'status' => 'cancelled', 'ends_at' => now()]);

        $this->artisan('mercadopago:sync-subscriptions')
            ->expectsOutputToContain('Synced 1 subscription(s), 0 failed.')
            ->assertSuccessful();

        $this->assertSame('paused', $drifted->refresh()->status);
        $this->assertSame('cancelled', $cancelled->refresh()->status);
        $this->assertCount(1, $mercadoPago->calls('subscriptions.find'));
    }

    public function test_failures_are_reported_without_stopping_the_run(): void
    {
        MercadoPago::fake()->addSubscription(['id' => 'preapproval_ok', 'status' => 'authorized']);
        $team = Team::create(['name' => 'Acme']);
        $team->subscriptions()->create(['preapproval_id' => 'preapproval_gone', 'status' => 'authorized']);
        $ok = $team->subscriptions()->create(['preapproval_id' => 'preapproval_ok', 'status' => 'pending']);

        $this->artisan('mercadopago:sync-subscriptions')
            ->expectsOutputToContain('Synced 1 subscription(s), 1 failed.')
            ->assertFailed();

        $this->assertSame('authorized', $ok->refresh()->status);
    }
}
