<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Testing;

use Carbon\CarbonImmutable;
use MatiasYocca\MercadoPago\Contracts\Gateway;
use MatiasYocca\MercadoPago\Data\AuthorizedPayment;
use MatiasYocca\MercadoPago\Data\Payment;
use MatiasYocca\MercadoPago\Data\PreApproval;
use MatiasYocca\MercadoPago\Data\Preference;
use MatiasYocca\MercadoPago\Exceptions\RequestFailed;
use PHPUnit\Framework\Assert;

/**
 * In-memory Mercado Pago for tests. Swap it in with MercadoPago::fake().
 *
 * Subscriptions created through it are stored, so later finds and updates
 * see them. Seed other state with addSubscription(), addPayment() and
 * addAuthorizedPayment().
 */
final class FakeGateway implements Gateway
{
    /** @var list<array{operation: string, id: string|int|null, payload: array<string, mixed>, idempotencyKey: string|null}> */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> */
    private array $subscriptions = [];

    /** @var array<int, array<string, mixed>> */
    private array $payments = [];

    /** @var array<int, array<string, mixed>> */
    private array $authorizedPayments = [];

    private int $sequence = 0;

    public function createPreference(array $payload, ?string $idempotencyKey = null): Preference
    {
        $id = 'pref_'.$this->next();
        $this->record('preferences.create', null, $payload, $idempotencyKey);

        return Preference::fromArray([
            ...$payload,
            'id' => $id,
            'init_point' => 'https://mercadopago.test/checkout/'.$id,
            'sandbox_init_point' => 'https://sandbox.mercadopago.test/checkout/'.$id,
        ]);
    }

    public function findPayment(int $id): Payment
    {
        $this->record('payments.find', $id);

        return Payment::fromArray($this->payments[$id] ?? throw RequestFailed::notFound('payment', $id));
    }

    public function createSubscription(array $payload, ?string $idempotencyKey = null): PreApproval
    {
        $id = 'preapproval_'.$this->next();
        $this->record('subscriptions.create', $id, $payload, $idempotencyKey);

        $this->subscriptions[$id] = [
            ...$payload,
            'id' => $id,
            'status' => $payload['status'] ?? 'pending',
            'init_point' => 'https://mercadopago.test/subscriptions/checkout?preapproval_id='.$id,
            'next_payment_date' => self::firstChargeDate($payload)->toIso8601String(),
        ];

        return PreApproval::fromArray($this->subscriptions[$id]);
    }

    public function findSubscription(string $id): PreApproval
    {
        $this->record('subscriptions.find', $id);

        return PreApproval::fromArray($this->subscriptions[$id] ?? throw RequestFailed::notFound('subscription', $id));
    }

    public function updateSubscription(string $id, array $payload): PreApproval
    {
        $this->record('subscriptions.update', $id, $payload);

        $current = $this->subscriptions[$id] ?? throw RequestFailed::notFound('subscription', $id);
        $this->subscriptions[$id] = array_replace_recursive($current, $payload);

        return PreApproval::fromArray($this->subscriptions[$id]);
    }

    public function findAuthorizedPayment(int $id): AuthorizedPayment
    {
        $this->record('authorized_payments.find', $id);

        return AuthorizedPayment::fromArray($this->authorizedPayments[$id] ?? throw RequestFailed::notFound('authorized payment', $id));
    }

    /**
     * Add or change a subscription as Mercado Pago would report it.
     * Attributes are merged into an existing subscription with the same id.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addSubscription(array $attributes): self
    {
        $id = (string) ($attributes['id'] ?? 'preapproval_'.$this->next());
        $this->subscriptions[$id] = array_replace_recursive($this->subscriptions[$id] ?? [], [...$attributes, 'id' => $id]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addPayment(array $attributes): self
    {
        $id = (int) ($attributes['id'] ?? $this->next());
        $this->payments[$id] = [...$attributes, 'id' => $id];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addAuthorizedPayment(array $attributes): self
    {
        $id = (int) ($attributes['id'] ?? $this->next());
        $this->authorizedPayments[$id] = [...$attributes, 'id' => $id];

        return $this;
    }

    /**
     * The stored subscription as the fake API would return it.
     *
     * @return array<string, mixed>|null
     */
    public function subscription(string $id): ?array
    {
        return $this->subscriptions[$id] ?? null;
    }

    /**
     * @return list<array{operation: string, id: string|int|null, payload: array<string, mixed>, idempotencyKey: string|null}>
     */
    public function calls(?string $operation = null): array
    {
        if ($operation === null) {
            return $this->calls;
        }

        return array_values(array_filter($this->calls, fn (array $call): bool => $call['operation'] === $operation));
    }

    /**
     * @param  (callable(array<string, mixed>, string|null): bool)|null  $callback
     */
    public function assertPreferenceCreated(?callable $callback = null): self
    {
        return $this->assertCalled('preferences.create', $callback);
    }

    /**
     * @param  (callable(array<string, mixed>, string|null): bool)|null  $callback
     */
    public function assertSubscriptionCreated(?callable $callback = null): self
    {
        return $this->assertCalled('subscriptions.create', $callback);
    }

    public function assertSubscriptionCreatedTimes(int $times): self
    {
        Assert::assertCount($times, $this->calls('subscriptions.create'));

        return $this;
    }

    /**
     * @param  (callable(array<string, mixed>, string|null): bool)|null  $callback
     */
    public function assertSubscriptionUpdated(string $id, ?callable $callback = null): self
    {
        return $this->assertCalled('subscriptions.update', $callback, $id);
    }

    public function assertNothingCreated(): self
    {
        Assert::assertSame([], $this->calls('preferences.create'), 'Unexpected Mercado Pago preferences were created.');
        Assert::assertSame([], $this->calls('subscriptions.create'), 'Unexpected Mercado Pago subscriptions were created.');

        return $this;
    }

    /**
     * @param  (callable(array<string, mixed>, string|null): bool)|null  $callback
     */
    private function assertCalled(string $operation, ?callable $callback, string|int|null $id = null): self
    {
        $calls = array_filter(
            $this->calls($operation),
            fn (array $call): bool => $id === null || $call['id'] === $id,
        );

        Assert::assertNotEmpty($calls, sprintf('Expected Mercado Pago [%s] to be called.', $operation));

        if ($callback !== null) {
            $matching = array_filter($calls, fn (array $call): bool => (bool) $callback($call['payload'], $call['idempotencyKey']));

            Assert::assertNotEmpty($matching, sprintf('No Mercado Pago [%s] call matched the callback.', $operation));
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $operation, string|int|null $id, array $payload = [], ?string $idempotencyKey = null): void
    {
        $this->calls[] = compact('operation', 'id', 'payload', 'idempotencyKey');
    }

    private function next(): int
    {
        return ++$this->sequence;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function firstChargeDate(array $payload): CarbonImmutable
    {
        $frequency = (int) data_get($payload, 'auto_recurring.free_trial.frequency', 0);
        $type = data_get($payload, 'auto_recurring.free_trial.frequency_type', 'days');

        return $type === 'months'
            ? CarbonImmutable::now()->addMonths($frequency)
            : CarbonImmutable::now()->addDays($frequency);
    }
}
