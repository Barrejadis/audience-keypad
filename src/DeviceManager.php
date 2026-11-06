<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KeyboardDefinition.php';
require_once __DIR__ . '/KvStore.php';

/**
 * Manages device-level state and storage operations.
 */
class DeviceManager
{
    private string $deviceName;
    private KvStore $store;

    public function __construct(string $deviceName, KvStore $store)
    {
        $this->deviceName = $deviceName;
        $this->store = $store;
    }

    /**
     * Check if device exists (has a recorded mode).
     */
    public function exists(): bool
    {
        return $this->store->exists("kb:{$this->deviceName}:mode");
    }

    /**
     * Get the recorded device mode.
     *
     * @return string|null 'main' or 'limited', or null if not recorded
     */
    public function getMode(): ?string
    {
        $key = "kb:{$this->deviceName}:mode";
        if (!$this->store->exists($key)) {
            return null;
        }
        return $this->store->get($key);
    }

    /**
     * Get the recorded limit for a limited device.
     *
     * @return int|null The limit or null if device is main mode or not recorded
     */
    public function getLimit(): ?int
    {
        $key = "kb:{$this->deviceName}:limit";
        if (!$this->store->exists($key)) {
            return null;
        }
        return (int)$this->store->get($key);
    }

    /**
     * Record the device mode and limit (first access).
     *
     * @param string $mode 'main' or 'limited'
     * @param int|null $limit The limit for limited mode
     */
    public function recordMode(string $mode, ?int $limit): void
    {
        $this->store->set("kb:{$this->deviceName}:mode", $mode);
        if ($limit !== null) {
            $this->store->set("kb:{$this->deviceName}:limit", $limit);
        }
    }

    /**
     * Validate a keyboard definition against recorded device state.
     *
     * @param KeyboardDefinition $definition
     * @return bool True if valid, false if there's a conflict
     */
    public function validateDefinition(KeyboardDefinition $definition): bool
    {
        $recordedMode = $this->getMode();
        $recordedLimit = $this->getLimit();

        if ($recordedMode === null) {
            // First access - always valid
            return true;
        }

        // Check for conflict
        return !$definition->conflictsWith($recordedMode, $recordedLimit);
    }

    /**
     * Store the user's active key ids.
     *
     * @param string $uid User session id
     * @param list<int> $keyIds Active key ids for this keyboard
     */
    public function storeUserKeys(string $uid, array $keyIds): void
    {
        $key = "kb:{$this->deviceName}:u:{$uid}:keys";
        $this->store->set($key, json_encode($keyIds), KB_TTL);
    }

    /**
     * Get the user's active key ids.
     *
     * @param string $uid User session id
     * @return list<int> Active key ids, empty array if not found
     */
    public function getUserKeys(string $uid): array
    {
        $key = "kb:{$this->deviceName}:u:{$uid}:keys";
        if (!$this->store->exists($key)) {
            return [];
        }
        $json = $this->store->get($key);
        return json_decode($json, true) ?: [];
    }

    /**
     * Record that the user entered via an alias.
     *
     * @param string $uid User session id
     * @param string $aliasName The alias name
     */
    public function recordAliasEntry(string $uid, string $aliasName): void
    {
        $key = "kb:{$this->deviceName}:u:{$uid}:via";
        $this->store->set($key, $aliasName, KB_TTL);
    }

    /**
     * Get the alias entry marker for a user.
     *
     * @param string $uid User session id
     * @return string|null The alias name or null
     */
    public function getAliasEntry(string $uid): ?string
    {
        $key = "kb:{$this->deviceName}:u:{$uid}:via";
        if (!$this->store->exists($key)) {
            return null;
        }
        return $this->store->get($key);
    }

    /**
     * Clear the alias entry marker (direct entry: last entry wins).
     *
     * @param string $uid User session id
     */
    public function clearAliasEntry(string $uid): void
    {
        $this->store->delete("kb:{$this->deviceName}:u:{$uid}:via");
    }
}
