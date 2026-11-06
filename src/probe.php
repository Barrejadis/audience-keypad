<?php
declare(strict_types=1);

// Endpoint (c) — admin aggregation and reset (spec 02-probe §6).
// Thin dispatch only: parse the JSON body, call the domain, emit the response.

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/KvStore.php';
require_once __DIR__ . '/ApcuKvStore.php';
require_once __DIR__ . '/ProbeException.php';
require_once __DIR__ . '/ProbeParser.php';
require_once __DIR__ . '/ProbeEvaluator.php';
require_once __DIR__ . '/ProbeAggregator.php';

header('Content-Type: application/json');

function respond(int $status, array $body): void
{
    http_response_code($status);
    // JSON_FORCE_OBJECT: the result map's uids are string keys, but a purely
    // numeric uid would otherwise be encoded as a JSON array.
    echo json_encode($body, JSON_FORCE_OBJECT);
    exit;
}

$request = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($request)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$aggregator = new ProbeAggregator(new ApcuKvStore());
$result = $aggregator->handle(
    $request['kb'] ?? null,
    $request['password'] ?? null,
    $request['action'] ?? null,
    $request['init'] ?? null,
    $request['program'] ?? null
);
respond($result['status'], $result['body']);
