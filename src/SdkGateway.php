<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago;

use Closure;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Data\AuthorizedPayment;
use MatiasYocca\MercadoPago\Data\Payment;
use MatiasYocca\MercadoPago\Data\PreApproval;
use MatiasYocca\MercadoPago\Data\Preference;
use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Invoice\InvoiceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResource;

/**
 * Gateway backed by the official Mercado Pago PHP SDK.
 *
 * The access token is sent per request, so the SDK's global configuration
 * is never touched.
 */
final readonly class SdkGateway implements Gateway
{
    public function __construct(private ?string $accessToken) {}

    public function createPreference(array $payload, ?string $idempotencyKey = null): Preference
    {
        return Preference::fromArray($this->send(
            fn (RequestOptions $options): MPResource => (new PreferenceClient)->create($payload, $options),
            $idempotencyKey,
        ));
    }

    public function findPayment(int $id): Payment
    {
        return Payment::fromArray($this->send(
            fn (RequestOptions $options): MPResource => (new PaymentClient)->get($id, $options),
        ));
    }

    public function createSubscription(array $payload, ?string $idempotencyKey = null): PreApproval
    {
        return PreApproval::fromArray($this->send(
            fn (RequestOptions $options): MPResource => (new PreApprovalClient)->create($payload, $options),
            $idempotencyKey,
        ));
    }

    public function findSubscription(string $id): PreApproval
    {
        return PreApproval::fromArray($this->send(
            fn (RequestOptions $options): MPResource => (new PreApprovalClient)->get($id, $options),
        ));
    }

    public function updateSubscription(string $id, array $payload): PreApproval
    {
        return PreApproval::fromArray($this->send(
            fn (RequestOptions $options): MPResource => (new PreApprovalClient)->update($id, $payload, $options),
        ));
    }

    public function findAuthorizedPayment(int $id): AuthorizedPayment
    {
        return AuthorizedPayment::fromArray($this->send(
            fn (RequestOptions $options): MPResource => (new InvoiceClient)->get($id, $options),
        ));
    }

    /**
     * @param  Closure(RequestOptions): MPResource  $request
     * @return array<string, mixed>
     */
    private function send(Closure $request, ?string $idempotencyKey = null): array
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            throw RequestFailed::missingAccessToken();
        }

        $options = new RequestOptions($this->accessToken);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options->setCustomHeaders(['X-Idempotency-Key: '.$idempotencyKey]);
        }

        try {
            $content = $request($options)->getResponse()->getContent();
        } catch (MPApiException $exception) {
            throw RequestFailed::fromApiException($exception);
        }

        /** @var array<string, mixed> $content */
        return $content;
    }
}
