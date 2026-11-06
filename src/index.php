<?php
declare(strict_types=1);

// Session configuration (Security spec)
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
session_start();

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/ApcuKvStore.php';
require_once __DIR__ . '/KeyboardDefinition.php';
require_once __DIR__ . '/LabelRegistry.php';
require_once __DIR__ . '/DeviceManager.php';
require_once __DIR__ . '/KeystrokeManager.php';
require_once __DIR__ . '/KeyboardRenderer.php';
require_once __DIR__ . '/AliasResolver.php';

$store = new ApcuKvStore();

// Parse request parameters
$kb = $_GET['kb'] ?? null;
$keys = $_GET['keys'] ?? null;
$limit = $_GET['limit'] ?? null;
$alias = $_GET['alias'] ?? null;

$uid = session_id();

// Check if alias parameter is present
if ($alias !== null) {
    // Alias entry
    if ($kb !== null || $keys !== null || $limit !== null) {
        // alias combined with kb/keys/limit -> 400
        http_response_code(400);
        echo "Error: alias parameter cannot be combined with kb, keys, or limit";
        exit;
    }

    // Validate alias name
    if (!preg_match(ALIAS_RE, $alias)) {
        http_response_code(400);
        echo "Error: Invalid alias name";
        exit;
    }

    // Resolve alias
    $resolver = new AliasResolver($store);
    $definition = $resolver->resolve($alias);

    if ($definition === null) {
        // Unknown alias -> 404, the system's only 404: a well-formed name
        // with no binding, as opposed to the 400 family of malformed requests.
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Unknown alias</title></head>' . "\n"
            . '<body><p>Unknown alias <strong>' . htmlspecialchars($alias, ENT_QUOTES) . '</strong>.</p>' . "\n"
            . '<p>This link has no binding. Ask the administrator for a valid link.</p></body>' . "\n"
            . '</html>';
        exit;
    }

    $kb = $definition['kb'];
    $keys = $definition['keys'];
    $limit = $definition['limit'];
    $aliasName = $alias;
} else {
    $aliasName = null;
}

// Now we have kb, keys, limit from direct params or alias resolution
if ($kb === null) {
    http_response_code(400);
    echo "Error: Missing device name (kb parameter)";
    exit;
}

if ($keys === null) {
    http_response_code(400);
    echo "Error: Missing keyboard definition (keys parameter)";
    exit;
}

// Parse and validate keyboard definition
try {
    $kbd = KeyboardDefinition::fromParameters($kb, $keys, $limit);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit;
}

// Check device manager
$deviceManager = new DeviceManager($kb, $store);

// Check for mode conflict
if ($deviceManager->exists()) {
    $recordedMode = $deviceManager->getMode();
    $recordedLimit = $deviceManager->getLimit();
    
    if ($kbd->conflictsWith($recordedMode, $recordedLimit)) {
        http_response_code(400);
        $limitStr = $recordedLimit !== null ? " with limit=$recordedLimit" : "";
        echo "Error: Device '$kb' already exists in $recordedMode mode$limitStr";
        exit;
    }
} else {
    // First access - record the mode
    $deviceManager->recordMode($kbd->mode, $kbd->limit);
}

// Register labels and build label->id mapping
$registry = new LabelRegistry($kb, $store);
$labelToId = [];
$userKeyIds = [];

foreach ($kbd->getActiveLabels() as $label) {
    $id = $registry->getOrCreateId($label);
    $labelToId[$label] = $id;
    $userKeyIds[] = $id;
}

// Store user's active key set
$deviceManager->storeUserKeys($uid, $userKeyIds);

// Entry marker: alias entry records it, direct entry clears any existing
// one (last entry wins — a direct entrant is never rerouted).
if ($aliasName !== null) {
    $deviceManager->recordAliasEntry($uid, $aliasName);
} else {
    $deviceManager->clearAliasEntry($uid);
}

// Refresh all per-user TTLs on every (a) request (spec 03-storage §2):
// keys, the alias entry marker, the counter and every stored stroke stay
// alive while the session is active and expire as a whole when abandoned.
$keystrokes = new KeystrokeManager($kb, $store);
$keystrokes->refreshUserTtl($uid);

// For limited mode, get user's history
$history = [];
$remaining = 0;

if ($kbd->mode === 'limited') {
    // Get user's keystroke history and remaining allowance
    $n = $keystrokes->getCount($uid);
    $history = $keystrokes->getHistory($uid);
    $remaining = ($kbd->limit ?? MAX_STROKES) - $n;
}

// Render the page
$renderer = new KeyboardRenderer(
    $kbd,
    $labelToId,
    $userKeyIds,
    $history,
    $remaining
);

echo $renderer->render();
