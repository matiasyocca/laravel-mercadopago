<?php

declare(strict_types=1);

namespace Tests\Unit;

use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MatiasYocca\MercadoPago\SdkGateway;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real SDK clients against a stub HTTP client.
 */
final class SdkGatewayTest extends TestCase
{
    private MPHttpClient $originalClient;

    private StubHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalClient = MercadoPagoConfig::getHttpClient();
        $this->http = new StubHttpClient;
        MercadoPagoConfig::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        MercadoPagoConfig::setHttpClient($this->originalClient);

        parent::tearDown();
    }

    public function test_it_creates_a_subscription_with_the_token_and_idempotency_key(): void
    {
        $this->http->respond(201, [
            'id' => '2c9380848f',
            'status' => 'pending',
            'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=2c9380848f',
            'external_reference' => 'team:1',
            'payer_email' => 'billing@acme.test',
            'next_payment_date' => '2026-12-02T12:00:00.000-03:00',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => 12000,
                'currency_id' => 'ARS',
            ],
        ]);

        $preApproval = (new SdkGateway('APP_USR-token'))->createSubscription(['reason' => 'Pro'], 'idem-1');

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/preapproval', $request->getUri());
        $this->assertSame(['reason' => 'Pro'], json_decode((string) $request->getPayload(), true));
        $this->assertContains('Authorization: Bearer APP_USR-token', $request->getHeaders());
        $this->assertContains('X-Idempotency-Key: idem-1', $request->getHeaders());

        $this->assertSame('2c9380848f', $preApproval->id);
        $this->assertSame('pending', $preApproval->status);
        $this->assertSame('team:1', $preApproval->externalReference);
        $this->assertSame(12000.0, $preApproval->amount);
        $this->assertSame('ARS', $preApproval->currency);
        $this->assertSame(1, $preApproval->frequency);
        $this->assertSame('months', $preApproval->frequencyType);
        $this->assertSame('2026-12-02T15:00:00+00:00', $preApproval->nextPaymentAt?->utc()->toIso8601String());
    }

    public function test_it_reads_payments_and_authorized_payments(): void
    {
        $gateway = new SdkGateway('APP_USR-token');

        $this->http->respond(200, ['id' => 99, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 10.5, 'currency_id' => 'ARS']);
        $payment = $gateway->findPayment(99);
        $this->assertStringEndsWith('/v1/payments/99', $this->http->lastRequest()->getUri());
        $this->assertTrue($payment->approved());
        $this->assertSame(10.5, $payment->amount);

        $this->http->respond(200, ['id' => 7, 'preapproval_id' => '2c93', 'status' => 'processed', 'transaction_amount' => 12000, 'currency_id' => 'ARS']);
        $charge = $gateway->findAuthorizedPayment(7);
        $this->assertStringEndsWith('/authorized_payments/7', $this->http->lastRequest()->getUri());
        $this->assertSame('2c93', $charge->preApprovalId);
    }

    public function test_api_errors_become_request_failed(): void
    {
        $this->http->fail(400, ['message' => 'Invalid users involved', 'error' => 'bad_request']);

        try {
            (new SdkGateway('APP_USR-token'))->updateSubscription('2c93', ['status' => 'cancelled']);
            $this->fail('Expected RequestFailed.');
        } catch (RequestFailed $exception) {
            $this->assertSame(400, $exception->status);
            $this->assertStringContainsString('Invalid users involved', $exception->getMessage());
            $this->assertSame('bad_request', $exception->response['error']);
        }
    }

    public function test_api_errors_without_a_body_are_still_wrapped(): void
    {
        $this->http->fail(503, null);

        $this->expectException(RequestFailed::class);
        $this->expectExceptionMessage('status 503.');

        (new SdkGateway('APP_USR-token'))->findSubscription('2c93');
    }
}

final class StubHttpClient implements MPHttpClient
{
    /** @var list<MPRequest> */
    private array $requests = [];

    private ?MPResponse $response = null;

    private bool $fails = false;

    /**
     * @param  array<string, mixed>  $content
     */
    public function respond(int $status, array $content): void
    {
        $this->response = new MPResponse($status, $content);
        $this->fails = false;
    }

    /**
     * @param  array<string, mixed>|null  $content
     */
    public function fail(int $status, ?array $content): void
    {
        $this->response = new MPResponse($status, $content);
        $this->fails = true;
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->requests[] = $request;

        if ($this->fails) {
            throw new MPApiException('Api error. Check response for details', $this->response);
        }

        return $this->response;
    }

    public function lastRequest(): MPRequest
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
