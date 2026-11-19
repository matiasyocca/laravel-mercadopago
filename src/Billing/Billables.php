<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use MatiasYocca\MercadoPago\Exceptions\BillingException;

/**
 * @internal
 */
final class Billables
{
    /**
     * @phpstan-assert-if-true Model $model
     */
    public static function isBillable(mixed $model): bool
    {
        return $model instanceof Model && in_array(Billable::class, class_uses_recursive($model), true);
    }

    /**
     * @return Model&Billable
     */
    public static function ensure(mixed $model): Model
    {
        if (! self::isBillable($model)) {
            throw BillingException::notBillable($model);
        }

        /** @var Model&Billable $model */
        return $model;
    }

    /**
     * Resolve "{morph class}:{key}" back to a billable model.
     *
     * Only use it with references read from the Mercado Pago API, never
     * with values taken from a request.
     *
     * @return (Model&Billable)|null
     */
    public static function fromExternalReference(?string $reference): ?Model
    {
        if ($reference === null || ! str_contains($reference, ':')) {
            return null;
        }

        [$type, $key] = explode(':', $reference, 2);
        $class = Relation::getMorphedModel($type) ?? $type;

        if ($key === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        if (! self::isBillable(new $class)) {
            return null;
        }

        /** @var (Model&Billable)|null */
        return $class::query()->whereKey($key)->first();
    }
}
