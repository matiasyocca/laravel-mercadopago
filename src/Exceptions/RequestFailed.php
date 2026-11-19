<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Exceptions;

use MercadoPago\Exceptions\MPApiException;
use TypeError;

final class RequestFailed extends MercadoPagoException
{
    /**
     * @param  array<array-key, mixed>  $response
     */
    public function __construct(string $message, public readonly int $status = 0, public readonly array $response = [])
    {
        parent::__construct($message, $status);
    }

    public static function fromApiException(MPApiException $exception): self
    {
        try {
            $content = $exception->getApiResponse()->getContent();
        } catch (TypeError) {
            // The SDK throws when the error response has no body.
            $content = [];
        }

        $detail = $content['message'] ?? $content['error'] ?? null;

        return new self(
            sprintf(
                'Mercado Pago request failed with status %d%s',
                $exception->getStatusCode(),
                is_string($detail) && $detail !== '' ? ': '.$detail : '.',
            ),
            $exception->getStatusCode(),
            $content,
        );
    }

    public static function missingAccessToken(): self
    {
        return new self('MERCADOPAGO_ACCESS_TOKEN is not configured.');
    }

    public static function notFound(string $resource, string|int $id): self
    {
        return new self(sprintf('Mercado Pago %s [%s] was not found.', $resource, $id), 404);
    }

    public static function missingId(string $resource): self
    {
        return new self(sprintf('Mercado Pago returned a %s without an id.', $resource));
    }
}
