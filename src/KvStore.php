<?php
declare(strict_types=1);

/**
 * Storage seam used by the domain layer.
 * Production wiring uses the APCu-backed implementation (ApcuKvStore);
 * unit tests use a pure in-memory fake.
 */
interface KvStore
{
    /**
     * Check whether a key exists.
     */
    public function exists(string $key): bool;

    /**
     * Get the value stored at a key.
     *
     * @return mixed The stored value, or null if the key does not exist
     */
    public function get(string $key): mixed;

    /**
     * Store a value at a key.
     *
     * @param int|null $ttl Time-to-live in seconds, or null for no expiry
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void;

    /**
     * Increment the integer value at a key and return the new value.
     * A missing key is treated as 0 before incrementing.
     */
    public function increment(string $key): int;

    /**
     * Get the value at a key, generating and storing it atomically if the key
     * does not exist. Concurrent callers all observe the same value.
     */
    public function getOrCompute(string $key, callable $generator): mixed;

    /**
     * Delete a key.
     */
    public function delete(string $key): void;

    /**
     * Get all entries whose key starts with the given prefix.
     *
     * @return array<string, mixed> Key to value mapping
     */
    public function entriesWithPrefix(string $prefix): array;
}
