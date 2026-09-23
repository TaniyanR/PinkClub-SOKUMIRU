<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/images.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, max-age=300');
    header('X-Robots-Tag: noindex, nofollow', true);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['images' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$rawIds = trim((string)($_GET['ids'] ?? ''));
$ids = [];
foreach (preg_split('/\s*,\s*/', $rawIds) ?: [] as $value) {
    if ($value === '' || !ctype_digit($value)) {
        continue;
    }
    $id = (int)$value;
    if ($id > 0 && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
    if (count($ids) >= 10) {
        break;
    }
}

if ($ids === []) {
    echo json_encode(['images' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$images = [];
try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT id, image_small, raw_json FROM items WHERE id IN (' . $placeholders . ')'
    );
    foreach ($ids as $index => $id) {
        $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
    }
    $stmt->execute();

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $images[(string)$id] = item_front_cover_url($row);
    }
} catch (Throwable $e) {
    error_log('[recent_images] failed: ' . $e->getMessage());
}

echo json_encode(['images' => $images], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
