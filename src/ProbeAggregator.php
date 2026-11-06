<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/LabelRegistry.php';
require_once __DIR__ . '/KeystrokeManager.php';
require_once __DIR__ . '/ProbeException.php';
require_once __DIR__ . '/ProbeParser.php';
require_once __DIR__ . '/ProbeEvaluator.php';

/**
 * Admin aggregation — endpoint (c) domain logic (spec 02-probe §6).
 *
 * Probes: parse the program once, enumerate every uid with a live
 * kb:{dev}:u:{uid}:keys entry (sorted by uid), fold each user's keystrokes
 * in index order, and return a map uid => value as a decimal string.
 * Reset action: delete every key under the kb:{dev}: prefix.
 *
 * The admin password travels in the POST body and is compared with
 * hash_equals against ADMIN_HASH; mismatch → 401.
 */
class ProbeAggregator
{
    private KvStore $store;

    public function __construct(KvStore $store)
    {
        $this->store = $store;
    }

    /**
     * Handle a raw endpoint (c) request.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(mixed $device, mixed $password, mixed $action, mixed $init, mixed $program): array
    {
        if (!is_string($device) || !preg_match(KB_NAME_RE, $device)) {
            return self::error(400, 'Invalid or missing device name (kb)');
        }

        if (!is_string($password) || !hash_equals(ADMIN_HASH, hash('sha256', $password))) {
            return self::error(401, 'Admin password mismatch');
        }

        try {
            if ($action === 'reset') {
                return $this->reset($device);
            }
            if (!is_string($init) || !is_string($program)) {
                return self::error(400, 'Missing init or program');
            }
            return $this->aggregate($device, $init, $program);
        } catch (ProbeException $e) {
            return self::error($e->status, $e->getMessage());
        }
    }

    /**
     * Fold every live user's keystrokes on the device.
     *
     * @return array{status: int, body: array<string, string>} uid => decimal value
     * @throws ProbeException 413/422 from the probe language
     */
    public function aggregate(string $device, string $init, string $program): array
    {
        $registry = new LabelRegistry($device, $this->store);
        $ast = ProbeParser::parse($program, $registry);

        $keystrokes = new KeystrokeManager($device, $this->store);

        $body = [];
        foreach ($this->enumerateUserIds($device) as $uid) {
            $body[$uid] = ProbeEvaluator::fold($ast, $init, $keystrokes->getHistory($uid));
        }
        return ['status' => 200, 'body' => $body];
    }

    /**
     * Delete every key under the kb:{dev}: prefix (label registry, counter,
     * logs, user data). Labels re-register from id 0 afterwards.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function reset(string $device): array
    {
        $prefix = "kb:{$device}:";
        foreach (array_keys($this->store->entriesWithPrefix($prefix)) as $key) {
            $this->store->delete($key);
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /**
     * Every uid with a live kb:{dev}:u:{uid}:keys entry, sorted by uid.
     *
     * @return list<string>
     */
    private function enumerateUserIds(string $device): array
    {
        $prefix = "kb:{$device}:u:";
        $suffix = ':keys';
        $uids = [];
        foreach (array_keys($this->store->entriesWithPrefix($prefix)) as $key) {
            if (str_ends_with($key, $suffix)) {
                $uids[] = substr($key, strlen($prefix), -strlen($suffix));
            }
        }
        sort($uids, SORT_STRING);
        return $uids;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $message]];
    }
}
