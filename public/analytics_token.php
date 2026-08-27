<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/access_analytics.php';
require_once __DIR__ . '/../lib/analytics_beacon_security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);

if (!analytics_secure_same_origin_get()) {
    http_response_code(403);
    echo '{"error":"forbidden"}';
    exit;
}

$path = (string)($_GET['path'] ?? '/');
echo json_encode(['token' => analytics_secure_token($path)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
