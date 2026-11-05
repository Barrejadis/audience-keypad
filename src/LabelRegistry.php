<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';

/**
 * Manages the label-to-key-id registry for a device.
 * Registry entries have no TTL and persist for the lifetime of the APCu cache.
 */
class LabelRegistry
{
    private string $deviceName;

    public function __construct(string $deviceName)
    {
        $this->deviceName = $deviceName;
    }

    /**
     * Get or create the key id for a label.
     * 
     * @param string $label The label
     * @return int The key id (0-based, assigned in first-seen order)
     */
    public function getOrCreateId(string $label): int
    {
        $key = $this->registryKey($label);
        
        if (apcu_exists($key)) {
            return (int)apcu_fetch($key);
        }

        // Get and increment the next id counter
        $nextKey = "kb:{$this->deviceName}:lab:next";
        
        if (!apcu_exists($nextKey)) {
            apcu_store($nextKey, 0);
        }
        
        $nextId = apcu_inc($nextKey);
        $id = $nextId - 1;

        apcu_store($key, $id);
        return $id;
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
        
        if (!apcu_exists($key)) {
            return null;
        }

        return (int)apcu_fetch($key);
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
        
        $info = apcu_cache_info();
        foreach ($info['cache_list'] as $entry) {
            $entryKey = $entry['info'];
            if (str_starts_with($entryKey, $prefix) && $entryKey !== "kb:{$this->deviceName}:lab:next") {
                $label = substr($entryKey, strlen($prefix));
                $result[$label] = (int)$entry['value'];
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
