<?php
/**
 * Dev tool: router script for PHP's built-in web server that records every
 * request it receives (time, method, path, headers, raw body) as one JSON
 * line in captured/requests.jsonl and answers with CAPTURE_STATUS (default
 * 200). Used behind a public tunnel to see what Quickshipper's webhooks
 * send; nothing here is part of the module.
 *
 * With FORWARD_TO set (e.g. http://localhost:8080), a POST to the store's
 * status webhook path is also passed on to that store, and the store's answer
 * is what Quickshipper gets (and what is recorded). Nothing else is forwarded,
 * so the tunnel never exposes the rest of the store.
 */

declare(strict_types=1);

$dir = __DIR__ . '/captured';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

const FORWARD_PATH = '/qshpr/webhook/status';

$entry = [
    'received_at' => gmdate('Y-m-d\TH:i:s.') . substr((string)microtime(), 2, 3) . 'Z',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'remote' => $_SERVER['REMOTE_ADDR'] ?? '',
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => (string)file_get_contents('php://input'),
];
$status = (int)(getenv('CAPTURE_STATUS') ?: 200);
$answer = json_encode(['ok' => $status < 300]);
$forwardTo = rtrim((string)getenv('FORWARD_TO'), '/');

if ($forwardTo !== '' && $entry['method'] === 'POST' && strtok($entry['uri'], '?') === FORWARD_PATH) {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $entry['body'],
        'timeout' => 25,
        'ignore_errors' => true,
    ]]);
    $answer = @file_get_contents($forwardTo . $entry['uri'], false, $context);
    $status = 502;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    $entry['forwarded'] = ['status' => $status, 'answer' => substr((string)$answer, 0, 500)];
}

// The webhook URL carries the store's key: never keep it on disk.
$entry['uri'] = (string)preg_replace('/([?&]key=)[^&]*/', '$1***', $entry['uri']);

file_put_contents(
    $dir . '/requests.jsonl',
    json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);

http_response_code($status);
header('Content-Type: application/json');
echo $answer === false ? '{}' : $answer;
