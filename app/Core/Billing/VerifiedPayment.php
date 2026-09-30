<?php

declare(strict_types=1);

namespace NovaNuke\Core\Billing;

use InvalidArgumentException;

final readonly class VerifiedPayment
{
    public function __construct(
        public string $provider,
        public string $externalReference,
        public int $userId,
        public string $planKey,
        public int $amountMinor,
        public string $currency,
    ) {
        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $provider)) {
            throw new InvalidArgumentException('Invalid payment provider key.');
        }
        if ($externalReference === '' || mb_strlen($externalReference) > 191) {
            throw new InvalidArgumentException('Invalid payment reference.');
        }
        if ($userId < 1) throw new InvalidArgumentException('Invalid payment user.');
        if (! preg_match('/^[a-z][a-z0-9-]{1,63}$/', $planKey)) {
            throw new InvalidArgumentException('Invalid payment membership plan.');
        }
        if ($amountMinor < 0) throw new InvalidArgumentException('Invalid payment amount.');
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Payment currency must be a three-letter ISO code.');
        }
    }
}
