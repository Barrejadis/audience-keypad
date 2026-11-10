<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';

/**
 * Clean start — admin wipe of all keypad APCu data (issue #33).
 *
 * Deletes every key under the application's own prefixes — `kb:` (all
 * device namespaces: registry, definitions, counters, logs, user data) and
 * `alias:` (admin-owned bindings) — and nothing else: APCu entries owned by
 * other software on the same host are left untouched, unlike
 * apcu_clear_cache() which would wipe them too.
 *
 * The admin password travels in the POST body and is compared with
 * hash_equals against ADMIN_HASH; mismatch → 401. The destructive action
 * must be confirmed explicitly (`confirm === true`), otherwise → 400.
 */
class CleanStartManager
{
    /** The application's own APCu key prefixes; anything else is foreign. */
    private const KEY_PREFIXES = ['kb:', 'alias:'];

    private KvStore $store;

    public function __construct(KvStore $store)
    {
        $this->store = $store;
    }

    /**
     * Handle a raw admin request: admin password plus one action.
     * Success → 200 with the wipe report; password mismatch → 401;
     * missing/unknown action or missing confirmation → 400.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(mixed $password, mixed $action, mixed $confirm): array
    {
        if (!is_string($password) || !hash_equals(ADMIN_HASH, hash('sha256', $password))) {
            return self::error(401, 'Admin password mismatch');
        }

        if (!is_string($action)) {
            return self::error(400, 'Missing or invalid action');
        }
        if ($action !== 'clean-start') {
            return self::error(400, "Unknown action: $action");
        }

        // Destructive action: an explicit confirmation flag is required so a
        // stray or replayed request cannot wipe the data by accident.
        if ($confirm !== true) {
            return self::error(400, 'Confirmation required: resend with confirm=true');
        }

        return $this->cleanStart();
    }

    /**
     * Delete every keypad key and report what was cleared: keys removed,
     * distinct devices, live sessions, aliases, and the number of keypad
     * keys left afterwards (0) to confirm the resulting clean state.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function cleanStart(): array
    {
        $keys = array_keys($this->keypadKeys());

        $devices = [];
        $sessions = 0;
        $aliases = 0;
        foreach ($keys as $key) {
            if (str_starts_with($key, 'alias:')) {
                $aliases++;
                continue;
            }
            // kb:{dev}:... — the device name contains no colon (KB_NAME_RE)
            $rest = substr($key, 3);
            $colon = strpos($rest, ':');
            if ($colon !== false) {
                $devices[substr($rest, 0, $colon)] = true;
            }
            if (preg_match('/^kb:[^:]+:u:[^:]+:keys$/', $key) === 1) {
                $sessions++;
            }
        }

        foreach ($keys as $key) {
            $this->store->delete($key);
        }

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'keys_removed' => count($keys),
                'devices' => count($devices),
                'sessions' => $sessions,
                'aliases' => $aliases,
                'remaining' => count($this->keypadKeys()),
            ],
        ];
    }

    /**
     * All stored keys owned by the keypad application.
     *
     * @return array<string, mixed> Key to value mapping
     */
    private function keypadKeys(): array
    {
        $keys = [];
        foreach (self::KEY_PREFIXES as $prefix) {
            foreach ($this->store->entriesWithPrefix($prefix) as $key => $value) {
                $keys[$key] = $value;
            }
        }
        return $keys;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $message]];
    }
}
