<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/KeyboardDefinition.php';

/**
 * Resolves alias names to device definitions, and owns the admin alias
 * actions of endpoint (d) (spec 05-alias §4): structural validation,
 * upsert, delete, and password authentication.
 *
 * Storage: alias:{name} → JSON definition, no TTL (admin-owned data,
 * like the label registry).
 */
class AliasResolver
{
    private KvStore $store;

    public function __construct(KvStore $store)
    {
        $this->store = $store;
    }

    /**
     * Handle a raw endpoint (d) request: admin password plus one alias
     * action. Success → 200 {"ok": true}; structural validation failures
     * → 400; password mismatch → 401.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(mixed $password, mixed $action, mixed $alias, mixed $kb, mixed $keys, mixed $limit): array
    {
        if (!is_string($password) || !hash_equals(ADMIN_HASH, hash('sha256', $password))) {
            return self::error(401, 'Admin password mismatch');
        }

        if (!is_string($action)) {
            return self::error(400, 'Missing or invalid action');
        }

        try {
            if ($action === 'alias') {
                return $this->upsertAction($alias, $kb, $keys, $limit);
            }
            if ($action === 'alias-del') {
                return $this->deleteAction($alias);
            }
            return self::error(400, "Unknown action: $action");
        } catch (InvalidArgumentException $e) {
            return self::error(400, $e->getMessage());
        }
    }

    /**
     * Upsert action: create or overwrite the binding. The definition is
     * validated structurally (same rules as endpoint (a)); the target
     * device need not exist yet.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function upsertAction(mixed $alias, mixed $kb, mixed $keys, mixed $limit): array
    {
        if (!is_string($alias) || !is_string($kb) || !is_string($keys)) {
            return self::error(400, 'Alias upsert requires string alias, kb, and keys');
        }
        if ($limit !== null && is_int($limit)) {
            $limit = (string)$limit;
        }
        if ($limit !== null && !is_string($limit)) {
            return self::error(400, 'Limit must be an integer');
        }

        $this->upsert($alias, $kb, $keys, $limit);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /**
     * Delete action: remove the binding; an unknown alias is a no-op.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function deleteAction(mixed $alias): array
    {
        if (!is_string($alias)) {
            return self::error(400, 'Alias delete requires a string alias');
        }
        $this->delete($alias);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /**
     * Create or overwrite an alias binding. Validation is structural and
     * happens at write time, exactly as in endpoint (a).
     *
     * @throws InvalidArgumentException on a structurally invalid definition
     */
    public function upsert(string $aliasName, string $kb, string $keys, ?string $limit): void
    {
        if (!preg_match(ALIAS_RE, $aliasName)) {
            throw new InvalidArgumentException("Invalid alias name: $aliasName");
        }

        // Structural validation of the definition alone (device name,
        // keyboard sizes, unique active labels, limit range).
        KeyboardDefinition::fromParameters($kb, $keys, $limit);

        $definition = ['kb' => $kb, 'keys' => $keys];
        if ($limit !== null) {
            $definition['limit'] = $limit;
        }
        // No TTL: admin-owned data, wiped only by an APCu restart or an
        // explicit delete.
        $this->store->set("alias:{$aliasName}", json_encode($definition));
    }

    /**
     * Remove an alias binding. An unknown alias is a no-op.
     *
     * @throws InvalidArgumentException on a malformed alias name
     */
    public function delete(string $aliasName): void
    {
        if (!preg_match(ALIAS_RE, $aliasName)) {
            throw new InvalidArgumentException("Invalid alias name: $aliasName");
        }
        $this->store->delete("alias:{$aliasName}");
    }

    /**
     * Resolve an alias to its definition.
     *
     * @param string $aliasName The alias name
     * @return array{kb: string, keys: string, limit: string|null}|null Definition or null if not found
     */
    public function resolve(string $aliasName): ?array
    {
        if (!preg_match(ALIAS_RE, $aliasName)) {
            return null;
        }

        $key = "alias:{$aliasName}";
        if (!$this->store->exists($key)) {
            return null;
        }

        $json = $this->store->get($key);
        $definition = json_decode($json, true);

        if (!is_array($definition)) {
            return null;
        }

        return [
            'kb' => $definition['kb'] ?? null,
            'keys' => $definition['keys'] ?? null,
            'limit' => $definition['limit'] ?? null,
        ];
    }

    /**
     * Check if an alias exists.
     */
    public function exists(string $aliasName): bool
    {
        if (!preg_match(ALIAS_RE, $aliasName)) {
            return false;
        }
        return $this->store->exists("alias:{$aliasName}");
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $message]];
    }
}
