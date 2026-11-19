<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use MatiasYocca\MercadoPago\Billing\Plan;
use MatiasYocca\MercadoPago\Exceptions\BillingException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PlanTest extends TestCase
{
    public function test_it_reads_a_plan_from_config(): void
    {
        $plan = Plan::find('pro');

        $this->assertSame('Acme Pro', $plan->reason);
        $this->assertSame(12000.0, $plan->amount);
        $this->assertSame('ARS', $plan->currency);
        $this->assertTrue($plan->hasTrial());
        $this->assertSame([
            'frequency' => 1,
            'frequency_type' => 'months',
            'transaction_amount' => 12000.0,
            'currency_id' => 'ARS',
            'free_trial' => ['frequency' => 14, 'frequency_type' => 'days'],
        ], $plan->autoRecurring());
        $this->assertArrayNotHasKey('free_trial', $plan->autoRecurring(withTrial: false));
    }

    public function test_it_calculates_the_trial_end(): void
    {
        $from = CarbonImmutable::parse('2026-01-31 10:00:00');

        $this->assertEquals($from->addDays(14), Plan::find('pro')->trialEndsAt($from));
        $this->assertNull(Plan::find('business')->trialEndsAt($from));
    }

    public function test_unknown_plans_fail_loudly(): void
    {
        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('[missing] is not defined');

        Plan::find('missing');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPlans')]
    public function test_invalid_plans_fail_loudly(array $overrides, string $message): void
    {
        config()->set('mercadopago.billing.plans.broken', [
            'reason' => 'Broken',
            'amount' => 100,
            'currency' => 'ARS',
            ...$overrides,
        ]);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage($message);

        Plan::find('broken');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPlans(): iterable
    {
        yield 'zero amount' => [['amount' => 0], '"amount" must be greater than zero'];
        yield 'missing reason' => [['reason' => ''], '"reason" is required'];
        yield 'missing currency' => [['currency' => null], '"currency" is required'];
        yield 'weekly' => [['frequency_type' => 'weeks'], '"frequency_type" must be "days" or "months"'];
        yield 'zero trial' => [['trial' => ['frequency' => 0]], '"trial.frequency" must be a positive integer'];
    }
}
