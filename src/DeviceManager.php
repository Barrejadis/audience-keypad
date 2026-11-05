<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KeyboardDefinition.php';

/**
 * Manages device-level state and storage operations.
 */
class DeviceManager
{
    private string $deviceName;

    public function __construct(string $deviceName)
    {
        $this->deviceName = $deviceName;
    }

    /**
     * Check if device exists (has a recorded mode).
     */
    public function exists(): bool
    {
        return apcu_exists("kb:{$this->deviceName}:mode");
    }

    /**
     * Get the recorded device mode.
     * 
     * @return string|null 'main' or 'limited', or null if not recorded
     */
    public function getMode(): ?string
    {
        $key = "kb:{$this->deviceName}:mode";
        if (!apcu_exists($key)) {
            return null;
        }
        return apcu_fetch($key);
    }

    /**
     * Get the recorded limit for a limited device.
     * 
     * @return int|null The limit or null if device is main mode or not recorded
     */
    public function getLimit(): ?int
    {
        $key = "kb:{$this->deviceName}:limit";
        if (!apcu_exists($key)) {
            return null;
        }
        return (int)apcu_fetch($key);
    }

    /**
     * Record the device mode and limit (first access).
     * 
     * @param string $mode 'main' or 'limited'
     * @param int|null $limit The limit for limited mode
     */
    public function recordMode(string $mode, ?int $limit): void
    {
        apcu_store("kb:{$this->deviceName}:mode", $mode);
        if ($limit !== null) {
            apcu_store("kb:{$this->deviceName}:limit", $limit);
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
        apcu_store($key, json_encode($keyIds), KB_TTL);
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
        if (!apcu_exists($key)) {
            return [];
        }
        $json = apcu_fetch($key);
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
        apcu_store($key, $aliasName, KB_TTL);
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
        if (!apcu_exists($key)) {
            return null;
        }
        return apcu_fetch($key);
    }
}
