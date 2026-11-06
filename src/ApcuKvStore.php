<?php
declare(strict_types=1);

require_once __DIR__ . '/KvStore.php';

/**
 * APCu-backed KvStore. This is the only src/ file that calls the APCu
 * extension; endpoints instantiate it and inject it into domain objects.
 */
class ApcuKvStore implements KvStore
{
    public function exists(string $key): bool
    {
        return apcu_exists($key);
    }

    public function get(string $key): mixed
    {
        return apcu_fetch($key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        apcu_store($key, $value, $ttl ?? 0);
    }

    public function increment(string $key): int
    {
        if (!apcu_exists($key)) {
            apcu_store($key, 0);
        }
        return (int)apcu_inc($key);
    }

    public function delete(string $key): void
    {
        apcu_delete($key);
    }

    public function entriesWithPrefix(string $prefix): array
    {
        $result = [];
        $info = apcu_cache_info();
        foreach ($info['cache_list'] as $entry) {
            $entryKey = $entry['info'];
            if (str_starts_with($entryKey, $prefix)) {
                // Some APCu builds omit 'value' from apcu_cache_info() entries;
                // fall back to a direct fetch so callers still get the stored value.
                $result[$entryKey] = $entry['value'] ?? apcu_fetch($entryKey);
            }
        }
        return $result;
    }
}
