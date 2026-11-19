<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Exceptions;

final class InvalidWebhookSignature extends MercadoPagoException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
