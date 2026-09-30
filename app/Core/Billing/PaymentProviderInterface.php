<?php

declare(strict_types=1);

namespace NovaNuke\Core\Billing;

/**
 * Optional payment modules verify provider authenticity and return only a
 * normalized, completed payment. Core never accepts raw "paid=true" input.
 */
interface PaymentProviderInterface
{
    public function key(): string;

    /** @param array<string,string> $headers */
    public function verify(string $payload, array $headers = []): VerifiedPayment;
}
