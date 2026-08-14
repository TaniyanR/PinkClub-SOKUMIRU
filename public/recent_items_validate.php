<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

$rawIds = preg_split('/\s*,\s*/', trim((string)get('ids', ''))) ?: [];
$ids = [];
foreach ($rawIds as $rawId) {
    if (preg_match('/^\d+$/', (string)$rawId) !== 1) {
        continue;
    }
    $id = (int)$rawId;
    if ($id > 0 && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
    if (count($ids) >= 20) {
        break;
    }
}

if ($ids === []) {
    echo json_encode(['valid_ids' => []], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT id FROM items WHERE id IN (' . $placeholders . ') AND ' . items_product_source_where('items')
    );
    $stmt->execute($ids);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    echo json_encode(['valid_ids' => $validIds], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('recent_items_validate.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'validation_failed'], JSON_UNESCAPED_SLASHES);
}
