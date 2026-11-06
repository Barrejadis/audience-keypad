<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/DeviceManager.php';
require_once __DIR__ . '/AliasResolver.php';

/**
 * Keystroke capture and undo for one device (endpoint (b) domain logic).
 *
 * Per-user state lives in the store under kb:{dev}:u:{uid}: (Storage spec):
 *   keys  JSON array of the user's active key ids (written by endpoint (a))
 *   n     keystroke counter; the atomic increment is the index source,
 *         so the per-user index order equals press order
 *   {i}   key id of keystroke i
 *
 * The cap is per user per device name: L on a limited device,
 * MAX_STROKES otherwise. An undo restores budget.
 */
class KeystrokeManager
{
    private string $deviceName;
    private KvStore $store;
    private DeviceManager $devices;

    public function __construct(string $deviceName, KvStore $store)
    {
        if (!preg_match(KB_NAME_RE, $deviceName)) {
            throw new InvalidArgumentException("Invalid device name: $deviceName");
        }
        $this->deviceName = $deviceName;
        $this->store = $store;
        $this->devices = new DeviceManager($deviceName, $store);
    }

    /**
     * Handle a raw endpoint (b) request: exactly one of $key (integer key
     * id as a string) or $undo must be non-null.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $uid, mixed $key, mixed $undo): array
    {
        $hasKey = $key !== null;
        $hasUndo = $undo !== null;

        if ($hasKey === $hasUndo) {
            return self::error(400, 'Exactly one of key or undo must be provided');
        }

        // Every well-formed (b) request refreshes all per-user TTLs
        // (spec 03-storage §2), so an active session never loses data.
        $this->refreshUserTtl($uid);

        if ($hasKey) {
            if (!is_string($key) || !ctype_digit($key)) {
                return self::error(400, 'Invalid key id');
            }
            return $this->record($uid, (int)$key);
        }

        return $this->undo($uid);
    }

    /**
     * Record one keystroke for the user.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function record(string $uid, int $keyId): array
    {
        // Routing check (spec 05-alias §5), before the command processing:
        // a user who entered this device via an alias is rerouted when the
        // alias is deleted or retargeted to another device. The keystroke is
        // dropped — nothing is recorded or budgeted.
        $reroute = $this->checkReroute($uid);
        if ($reroute !== null) {
            return $reroute;
        }

        // The key id must be one of the user's active keys on this device;
        // this also rejects wrong device names and sessions that never
        // loaded a keyboard here.
        if (!in_array($keyId, $this->devices->getUserKeys($uid), true)) {
            return self::error(400, 'Unregistered key id for this user on this device');
        }

        $cap = $this->getCap();
        $counterKey = $this->counterKey($uid);

        $n = $this->store->increment($counterKey);
        if ($n > $cap) {
            // The stroke is not stored; roll the counter back so the log
            // keeps no hole at its tail and undo erases the true last stroke.
            $this->store->set($counterKey, $n - 1, KB_TTL);
            return self::error(429, 'Keystroke cap reached for this device');
        }

        $this->store->set($this->strokeKey($uid, $n - 1), $keyId, KB_TTL);
        $this->store->set($counterKey, $n, KB_TTL);
        $this->refreshKeysTtl($uid);

        return ['status' => 200, 'body' => ['ok' => true, 'remaining' => $cap - $n]];
    }

    /**
     * Erase the last recorded keystroke of the user (limited devices only).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function undo(string $uid): array
    {
        // Routing check applies to undo as well (spec 05-alias §5)
        $reroute = $this->checkReroute($uid);
        if ($reroute !== null) {
            return $reroute;
        }

        if ($this->devices->getMode() !== 'limited') {
            return self::error(400, 'Undo is only available on a limited device');
        }

        $limit = $this->devices->getLimit() ?? MAX_STROKES;
        $n = $this->getCount($uid);
        if ($n <= 0) {
            return self::error(400, 'No recorded keystroke to erase');
        }

        $this->store->delete($this->strokeKey($uid, $n - 1));
        $this->store->set($this->counterKey($uid), $n - 1, KB_TTL);
        $this->refreshKeysTtl($uid);

        return ['status' => 200, 'body' => ['ok' => true, 'remaining' => $limit - ($n - 1)]];
    }

    /**
     * Rerouting check: if the user entered this device through an alias
     * (entry marker kb:{dev}:u:{uid}:via), resolve the alias's current
     * binding. Unchanged (target equals this device) → null, normal
     * processing. Deleted or retargeted → 409 with reroute=true. The
     * modification criterion is the target device name only.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function checkReroute(string $uid): ?array
    {
        $via = $this->devices->getAliasEntry($uid);
        if ($via === null) {
            return null;
        }

        // The marker is a per-user entry: refresh its TTL at every routing
        // check (spec 03-storage §2), so an active session keeps its
        // reroute protection for as long as it keeps pressing.
        $this->devices->recordAliasEntry($uid, $via);

        $resolver = new AliasResolver($this->store);
        $definition = $resolver->resolve($via);

        if ($definition !== null && $definition['kb'] === $this->deviceName) {
            return null;
        }

        return ['status' => 409, 'body' => ['ok' => false, 'reroute' => true]];
    }

    /**
     * The user's recorded keystroke count on this device.
     */
    public function getCount(string $uid): int
    {
        $key = $this->counterKey($uid);
        if (!$this->store->exists($key)) {
            return 0;
        }
        return (int)$this->store->get($key);
    }

    /**
     * The user's keystroke history on this device, oldest first.
     *
     * @return list<int>
     */
    public function getHistory(string $uid): array
    {
        $history = [];
        $n = $this->getCount($uid);
        for ($i = 0; $i < $n; $i++) {
            $key = $this->strokeKey($uid, $i);
            if ($this->store->exists($key)) {
                $history[] = (int)$this->store->get($key);
            }
        }
        return $history;
    }

    /**
     * The keystroke cap for this device: L in limited mode, MAX_STROKES otherwise.
     */
    private function getCap(): int
    {
        if ($this->devices->getMode() === 'limited') {
            return $this->devices->getLimit() ?? MAX_STROKES;
        }
        return MAX_STROKES;
    }

    /**
     * Refresh all per-user TTLs on this device (spec 03-storage §2): the
     * counter, every stored stroke and the active key set are re-stored
     * with a fresh KB_TTL. An active session therefore never loses data,
     * while an abandoned one expires as a whole. Called on every (a) and
     * (b) request.
     */
    public function refreshUserTtl(string $uid): void
    {
        $n = $this->getCount($uid);
        if ($n > 0) {
            $this->store->set($this->counterKey($uid), $n, KB_TTL);
            for ($i = 0; $i < $n; $i++) {
                $key = $this->strokeKey($uid, $i);
                if ($this->store->exists($key)) {
                    $this->store->set($key, (int)$this->store->get($key), KB_TTL);
                }
            }
        }
        $this->refreshKeysTtl($uid);
    }

    /**
     * Refresh the per-user TTL of the active key set (all per-user TTLs are
     * refreshed on (a) and (b)).
     */
    private function refreshKeysTtl(string $uid): void
    {
        $keys = $this->devices->getUserKeys($uid);
        if ($keys !== []) {
            $this->devices->storeUserKeys($uid, $keys);
        }
    }

    private function counterKey(string $uid): string
    {
        return "kb:{$this->deviceName}:u:{$uid}:n";
    }

    private function strokeKey(string $uid, int $index): string
    {
        return "kb:{$this->deviceName}:u:{$uid}:{$index}";
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $message]];
    }
}
