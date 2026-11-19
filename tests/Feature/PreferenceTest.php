<?php

declare(strict_types=1);

namespace Tests\Feature;

use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MatiasYocca\MercadoPago\Facades\MercadoPago;
use MatiasYocca\MercadoPago\SdkGateway;
use Tests\TestCase;

final class PreferenceTest extends TestCase
{
    public function test_checkout_pro_preferences_go_through_the_facade(): void
    {
        $mercadoPago = MercadoPago::fake();

        $preference = MercadoPago::createPreference([
            'items' => [['title' => 'Credit pack', 'quantity' => 1, 'unit_price' => 5000, 'currency_id' => 'ARS']],
            'external_reference' => 'order-1',
        ], idempotencyKey: 'order-1');

        $mercadoPago->assertPreferenceCreated(fn (array $payload, ?string $key): bool => $payload['external_reference'] === 'order-1' && $key === 'order-1');
        $this->assertSame('order-1', $preference->externalReference);
        $this->assertSame($preference->initPoint, $preference->toResponse(request())->getTargetUrl());
    }

    public function test_the_container_resolves_the_sdk_gateway(): void
    {
        $this->assertInstanceOf(SdkGateway::class, $this->app->make(Gateway::class));
    }

    public function test_the_sdk_gateway_needs_an_access_token(): void
    {
        $this->expectException(RequestFailed::class);
        $this->expectExceptionMessage('MERCADOPAGO_ACCESS_TOKEN');

        (new SdkGateway(null))->findPayment(1);
    }
}
