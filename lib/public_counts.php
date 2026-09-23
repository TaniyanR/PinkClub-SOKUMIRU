<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/actress_directory_cache.php';

/**
 * 公開ページに実際に表示できる商品数・女優数を返す。
 *
 * @return array{posts:?int,actresses:?int}
 */
function pcf_public_counts(): array
{
    static $counts = null;
    if (is_array($counts)) {
        return $counts;
    }

    $counts = ['posts' => null, 'actresses' => null];

    try {
        if (db_table_exists('items')) {
            $where = items_product_source_where('items');
            $stmt = db()->query('SELECT COUNT(*) FROM items WHERE ' . $where);
            $counts['posts'] = $stmt ? (int)$stmt->fetchColumn() : null;
        }
    } catch (Throwable $e) {
        $counts['posts'] = null;
    }

    try {
        // Never rebuild the full public directory while rendering a normal page.
        // On a fresh deployment this may scan tens of thousands of rows and write
        // every group file, which can exceed the web request time limit and turn
        // otherwise healthy pages into HTTP 500 responses.  A cached manifest is
        // authoritative; until it exists, use a small indexed COUNT query.
        $manifest = pcf_actress_directory_cache_read_manifest();
        $actressCount = is_array($manifest)
            ? pcf_actress_directory_cache_count($manifest)
            : null;

        if ($actressCount === null && db_table_exists('actresses')) {
            $sql = 'SELECT COUNT(*) FROM actresses';
            if (db_column_exists('actresses', 'dmm_id')) {
                $sql .= ' WHERE COALESCE(dmm_id, "") <> "" AND dmm_id NOT LIKE "name:%"';
            }
            $stmt = db()->query($sql);
            $actressCount = $stmt ? (int)$stmt->fetchColumn() : null;
        }

        $counts['actresses'] = $actressCount;
    } catch (Throwable $e) {
        $counts['actresses'] = null;
    }

    return $counts;
}
