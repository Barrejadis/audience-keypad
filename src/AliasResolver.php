<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';

/**
 * Resolves alias names to device definitions.
 */
class AliasResolver
{
    private KvStore $store;

    public function __construct(KvStore $store)
    {
        $this->store = $store;
    }

    /**
     * Resolve an alias to its definition.
     *
     * @param string $aliasName The alias name
     * @return array{kb: string, keys: string, limit: string|null}|null Definition or null if not found
     */
    public function resolve(string $aliasName): ?array
    {
        if (!preg_match(KB_NAME_RE, $aliasName)) {
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
        if (!preg_match(KB_NAME_RE, $aliasName)) {
            return false;
        }
        return $this->store->exists("alias:{$aliasName}");
    }
}
