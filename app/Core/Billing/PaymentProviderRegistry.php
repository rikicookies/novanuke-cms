<?php

declare(strict_types=1);

namespace NovaNuke\Core\Billing;

use InvalidArgumentException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private array $providers = [];

    public function register(PaymentProviderInterface $provider): void
    {
        $key=$provider->key();
        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/',$key)) {
            throw new InvalidArgumentException('Invalid payment provider key.');
        }
        if (isset($this->providers[$key])) {
            throw new InvalidArgumentException("Payment provider already registered: {$key}");
        }
        $this->providers[$key]=$provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): PaymentProviderInterface
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Payment provider is not registered: {$key}");
        }
        return $this->providers[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys=array_keys($this->providers);
        sort($keys,SORT_STRING);
        return $keys;
    }
}
