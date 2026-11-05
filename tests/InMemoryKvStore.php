<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/KvStore.php';

/**
 * Pure in-memory KvStore fake for unit tests. TTLs are not simulated.
 */
class InMemoryKvStore implements KvStore
{
    /** @var array<string, mixed> */
    private array $data = [];

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
    }

    public function increment(string $key): int
    {
        $current = isset($this->data[$key]) ? (int)$this->data[$key] : 0;
        $this->data[$key] = $current + 1;
        return $current + 1;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
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
