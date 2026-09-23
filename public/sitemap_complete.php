<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/contact_page_slug.php';

try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    http_response_code(503);
    header('Retry-After: 300');
    header('Cache-Control: no-store');
    exit;
}
header('Content-Type: application/xml; charset=UTF-8');
require_once __DIR__ . '/../lib/public_page_cache.php';
pcf_public_page_cache_start(600);

function sitemap_complete_e(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function sitemap_complete_url(string $loc, string $changefreq, string $priority, string $lastmod = ''): void
{
    echo "  <url>\n";
    echo '    <loc>' . sitemap_complete_e($loc) . "</loc>\n";
    if ($lastmod !== '') {
        echo '    <lastmod>' . sitemap_complete_e(substr($lastmod, 0, 10)) . "</lastmod>\n";
    }
    echo '    <changefreq>' . sitemap_complete_e($changefreq) . "</changefreq>\n";
    echo '    <priority>' . sitemap_complete_e($priority) . "</priority>\n";
    echo "  </url>\n";
}

function sitemap_complete_product_where(string $alias): string
{
    return items_product_source_where($alias);
}

function sitemap_complete_master_sources(): array
{
    $sources = [];
    if (db_table_exists('items')) {
        $sources[] = [
            'from' => 'items entity',
            'path' => 'item.php',
            'changefreq' => 'weekly',
            'priority' => '0.8',
            'where' => sitemap_complete_product_where('entity'),
        ];
    }

    $masterSources = [
        'genres' => ['relation' => 'item_genres', 'path' => 'genre.php'],
        'series_master' => ['relation' => 'item_series', 'path' => 'series_detail.php'],
        'actresses' => ['relation' => 'item_actresses', 'path' => 'actress.php'],
        'makers' => ['relation' => 'item_makers', 'path' => 'maker.php'],
    ];

    foreach ($masterSources as $table => $config) {
        $relation = (string)$config['relation'];
        if (!db_table_exists($table) || !db_table_exists($relation)) {
            continue;
        }
        $where = [
            "TRIM(COALESCE(entity.name, '')) <> ''",
            "LOWER(entity.name) NOT LIKE '%http://%'",
            "LOWER(entity.name) NOT LIKE '%https://%'",
            "LOWER(entity.name) NOT LIKE '%www.%'",
            "entity.name NOT LIKE '%/%'",
        ];
        if ($table === 'actresses') {
            $where[] = "entity.dmm_id REGEXP '^[0-9]+$'";
        }
        if ($table === 'series_master') {
            $redirectSeriesIds = array_keys(series_canonical_maker_redirects());
            if ($redirectSeriesIds !== []) {
                $where[] = 'entity.id NOT IN (' . implode(',', array_map('intval', $redirectSeriesIds)) . ')';
            }
        }
        if ($table === 'makers' && db_table_exists('mutual_links')) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM mutual_links ml WHERE ml.site_name = entity.name)';
        }

        if (db_column_exists($relation, 'item_id')) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . $relation . ' relation_row INNER JOIN items related_item ON related_item.id = relation_row.item_id WHERE relation_row.dmm_id = entity.dmm_id AND ' . sitemap_complete_product_where('related_item') . ')';
        } else {
            $legacyIdColumn = match ($table) {
                'genres' => 'genre_id',
                'series_master' => 'series_id',
                'actresses' => 'actress_id',
                'makers' => 'maker_id',
            };
            $where[] = 'EXISTS (SELECT 1 FROM ' . $relation . ' relation_row INNER JOIN items related_item ON related_item.content_id = relation_row.content_id WHERE relation_row.' . $legacyIdColumn . ' = entity.id AND ' . sitemap_complete_product_where('related_item') . ')';
        }

        $sources[] = [
            'from' => $table . ' entity',
            'path' => (string)$config['path'],
            'changefreq' => 'weekly',
            'priority' => '0.7',
            'where' => implode(' AND ', $where),
        ];
    }
    return $sources;
}

function sitemap_complete_source_count(array $source): int
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM ' . $source['from'] . ' WHERE ' . $source['where'])->fetchColumn();
    } catch (Throwable $e) {
        error_log('sitemap count failed: ' . $source['from'] . ': ' . $e->getMessage());
        return 0;
    }
}

function sitemap_complete_emit_source(array $source, int $start, int &$remaining): int
{
    $count = sitemap_complete_source_count($source);
    if ($remaining <= 0 || $start >= $count) {
        return $count;
    }
    $limit = min($remaining, $count - $start);
    try {
        $stmt = db()->prepare('SELECT entity.id FROM ' . $source['from'] . ' WHERE ' . $source['where'] . ' ORDER BY entity.id ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            sitemap_complete_url(public_url((string)$source['path']) . '?id=' . rawurlencode((string)$id), (string)$source['changefreq'], (string)$source['priority']);
            $remaining--;
        }
    } catch (Throwable $e) {
        error_log('sitemap rows failed: ' . $source['from'] . ': ' . $e->getMessage());
    }
    return $count;
}

function sitemap_complete_label_count(): int
{
    if (!db_table_exists('item_labels') || !db_table_exists('items')) return 0;
    try {
        if (db_column_exists('item_labels', 'item_id')) {
            $sql = 'SELECT COUNT(*) FROM (SELECT COALESCE(NULLIF(il.dmm_id, ""), il.label_name) label_id FROM item_labels il INNER JOIN items i ON i.id = il.item_id WHERE ' . sitemap_complete_product_where('i') . ' AND TRIM(COALESCE(il.label_name,"")) <> "" GROUP BY COALESCE(NULLIF(il.dmm_id, ""), il.label_name), il.label_name) labels_count';
        } else {
            $sql = 'SELECT COUNT(*) FROM (SELECT il.label_id FROM item_labels il INNER JOIN items i ON i.content_id = il.content_id WHERE ' . sitemap_complete_product_where('i') . ' AND TRIM(COALESCE(il.label_name,"")) <> "" GROUP BY il.label_id, il.label_name) labels_count';
        }
        return (int)db()->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        error_log('sitemap label count failed: ' . $e->getMessage());
        return 0;
    }
}

function sitemap_complete_emit_labels(int $start, int &$remaining): int
{
    $count = sitemap_complete_label_count();
    if ($remaining <= 0 || $start >= $count) return $count;
    $limit = min($remaining, $count - $start);
    try {
        if (db_column_exists('item_labels', 'item_id')) {
            $sql = 'SELECT COALESCE(NULLIF(il.dmm_id, ""), il.label_name) id FROM item_labels il INNER JOIN items i ON i.id = il.item_id WHERE ' . sitemap_complete_product_where('i') . ' AND TRIM(COALESCE(il.label_name,"")) <> "" GROUP BY COALESCE(NULLIF(il.dmm_id, ""), il.label_name), il.label_name ORDER BY il.label_name ASC LIMIT :limit OFFSET :offset';
        } else {
            $sql = 'SELECT il.label_id id FROM item_labels il INNER JOIN items i ON i.content_id = il.content_id WHERE ' . sitemap_complete_product_where('i') . ' AND TRIM(COALESCE(il.label_name,"")) <> "" GROUP BY il.label_id, il.label_name ORDER BY il.label_name ASC LIMIT :limit OFFSET :offset';
        }
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') continue;
            sitemap_complete_url(public_url('label.php') . '?id=' . rawurlencode($id), 'weekly', '0.7');
            $remaining--;
        }
    } catch (Throwable $e) {
        error_log('sitemap label rows failed: ' . $e->getMessage());
    }
    return $count;
}

function sitemap_complete_fixed_pages(): array
{
    $pages = [
        'about' => ['title' => 'サイトについて', 'updated_at' => ''],
        'privacy-policy' => ['title' => 'Privacy Policy', 'updated_at' => ''],
    ];
    if (db_table_exists('fixed_pages')) {
        try {
            $columns = db_column_exists('fixed_pages', 'updated_at') ? ',updated_at' : '';
            $stmt = db()->query('SELECT slug,title' . $columns . ' FROM fixed_pages WHERE is_published=1 ORDER BY id ASC');
            foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '' || $slug === CONTACT_PAGE_SLUG || $slug === 'que') continue;
                $pages[$slug] = ['title' => (string)($row['title'] ?? ''), 'updated_at' => (string)($row['updated_at'] ?? '')];
            }
        } catch (Throwable $e) {
            error_log('sitemap fixed pages failed: ' . $e->getMessage());
        }
    }
    return $pages;
}

$perSitemap = 10000;
$staticUrls = [
    [public_url('index.php'), 'daily', '1.0', ''],
    [public_url('items.php'), 'daily', '0.9', ''],
    [public_url('actresses.php'), 'weekly', '0.8', ''],
    [public_url('genres.php'), 'weekly', '0.8', ''],
    [public_url('makers.php'), 'weekly', '0.8', ''],
    [public_url('labels.php'), 'weekly', '0.8', ''],
    [public_url('series_list.php'), 'weekly', '0.8', ''],
];
foreach (sitemap_complete_fixed_pages() as $slug => $page) {
    $staticUrls[] = [public_url('page.php') . '?' . http_build_query(['slug' => $slug]), 'monthly', '0.4', (string)($page['updated_at'] ?? '')];
}

$sources = sitemap_complete_master_sources();
$labelCount = sitemap_complete_label_count();
$totalUrls = count($staticUrls) + $labelCount;
foreach ($sources as $source) $totalUrls += sitemap_complete_source_count($source);
$totalParts = max(1, (int)ceil($totalUrls / $perSitemap));

if ((isset($_GET['index']) && (string)$_GET['index'] === '1') || ($totalUrls > $perSitemap && !isset($_GET['part']))) {
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    for ($i = 1; $i <= $totalParts; $i++) {
        echo "  <sitemap>\n    <loc>" . sitemap_complete_e(public_url('sitemap.php') . '?part=' . $i) . "</loc>\n  </sitemap>\n";
    }
    echo "</sitemapindex>\n";
    return;
}

$part = 1;
if (isset($_GET['part'])) {
    $validatedPart = filter_var($_GET['part'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $totalParts]]);
    if ($validatedPart === false) {
        http_response_code(404);
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"/>\n";
        return;
    }
    $part = (int)$validatedPart;
}

$start = ($part - 1) * $perSitemap;
$remaining = $perSitemap;
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

foreach ($staticUrls as $index => $url) {
    if ($index < $start) continue;
    if ($remaining <= 0) break;
    sitemap_complete_url((string)$url[0], (string)$url[1], (string)$url[2], (string)$url[3]);
    $remaining--;
}
$start = max(0, $start - count($staticUrls));

foreach ($sources as $source) {
    $count = sitemap_complete_emit_source($source, $start, $remaining);
    $start = max(0, $start - $count);
}

sitemap_complete_emit_labels($start, $remaining);
echo "</urlset>\n";
