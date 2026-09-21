<?php

namespace App\Contracts\Payments;

use App\Enums\PaymentChannel;
use InvalidArgumentException;

/**
 * The payment providers this installation can actually reach.
 *
 * A shop configures a method against a provider by key — `midtrans` — and the
 * engine's only question is whether something answers to that key. That is the
 * whole job, and it is why the answer is held here rather than scattered through
 * the sale flow: a method may name a provider that has not been installed, in
 * which case the till has to be told so plainly instead of taking money it cannot
 * confirm.
 *
 * Subphase 3.3 registers no providers, so resolution always fails and every
 * method ships with a null `provider`. 3.8 adds the Midtrans implementation and
 * tags it here; nothing in PaymentService changes when it does.
 */
class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    /**
     * @param  iterable<PaymentProviderInterface>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(PaymentProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * The provider behind a key, or null when nothing is installed for it.
     */
    public function find(string $key): ?PaymentProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @throws InvalidArgumentException when no provider answers to the key
     */
    public function get(string $key): PaymentProviderInterface
    {
        return $this->find($key)
            ?? throw new InvalidArgumentException("No payment provider is registered under [{$key}].");
    }

    /**
     * Whether any installed provider can take this kind of money.
     *
     * The check a shop's configuration gets: a QRIS method pointing at a provider
     * that only speaks card is a misconfiguration discovered at the counter, so it
     * is refused where the mistake was made.
     */
    public function supports(PaymentChannel $channel): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
