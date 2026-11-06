<?php

namespace App\Auth;

use App\Auth\Contracts\AuthenticationAdapter;
use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Registry of authentication adapters, resolved by driver name.
 *
 * Built-in drivers come from `config('auth.adapters')`; packages or providers can add
 * more at runtime with {@see extend()}, the same way Laravel's own managers work.
 */
class AuthenticationManager
{
    /** @var array<string, AuthenticationAdapter> */
    private array $resolved = [];

    /** @var array<string, Closure(Container): AuthenticationAdapter> */
    private array $customCreators = [];

    /**
     * @param  array<string, class-string<AuthenticationAdapter>>  $drivers
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $drivers,
    ) {}

    /**
     * Register an adapter factory under a driver name.
     *
     * @param  Closure(Container): AuthenticationAdapter  $creator
     */
    public function extend(string $driver, Closure $creator): static
    {
        $this->customCreators[$driver] = $creator;
        unset($this->resolved[$driver]);

        return $this;
    }

    /**
     * Get the adapter for a driver, instantiating it on first use.
     */
    public function adapter(string $driver): AuthenticationAdapter
    {
        return $this->resolved[$driver] ??= $this->resolve($driver);
    }

    public function has(string $driver): bool
    {
        return isset($this->customCreators[$driver]) || isset($this->drivers[$driver]);
    }

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_values(array_unique([
            ...array_keys($this->drivers),
            ...array_keys($this->customCreators),
        ]));
    }

    private function resolve(string $driver): AuthenticationAdapter
    {
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($this->container);
        }

        if (isset($this->drivers[$driver])) {
            return $this->container->make($this->drivers[$driver]);
        }

        throw new InvalidArgumentException("Authentication driver [{$driver}] is not registered.");
    }
}
