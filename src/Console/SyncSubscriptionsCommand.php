<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Console;

use Illuminate\Console\Command;
use MatiasYocca\MercadoPago\Billing\Subscription;
use MatiasYocca\MercadoPago\Billing\SyncSubscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Re-reads subscriptions from Mercado Pago in case webhooks were missed.
 * Schedule it daily.
 */
#[AsCommand(name: 'mercadopago:sync-subscriptions')]
final class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'mercadopago:sync-subscriptions
        {--all : Include cancelled subscriptions}';

    protected $description = 'Re-read local Mercado Pago subscriptions from the API';

    public function handle(SyncSubscription $sync): int
    {
        $synced = 0;
        $failed = 0;

        Subscription::query()
            ->unless($this->option('all'), fn ($query) => $query->where(
                fn ($query) => $query->whereNull('status')->orWhere('status', '!=', Subscription::STATUS_CANCELLED),
            ))
            ->chunkById(100, function ($subscriptions) use ($sync, &$synced, &$failed): void {
                foreach ($subscriptions as $subscription) {
                    try {
                        $sync->refresh($subscription);
                        $synced++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                        $this->components->warn(sprintf('Subscription %s: %s', $subscription->preapproval_id, $exception->getMessage()));
                    }
                }
            });

        $this->components->info(sprintf('Synced %d subscription(s), %d failed.', $synced, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
