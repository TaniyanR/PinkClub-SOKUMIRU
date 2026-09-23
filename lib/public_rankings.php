<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/site_settings.php';

function pcf_public_ranking_period_start(string $period): string
{
    // Event timestamps are written with MySQL NOW(); use the same clock for the boundary.
    $expression = match ($period) {
        'weekly' => 'CURRENT_DATE - INTERVAL 6 DAY',
        'monthly' => 'CURRENT_DATE - INTERVAL 1 MONTH',
        'yearly' => 'CURRENT_DATE - INTERVAL 1 YEAR',
        default => 'CURRENT_DATE',
    };
    return (string)db()->query("SELECT DATE_FORMAT(" . $expression . ", '%Y-%m-%d 00:00:00')")->fetchColumn();
}

function pcf_public_weighted_ranking(string $type, string $period, int $limit = 200, bool $forceRefresh = false): array
{
    $allowedTypes = ['items', 'actresses', 'genres', 'makers', 'labels', 'series'];
    if (!in_array($type, $allowedTypes, true)) {
        return [];
    }
    if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        $period = 'daily';
    }

    $limit = max(1, min(200, $limit));
    $generation = @file_get_contents(dirname(__DIR__) . '/storage/cache/search-generation') ?: '';
    $cacheKey = 'public.weighted_ranking.v3.' . $type . '.' . $period;
    try {
        $cached = json_decode((string)setting_get($cacheKey, ''), true);
    } catch (Throwable $e) {
        error_log('Ranking cache read failed: ' . $e->getMessage());
        return [];
    }
    $cachedRows = is_array($cached['rows'] ?? null) && ($cached['generation'] ?? '') === $generation ? $cached['rows'] : [];
    $fresh = is_array($cached) && ($cached['generation'] ?? '') === $generation
        && (int)($cached['cached_at'] ?? 0) >= time() - 1800
        && ($period !== 'daily' || date('Y-m-d', (int)($cached['cached_at'] ?? 0)) === date('Y-m-d'));
    if (!$forceRefresh) {
        if (!$fresh) {
            $GLOBALS['__pcf_public_ranking_refresh'][$type . '|' . $period] = ['type'=>$type, 'period'=>$period];
        }
        // Cold and stale public requests never execute the aggregate query.
        return array_slice($cachedRows, 0, $limit);
    }
    $directory = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($directory)) @mkdir($directory, 0775, true);
    $lock = @fopen($directory . '/weighted-' . $type . '-' . $period . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return array_slice($cachedRows, 0, $limit);
    }
    try {
        $stmt = db()->prepare(pcf_public_ranking_sql($type, pcf_public_ranking_item_score_sql(), 200));
        $periodFrom = pcf_public_ranking_period_start($period);
        $stmt->execute([':page_view_from'=>$periodFrom, ':out_click_from'=>$periodFrom]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        setting_set($cacheKey, json_encode(['cached_at'=>time(), 'generation'=>$generation, 'rows'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return array_slice($rows, 0, $limit);
    } catch (Throwable $e) {
        error_log('weighted ranking failed: ' . $type . ': ' . $e->getMessage());
        // Preserve the last successful result on a transient database failure.
        return array_slice($cachedRows, 0, $limit);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function pcf_public_ranking_refresh_queue(): array
{
    $queue = $GLOBALS['__pcf_public_ranking_refresh'] ?? [];
    return is_array($queue) ? array_values($queue) : [];
}

function pcf_public_ranking_item_score_sql(): string
{
    return 'SELECT i.id,
                   i.content_id,
                   i.title,
                   COALESCE(pv.page_view_count, 0) AS page_view_count,
                   COALESCE(oc.out_click_count, 0) AS out_click_count,
                   COALESCE(pv.page_view_count, 0) + (COALESCE(oc.out_click_count, 0) * 3) AS access_count
            FROM items i
            LEFT JOIN (
              SELECT item_id, COUNT(*) AS page_view_count
              FROM page_views
              WHERE viewed_at >= :page_view_from
              GROUP BY item_id
            ) pv ON pv.item_id = i.id
            LEFT JOIN (
              SELECT item_id, COUNT(*) AS out_click_count
              FROM item_out_click_daily
              WHERE clicked_at >= :out_click_from
              GROUP BY item_id
            ) oc ON oc.item_id = i.id
            WHERE (COALESCE(pv.page_view_count, 0) > 0 OR COALESCE(oc.out_click_count, 0) > 0)
              AND ' . items_product_source_where('i');
}

function pcf_public_ranking_sql(string $type, string $scoreSql, int $limit): string
{
    if ($type === 'items') {
        return 'SELECT scores.id, scores.content_id, scores.title,
                       scores.page_view_count, scores.out_click_count, scores.access_count
                FROM (' . $scoreSql . ') scores
                ORDER BY scores.access_count DESC, scores.out_click_count DESC, scores.id DESC
                LIMIT ' . $limit;
    }

    $config = [
        'actresses' => ['relation' => 'item_actresses', 'master' => 'actresses'],
        'genres' => ['relation' => 'item_genres', 'master' => 'genres'],
        'makers' => ['relation' => 'item_makers', 'master' => 'makers'],
        'series' => ['relation' => 'item_series', 'master' => 'series_master'],
    ];

    if (isset($config[$type])) {
        $relation = $config[$type]['relation'];
        $master = $config[$type]['master'];
        return 'SELECT m.id, m.dmm_id, m.name,
                       SUM(scores.page_view_count) AS page_view_count,
                       SUM(scores.out_click_count) AS out_click_count,
                       SUM(scores.access_count) AS access_count
                FROM (' . $scoreSql . ') scores
                INNER JOIN ' . $relation . ' r ON r.item_id = scores.id
                INNER JOIN ' . $master . ' m ON m.dmm_id = r.dmm_id
                GROUP BY m.id, m.dmm_id, m.name
                ORDER BY access_count DESC, out_click_count DESC, m.id DESC
                LIMIT ' . $limit;
    }

    return 'SELECT COALESCE(NULLIF(il.dmm_id, ""), il.label_name) AS id,
                   il.label_name AS name,
                   SUM(scores.page_view_count) AS page_view_count,
                   SUM(scores.out_click_count) AS out_click_count,
                   SUM(scores.access_count) AS access_count
            FROM (' . $scoreSql . ') scores
            INNER JOIN item_labels il ON il.item_id = scores.id
            WHERE TRIM(COALESCE(il.label_name, "")) <> ""
            GROUP BY COALESCE(NULLIF(il.dmm_id, ""), il.label_name), il.label_name
            ORDER BY access_count DESC, out_click_count DESC, il.label_name ASC
            LIMIT ' . $limit;
}


function pcf_public_ranking_warm_due(int $maxJobs = 2): void
{
    $pairs = [];
    foreach (['items','actresses','genres','makers','labels','series'] as $type) {
        foreach (['daily','weekly','monthly','yearly'] as $period) $pairs[] = [$type,$period];
    }
    $cursor = max(0, (int)setting_get('ranking.warm_cursor', '0')) % count($pairs);
    $jobs = 0;
    for ($i = 0; $i < count($pairs); $i++) {
        $index = ($cursor + $i) % count($pairs);
        [$type,$period] = $pairs[$index];
        unset($GLOBALS['__pcf_public_ranking_refresh'][$type . '|' . $period]);
        pcf_public_weighted_ranking($type, $period);
        if (isset($GLOBALS['__pcf_public_ranking_refresh'][$type . '|' . $period])) {
            pcf_public_weighted_ranking($type, $period, 200, true);
            if (++$jobs >= max(1, $maxJobs)) {
                setting_set('ranking.warm_cursor', (string)(($index + 1) % count($pairs)));
                return;
            }
        }
    }
}
