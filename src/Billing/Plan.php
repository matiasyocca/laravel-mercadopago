<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use MatiasYocca\MercadoPago\Exceptions\BillingException;
use MatiasYocca\MercadoPago\Support\Value;

/**
 * A plan from config('mercadopago.billing.plans').
 */
final readonly class Plan
{
    private const array FREQUENCY_TYPES = ['days', 'months'];

    public function __construct(
        public string $key,
        public string $reason,
        public float $amount,
        public string $currency,
        public int $frequency,
        public string $frequencyType,
        public ?int $trialFrequency = null,
        public ?string $trialFrequencyType = null,
        public ?string $backUrl = null,
    ) {}

    public static function find(string $key): self
    {
        $plans = config('mercadopago.billing.plans', []);
        $config = is_array($plans) ? ($plans[$key] ?? null) : null;

        if (! is_array($config)) {
            throw BillingException::unknownPlan($key);
        }

        $reason = Value::string($config['reason'] ?? null)
            ?? throw BillingException::invalidPlan($key, '"reason" is required.');

        $amount = Value::float($config['amount'] ?? null);

        if ($amount === null || $amount <= 0) {
            throw BillingException::invalidPlan($key, '"amount" must be greater than zero.');
        }

        $currency = Value::string($config['currency'] ?? null)
            ?? throw BillingException::invalidPlan($key, '"currency" is required, for example "ARS".');

        $frequency = Value::int($config['frequency'] ?? 1);

        if ($frequency === null || $frequency < 1) {
            throw BillingException::invalidPlan($key, '"frequency" must be a positive integer.');
        }

        $frequencyType = self::frequencyType($key, 'frequency_type', $config['frequency_type'] ?? 'months');

        $trial = $config['trial'] ?? null;
        $trialFrequency = null;
        $trialFrequencyType = null;

        if (is_array($trial) && ($trial['frequency'] ?? null) !== null) {
            $trialFrequency = Value::int($trial['frequency']);

            if ($trialFrequency === null || $trialFrequency < 1) {
                throw BillingException::invalidPlan($key, '"trial.frequency" must be a positive integer.');
            }

            $trialFrequencyType = self::frequencyType($key, 'trial.frequency_type', $trial['frequency_type'] ?? 'days');
        }

        return new self(
            key: $key,
            reason: $reason,
            amount: $amount,
            currency: strtoupper($currency),
            frequency: $frequency,
            frequencyType: $frequencyType,
            trialFrequency: $trialFrequency,
            trialFrequencyType: $trialFrequencyType,
            backUrl: Value::string($config['back_url'] ?? null),
        );
    }

    public function hasTrial(): bool
    {
        return $this->trialFrequency !== null;
    }

    public function trialEndsAt(CarbonInterface $from): ?CarbonImmutable
    {
        if ($this->trialFrequency === null) {
            return null;
        }

        $from = CarbonImmutable::instance($from);

        return $this->trialFrequencyType === 'months'
            ? $from->addMonthsNoOverflow($this->trialFrequency)
            : $from->addDays($this->trialFrequency);
    }

    public function billsEvery(int $frequency, string $frequencyType): bool
    {
        return $this->frequency === $frequency && $this->frequencyType === $frequencyType;
    }

    /**
     * The auto_recurring block of a Mercado Pago subscription.
     *
     * @return array<string, mixed>
     */
    public function autoRecurring(bool $withTrial = true): array
    {
        $autoRecurring = [
            'frequency' => $this->frequency,
            'frequency_type' => $this->frequencyType,
            'transaction_amount' => $this->amount,
            'currency_id' => $this->currency,
        ];

        if ($withTrial && $this->hasTrial()) {
            $autoRecurring['free_trial'] = [
                'frequency' => $this->trialFrequency,
                'frequency_type' => $this->trialFrequencyType,
            ];
        }

        return $autoRecurring;
    }

    private static function frequencyType(string $key, string $field, mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, self::FREQUENCY_TYPES, true)) {
            throw BillingException::invalidPlan($key, sprintf('"%s" must be "days" or "months".', $field));
        }

        return $value;
    }
}
