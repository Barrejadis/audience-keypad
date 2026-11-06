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
     * Claim the device's definition lock (spec 01 §1 "Device definition").
     *
     * The first (a) served on a device records the full definition — the
     * ordered label list (kb:{dev}:keys, JSON, no TTL), the mode, and the
     * limit when present — and never changes it afterwards. Each record is
     * claimed atomically via KvStore::getOrCompute, in a fixed order, and
     * compared immediately after its claim: a concurrent cold load with a
     * different definition loses at the first divergent record and never
     * writes past it, so exactly one of two racing claims succeeds.
     *
     * @param KeyboardDefinition $definition
     * @return bool True if the device's recorded definition is exactly this
     *              one (claimed now or by an earlier identical load),
     *              false on any conflict (mode, limit, or label list)
     */
    public function claimDefinition(KeyboardDefinition $definition): bool
    {
        // 1. Ordered label list — claimed atomically; the first claim defines it.
        $claimedKeys = (string)$this->store->getOrCompute(
            "kb:{$this->deviceName}:keys",
            fn() => json_encode($definition->labels)
        );
        $recordedLabels = json_decode($claimedKeys, true);
        if (!is_array($recordedLabels) || $recordedLabels !== $definition->labels) {
            return false;
        }

        // 2. Mode — claimed atomically as well.
        $recordedMode = (string)$this->store->getOrCompute(
            "kb:{$this->deviceName}:mode",
            fn() => $definition->mode
        );
        if ($recordedMode !== $definition->mode) {
            return false;
        }

        // 3. Limit — present exactly on limited devices, claimed atomically.
        // A main-mode definition must find no limit record at all.
        if ($definition->limit !== null) {
            $recordedLimit = (int)$this->store->getOrCompute(
                "kb:{$this->deviceName}:limit",
                fn() => $definition->limit
            );
        } elseif ($this->store->exists("kb:{$this->deviceName}:limit")) {
            $recordedLimit = (int)$this->store->get("kb:{$this->deviceName}:limit");
        } else {
            $recordedLimit = null;
        }

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
