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
require_once __DIR__ . '/KeystrokeManager.php';

header('Content-Type: application/json');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$kb = $_POST['kb'] ?? null;
$key = $_POST['key'] ?? null;
$undo = $_POST['undo'] ?? null;

if (!is_string($kb) || $kb === '') {
    respond(400, ['ok' => false, 'error' => 'Missing device name (kb parameter)']);
}

// A keystroke belongs to the keyboard that displayed it: endpoint (b) never
// accepts or resolves an alias parameter (spec 05-alias §5).
if (isset($_POST['alias'])) {
    respond(400, ['ok' => false, 'error' => 'The alias parameter is not accepted here']);
}

try {
    $manager = new KeystrokeManager($kb, new ApcuKvStore());
} catch (InvalidArgumentException $e) {
    respond(400, ['ok' => false, 'error' => $e->getMessage()]);
}

$result = $manager->handle(session_id(), $key, $undo);
respond($result['status'], $result['body']);
