<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';

/**
 * Manages the label-to-key-id registry for a device.
 * Registry entries have no TTL and persist for the lifetime of the cache.
 */
class LabelRegistry
{
    private string $deviceName;
    private KvStore $store;

    public function __construct(string $deviceName, KvStore $store)
    {
        $this->deviceName = $deviceName;
        $this->store = $store;
    }

    /**
     * Get or create the key id for a label.
     *
     * @param string $label The label
     * @return int The key id (0-based, assigned in first-seen order)
     */
    public function getOrCreateId(string $label): int
    {
        // Atomic compute-if-absent: exactly one id is ever minted per label,
        // even when concurrent first registrations race (see issue #26).
        return (int)$this->store->getOrCompute(
            $this->registryKey($label),
            fn() => $this->store->increment("kb:{$this->deviceName}:lab:next") - 1
        );
    }

    /**
     * Get the key id for a label without creating it.
     *
     * @param string $label The label
     * @return int|null The key id or null if not registered
     */
    public function getId(string $label): ?int
    {
        $key = $this->registryKey($label);

        if (!$this->store->exists($key)) {
            return null;
        }

        return (int)$this->store->get($key);
    }

    /**
     * Get all registered labels and their ids for a device.
     *
     * @return array<string, int> Label to id mapping
     */
    public function getAll(): array
    {
        $result = [];
        $prefix = "kb:{$this->deviceName}:lab:";
        $nextKey = "kb:{$this->deviceName}:lab:next";

        foreach ($this->store->entriesWithPrefix($prefix) as $entryKey => $value) {
            if ($entryKey !== $nextKey) {
                $label = substr($entryKey, strlen($prefix));
                $result[$label] = (int)$value;
            }
        }

        return $result;
    }

    /**
     * Build the registry key for a label.
     */
    private function registryKey(string $label): string
    {
        return "kb:{$this->deviceName}:lab:" . $label;
    }
}
