<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/KvStore.php';

/**
 * Pure in-memory KvStore fake for unit tests. Expiry is not simulated, but
 * the TTL passed to each set() is recorded so tests can assert TTL refreshes.
 */
class InMemoryKvStore implements KvStore
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, int|null> Last TTL passed to set(), per key */
    private array $ttls = [];

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->data[$key] = $value;
        $this->ttls[$key] = $ttl;
    }

    /**
     * The TTL recorded by the last set() on this key (null = no expiry),
     * or null when the key was never set.
     */
    public function ttlOf(string $key): ?int
    {
        return $this->ttls[$key] ?? null;
    }

    public function increment(string $key): int
    {
        $current = isset($this->data[$key]) ? (int)$this->data[$key] : 0;
        $this->data[$key] = $current + 1;
        return $current + 1;
    }

    public function getOrCompute(string $key, callable $generator): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            $this->data[$key] = $generator();
        }
        return $this->data[$key];
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
        unset($this->ttls[$key]);
    }

    public function entriesWithPrefix(string $prefix): array
    {
        $result = [];
        foreach ($this->data as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
