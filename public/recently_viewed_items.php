<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/lib/repository.php';
require_once __DIR__ . '/partials/public_ui.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ids = [];
foreach (explode(',', trim((string)($_GET['ids'] ?? ''))) as $value) {
    $id = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id !== false && !in_array((int)$id, $ids, true)) {
        $ids[] = (int)$id;
    }
    if (count($ids) >= 10) {
        break;
    }
}

if ($ids === []) {
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$normalizeImageUrl = static function (mixed $value): string {
    $url = trim((string)$value);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (str_starts_with($url, 'http://')) {
        $url = 'https://' . substr($url, 7);
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if ($scheme !== 'https' || $host === '') {
        return '';
    }
    if ($host !== 'sokmil.com' && !str_ends_with($host, '.sokmil.com')
        && $host !== 'sokmil-ad.com' && !str_ends_with($host, '.sokmil-ad.com')) {
        return '';
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
};

$rowsById = [];
try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT id, title, image_small, image_large, image_list, raw_json '
        . 'FROM items WHERE id IN (' . $placeholders . ') AND ' . items_product_source_where('items')
    );
    foreach ($ids as $index => $id) {
        $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rowId = (int)($row['id'] ?? 0);
        if ($rowId > 0) {
            $rowsById[$rowId] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('[recently_viewed_items] failed: ' . $e->getMessage());
    // A database failure must not be mistaken for deleted products by the browser.
    http_response_code(503);
    echo json_encode(['error' => '履歴の商品情報を取得できませんでした。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
foreach ($ids as $id) {
    $row = $rowsById[$id] ?? null;
    if (!is_array($row)) {
        continue;
    }

    $raw = [];
    $rawJson = trim((string)($row['raw_json'] ?? ''));
    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    // SOKUMIRUの商品表紙を優先する。見開き/フルパッケージ候補は履歴カードでは使わない。
    $imageCandidates = [
        $normalizeImageUrl($raw['imageURL']['large'] ?? ''),
        $normalizeImageUrl($raw['imageURL']['small'] ?? ''),
        $normalizeImageUrl($row['image_small'] ?? ''),
        $normalizeImageUrl($row['image_large'] ?? ''),
    ];
    $imageCandidates = array_values(array_unique(array_filter($imageCandidates)));
    $image = (string)($imageCandidates[0] ?? '');

    $items[] = [
        'id' => $id,
        'title' => pcf_item_title($row),
        'image' => $image,
        'image_fallbacks' => array_slice($imageCandidates, 1),
        'url' => public_url('item.php?id=' . $id),
    ];
}

echo json_encode(
    ['items' => $items],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
