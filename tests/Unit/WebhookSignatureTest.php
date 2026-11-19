<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use MatiasYocca\MercadoPago\Exceptions\InvalidWebhookSignature;
use MatiasYocca\MercadoPago\Webhooks\WebhookSignature;
use Tests\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function test_it_builds_the_manifest_mercado_pago_signs(): void
    {
        $this->assertSame('id:abc123;request-id:req-1;ts:1700000000;', WebhookSignature::manifest('ABC123', 'req-1', '1700000000'));
        $this->assertSame('id:a-B_1;request-id:req-1;ts:1;', WebhookSignature::manifest('a-B_1', 'req-1', '1'));
        $this->assertSame('request-id:req-1;ts:1;', WebhookSignature::manifest(null, 'req-1', '1'));
    }

    public function test_it_accepts_a_valid_signature(): void
    {
        $this->signature()->verify($this->request('123', time()));

        $this->addToAssertionCount(1);
    }

    public function test_it_accepts_millisecond_timestamps(): void
    {
        $this->signature()->verify($this->request('123', time() * 1000));

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_wrong_secret(): void
    {
        $this->expectException(InvalidWebhookSignature::class);
        $this->expectExceptionMessage('does not match');

        $this->signature()->verify($this->request('123', time(), secret: 'other-secret'));
    }

    public function test_it_rejects_a_tampered_resource_id(): void
    {
        $request = $this->request('123', time());
        $request->query->set('data_id', '456');

        $this->expectException(InvalidWebhookSignature::class);

        $this->signature()->verify($request);
    }

    public function test_it_rejects_stale_notifications(): void
    {
        $this->expectException(InvalidWebhookSignature::class);
        $this->expectExceptionMessage('tolerance');

        $this->signature()->verify($this->request('123', time() - 3600));
    }

    public function test_it_fails_closed_without_a_secret(): void
    {
        config()->set('mercadopago.webhook_secret', null);

        $this->expectException(InvalidWebhookSignature::class);
        $this->expectExceptionMessage('MERCADOPAGO_WEBHOOK_SECRET');

        $this->signature()->verify($this->request('123', time()));
    }

    public function test_it_rejects_malformed_headers(): void
    {
        $request = Request::create('/mercadopago/webhook?data.id=123', 'POST');
        $request->headers->set('x-request-id', 'req-1');
        $request->headers->set('x-signature', 'garbage');

        $this->expectException(InvalidWebhookSignature::class);
        $this->expectExceptionMessage('Malformed');

        $this->signature()->verify($request);
    }

    private function signature(): WebhookSignature
    {
        return $this->app->make(WebhookSignature::class);
    }

    private function request(string $dataId, int $timestamp, string $secret = self::WEBHOOK_SECRET): Request
    {
        $hash = hash_hmac('sha256', WebhookSignature::manifest($dataId, 'req-1', (string) $timestamp), $secret);

        $request = Request::create('/mercadopago/webhook?data.id='.$dataId, 'POST');
        $request->headers->set('x-request-id', 'req-1');
        $request->headers->set('x-signature', "ts={$timestamp},v1={$hash}");

        return $request;
    }
}
