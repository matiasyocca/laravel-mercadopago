<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Webhooks;

use Illuminate\Http\Request;
use MatiasYocca\MercadoPago\Support\Value;

/**
 * A verified Mercado Pago notification. It only says what changed; always
 * fetch the resource from the API before trusting its state.
 */
final readonly class WebhookNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?string $topic,
        public ?string $action,
        public ?string $resourceId,
        public bool $liveMode,
        public string $requestId,
        public array $payload = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return new self(
            topic: Value::string($payload['type'] ?? $request->query('type') ?? $request->query('topic')),
            action: Value::string($payload['action'] ?? null),
            resourceId: Value::string(data_get($payload, 'data.id') ?? $request->query('data_id') ?? $request->query('id')),
            liveMode: (bool) ($payload['live_mode'] ?? false),
            requestId: (string) $request->headers->get('x-request-id', ''),
            payload: $payload,
        );
    }

    public function is(WebhookTopic $topic): bool
    {
        return $this->topic === $topic->value;
    }
}
