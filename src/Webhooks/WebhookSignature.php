<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use MatiasYocca\MercadoPago\Exceptions\InvalidWebhookSignature;

/**
 * Verifies the x-signature header Mercado Pago sends with every webhook.
 *
 * @see https://www.mercadopago.com/developers/en/docs/your-integrations/notifications/webhooks
 */
final readonly class WebhookSignature
{
    public function __construct(private Repository $config) {}

    /**
     * @throws InvalidWebhookSignature
     */
    public function verify(Request $request): void
    {
        $secret = $this->config->get('mercadopago.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw InvalidWebhookSignature::because('MERCADOPAGO_WEBHOOK_SECRET is not configured.');
        }

        $signature = $request->headers->get('x-signature');
        $requestId = $request->headers->get('x-request-id');

        if (! is_string($signature) || $signature === '') {
            throw InvalidWebhookSignature::because('Missing x-signature header.');
        }

        if (! is_string($requestId) || $requestId === '') {
            throw InvalidWebhookSignature::because('Missing x-request-id header.');
        }

        [$timestamp, $hash] = $this->parse($signature);

        if ($this->isStale($timestamp)) {
            throw InvalidWebhookSignature::because('Signature timestamp is outside the allowed tolerance.');
        }

        $expected = hash_hmac('sha256', self::manifest($request->query('data_id') ?? $request->query('data.id'), $requestId, $timestamp), $secret);

        if (! hash_equals($expected, $hash)) {
            throw InvalidWebhookSignature::because('Signature does not match.');
        }
    }

    /**
     * Build the string Mercado Pago signs: "id:{data.id};request-id:{x-request-id};ts:{ts};".
     * Parts whose value is missing are left out.
     */
    public static function manifest(mixed $dataId, string $requestId, string $timestamp): string
    {
        $parts = [];

        if (is_string($dataId) && $dataId !== '') {
            $parts[] = 'id:'.(ctype_alnum($dataId) ? strtolower($dataId) : $dataId);
        }

        $parts[] = 'request-id:'.$requestId;
        $parts[] = 'ts:'.$timestamp;

        return implode(';', $parts).';';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $signature): array
    {
        $parts = [];

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if ($key !== null && $value !== null) {
                $parts[trim($key)] = trim($value);
            }
        }

        if (($parts['ts'] ?? '') === '' || ($parts['v1'] ?? '') === '') {
            throw InvalidWebhookSignature::because('Malformed x-signature header.');
        }

        if (! ctype_digit($parts['ts'])) {
            throw InvalidWebhookSignature::because('Invalid x-signature timestamp.');
        }

        return [$parts['ts'], $parts['v1']];
    }

    private function isStale(string $timestamp): bool
    {
        $tolerance = (int) $this->config->get('mercadopago.webhook_tolerance', 300);

        if ($tolerance <= 0) {
            return false;
        }

        $sentAt = (int) $timestamp;

        // Mercado Pago has sent both seconds and milliseconds.
        if ($sentAt > 9_999_999_999) {
            $sentAt = intdiv($sentAt, 1000);
        }

        return abs(time() - $sentAt) > $tolerance;
    }
}
