<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MatiasYocca\MercadoPago\Events\WebhookReceived;
use MatiasYocca\MercadoPago\Exceptions\InvalidWebhookSignature;
use MatiasYocca\MercadoPago\Webhooks\WebhookNotification;
use MatiasYocca\MercadoPago\Webhooks\WebhookSignature;

/**
 * Verifies the notification and hands it to listeners. Listeners run in
 * the request: if one throws, Mercado Pago gets a 500 and retries later.
 */
final class WebhookController
{
    public function __invoke(Request $request, WebhookSignature $signature, Dispatcher $events): JsonResponse
    {
        try {
            $signature->verify($request);
        } catch (InvalidWebhookSignature $exception) {
            Log::warning('Rejected Mercado Pago webhook: '.$exception->getMessage(), [
                'request_id' => $request->headers->get('x-request-id'),
            ]);

            return new JsonResponse(['message' => 'Invalid signature.'], 401);
        }

        $events->dispatch(new WebhookReceived(WebhookNotification::fromRequest($request)));

        return new JsonResponse(['received' => true]);
    }
}
