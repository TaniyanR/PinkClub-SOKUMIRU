<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

function pcf_genre_count_items(int $genreId): int
{
    $genreId = max(1, $genreId);

    try {
        $sql = db_column_exists('item_genres', 'item_id')
            ? 'SELECT COUNT(DISTINCT items.id)
               FROM items
               INNER JOIN genres      ON genres.id          = :id
               INNER JOIN item_genres ON item_genres.dmm_id = genres.dmm_id
               WHERE items.id = item_genres.item_id AND ' . items_product_source_where('items')
            : 'SELECT COUNT(DISTINCT items.id)
               FROM items
               INNER JOIN item_genres ON items.content_id = item_genres.content_id
               WHERE item_genres.genre_id = :id AND ' . items_product_source_where('items');
        $stmt = db()->prepare($sql);
        $stmt->execute([':id' => $genreId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function pcf_genre_display_name(array $row): string
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '' && !pcf_is_noise_name($name)) {
        return $name;
    }

    $genreId = (int)($row['id'] ?? 0);
    if ($genreId > 0) {
        try {
            $sql = db_column_exists('item_genres', 'item_id')
                ? 'SELECT ig.genre_name, COUNT(*) AS item_count
                   FROM item_genres ig
                   INNER JOIN genres g ON g.id = :id AND ig.dmm_id = g.dmm_id
                   WHERE TRIM(COALESCE(ig.genre_name, \'\')) <> \'\'
                   GROUP BY ig.genre_name
                   ORDER BY item_count DESC
                   LIMIT 10'
                : 'SELECT genre_name, COUNT(*) AS item_count
                   FROM item_genres
                   WHERE genre_id = :id AND TRIM(COALESCE(genre_name, \'\')) <> \'\'
                   GROUP BY genre_name
                   ORDER BY item_count DESC
                   LIMIT 10';
            $stmt = db()->prepare($sql);
            $stmt->execute([':id' => $genreId]);
            foreach (($stmt->fetchAll() ?: []) as $candidate) {
                $candidateName = trim((string)($candidate['genre_name'] ?? ''));
                if ($candidateName !== '' && !pcf_is_noise_name($candidateName)) {
                    return $candidateName;
                }
            }
        } catch (Throwable) {
        }
    }

    return $name !== '' ? $name : 'ジャンル詳細';
}

$id = (int)get('id', 0);
$page = max(1, (int)get('page', 1));
$per = 20;
$row = null;
$list = [];
$total = 0;
$pg = paginate(0, $page, $per);
try {
    $row = fetch_genre($id);
    if ($row !== null) {
        $total = pcf_genre_count_items((int)$row['id']);
        $pg = paginate($total, $page, $per);
        $list = dedupe_items_by_key(fetch_items_by_genre((int)$row['id'], (int)$pg['perPage'], (int)$pg['offset']));
    }
} catch (Throwable) {
    $row = null;
    $list = [];
    $total = 0;
    $pg = paginate(0, $page, $per);
}
if ($row === null) {
    require __DIR__ . '/404.php';
}
if ($total === 0) {
    require __DIR__ . '/404.php';
}

$genreName = pcf_genre_display_name($row);
try {
    analytics_log_genre_page_view((int)$row['id']);
} catch (Throwable $e) {
    error_log('genre page view logging failed: ' . $e->getMessage());
}

$accessRankingPeriod = trim((string)get('rank_period', 'daily'));
$accessRankingTabs = [
    'daily' => ['label' => '本日'],
    'weekly' => ['label' => '週間'],
    'monthly' => ['label' => '月間'],
    'yearly' => ['label' => '年間'],
];
if (!isset($accessRankingTabs[$accessRankingPeriod])) {
    $accessRankingPeriod = 'daily';
}
$accessRankingRows = pcf_public_weighted_ranking('genres', $accessRankingPeriod);

$title = $genreName;
$pageDescription = mb_strimwidth($genreName . 'のAV・成人向け動画作品一覧。SOKUMIRUアフィリエイト最新作を紹介。', 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('genre.php') . '?' . http_build_query([
    'id' => $id,
    'page' => (int)($pg['page'] ?? 1) > 1 ? (int)$pg['page'] : null,
]);
if ((int)($pg['page'] ?? 1) > 1) {
    $relPrev = public_url('genre.php') . '?' . http_build_query(['id' => $id, 'page' => (int)$pg['page'] - 1]);
}
if ((int)($pg['page'] ?? 1) < (int)($pg['pages'] ?? 1)) {
    $relNext = public_url('genre.php') . '?' . http_build_query(['id' => $id, 'page' => (int)$pg['page'] + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => 'ジャンル一覧', 'url' => public_url('genres.php')],
    ['label' => $genreName],
]); ?>

<?php pcf_render_hero($genreName); ?>

<h2 class="pcf-section-title"><?= e($genreName) ?>一覧</h2>
<?php if ($list !== []): ?>
  <section class="pcf-related-grid pcf-genre-related-grid">
    <?php foreach ($list as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
  <?php pcf_render_pagination($pg, public_url('genre.php'), ['id' => (int)$row['id']]); ?>
<?php else: ?>
  <?php pcf_render_empty('このジャンルに紐づく商品はまだありません。'); ?>
<?php endif; ?>

<?php pcf_render_item_access_ranking(
    $accessRankingTabs,
    $accessRankingPeriod,
    static function (string $period) use ($row): string {
        return public_url('genre.php') . '?' . http_build_query([
            'id' => (int)$row['id'],
            'rank_period' => $period,
        ]) . '#access-ranking';
    },
    $accessRankingRows,
    static function (array $rankingRow): string {
        $rankingId = (int)($rankingRow['id'] ?? 0);
        return $rankingId > 0
            ? public_url('genre.php') . '?id=' . rawurlencode((string)$rankingId)
            : '';
    },
    '人気のジャンルランキングのデータがありません。',
    '人気のジャンルランキング'
); ?>

<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
