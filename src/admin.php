<?php
declare(strict_types=1);

// Endpoint — admin actions (clean start, issue #33).
// Thin dispatch only: parse the JSON body, call the domain, emit the response.

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/ApcuKvStore.php';
require_once __DIR__ . '/CleanStartManager.php';

header('Content-Type: application/json');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$request = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($request)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$manager = new CleanStartManager(new ApcuKvStore());
$result = $manager->handle(
    $request['password'] ?? null,
    $request['action'] ?? null,
    $request['confirm'] ?? null
);
respond($result['status'], $result['body']);
