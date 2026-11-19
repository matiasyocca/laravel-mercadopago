<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MatiasYocca\MercadoPago\Console\SyncSubscriptionsCommand;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Events\WebhookReceived;
use MatiasYocca\MercadoPago\Listeners\FetchUpdatedPayment;
use MatiasYocca\MercadoPago\Listeners\SyncSubscriptionFromWebhook;

final class MercadoPagoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mercadopago.php', 'mercadopago');

        $this->app->singleton(Gateway::class, function (Application $app): Gateway {
            $accessToken = $app['config']->get('mercadopago.access_token');

            return new SdkGateway(is_string($accessToken) ? $accessToken : null);
        });
    }

    public function boot(): void
    {
        Event::listen(WebhookReceived::class, SyncSubscriptionFromWebhook::class);
        Event::listen(WebhookReceived::class, FetchUpdatedPayment::class);

        if ((bool) $this->app['config']->get('mercadopago.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mercadopago.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncSubscriptionsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/mercadopago.php' => config_path('mercadopago.php'),
            ], 'mercadopago-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'mercadopago-migrations');
        }
    }
}
