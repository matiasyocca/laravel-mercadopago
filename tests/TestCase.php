<?php

declare(strict_types=1);

namespace Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\LivewireServiceProvider;
use MatiasYocca\MercadoPago\MercadoPagoServiceProvider;
use MatiasYocca\MercadoPago\Webhooks\WebhookSignature;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Tests\Fixtures\TestPanelProvider;
use Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const string WEBHOOK_SECRET = 'test-webhook-secret';

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            MercadoPagoServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('auth.providers.users.model', User::class);
        $config->set('mercadopago.access_token', 'TEST-access-token');
        $config->set('mercadopago.webhook_secret', self::WEBHOOK_SECRET);
        $config->set('mercadopago.billing.plans', [
            'pro' => [
                'reason' => 'Acme Pro',
                'amount' => 12000,
                'currency' => 'ARS',
                'frequency' => 1,
                'frequency_type' => 'months',
                'trial' => ['frequency' => 14, 'frequency_type' => 'days'],
            ],
            'business' => [
                'reason' => 'Acme Business',
                'amount' => 25000,
                'currency' => 'ARS',
                'frequency' => 1,
                'frequency_type' => 'months',
            ],
            'yearly' => [
                'reason' => 'Acme Pro yearly',
                'amount' => 120000,
                'currency' => 'ARS',
                'frequency' => 12,
                'frequency_type' => 'months',
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /**
     * Post a webhook signed the way Mercado Pago signs it.
     *
     * @param  array<string, mixed>  $body
     */
    protected function postSignedWebhook(string $topic, string|int $resourceId, array $body = [], ?string $secret = null): TestResponse
    {
        $timestamp = (string) time();
        $requestId = (string) Str::uuid();
        $manifest = WebhookSignature::manifest((string) $resourceId, $requestId, $timestamp);
        $hash = hash_hmac('sha256', $manifest, $secret ?? self::WEBHOOK_SECRET);

        return $this->postJson(
            '/mercadopago/webhook?'.http_build_query(['data.id' => (string) $resourceId, 'type' => $topic]),
            [
                'action' => $topic === 'payment' ? 'payment.updated' : 'updated',
                'type' => $topic,
                'data' => ['id' => (string) $resourceId],
                'live_mode' => false,
                ...$body,
            ],
            [
                'x-signature' => "ts={$timestamp},v1={$hash}",
                'x-request-id' => $requestId,
            ],
        );
    }
}
