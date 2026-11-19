<?php

declare(strict_types=1);

// Mock of the sales notifier API: fails with MOCK_FAILURE_STATUS (default 503) at MOCK_FAILURE_RATE (0..1), logs every notification.
$rate = getenv('MOCK_FAILURE_RATE');
$failureRate = false === $rate ? 0.3 : (float) $rate;
$failureStatus = (int) (getenv('MOCK_FAILURE_STATUS') ?: 503);

if ('/health' === $_SERVER['REQUEST_URI']) {
    echo 'ok';

    return;
}

$body = (string) file_get_contents('php://input');
$failed = mt_rand() / mt_getrandmax() < $failureRate;
file_put_contents('php://stdout', sprintf("[notifier] %d %s %s\n", $failed ? $failureStatus : 200, $_SERVER['REQUEST_URI'], $body));

http_response_code($failed ? $failureStatus : 200);
header('Content-Type: application/json');
echo $failed ? '{"error":"simulated failure"}' : '{"status":"received"}';
