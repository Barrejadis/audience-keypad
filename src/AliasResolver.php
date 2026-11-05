<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';

/**
 * Resolves alias names to device definitions.
 */
class AliasResolver
{
    /**
     * Resolve an alias to its definition.
     * 
     * @param string $aliasName The alias name
     * @return array{kb: string, keys: string, limit: string|null}|null Definition or null if not found
     */
    public static function resolve(string $aliasName): ?array
    {
        if (!preg_match(KB_NAME_RE, $aliasName)) {
            return null;
        }

        $key = "alias:{$aliasName}";
        if (!apcu_exists($key)) {
            return null;
        }

        $json = apcu_fetch($key);
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
    public static function exists(string $aliasName): bool
    {
        if (!preg_match(KB_NAME_RE, $aliasName)) {
            return false;
        }
        return apcu_exists("alias:{$aliasName}");
    }
}
