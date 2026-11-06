<?php
declare(strict_types=1);

// Endpoint (d) — alias management (spec 05-alias §4).
// Thin dispatch only: parse the JSON body, call the domain, emit the response.

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/ApcuKvStore.php';
require_once __DIR__ . '/AliasResolver.php';

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

$resolver = new AliasResolver(new ApcuKvStore());
$result = $resolver->handle(
    $request['password'] ?? null,
    $request['action'] ?? null,
    $request['alias'] ?? null,
    $request['kb'] ?? null,
    $request['keys'] ?? null,
    $request['limit'] ?? null
);
respond($result['status'], $result['body']);
