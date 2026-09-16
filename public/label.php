<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

$id = trim((string)get('id', ''));
$name = trim((string)get('name', ''));
$labelPage = max(1, (int)get('page', 1));
$limit = 20;
$offset = ($labelPage - 1) * $limit;
$list = [];
$hasNext = false;
$label = fetch_label($id, $name);
if ($label === null) {
    require __DIR__ . '/404.php';
}

$labelName = trim((string)($label['name'] ?? ''));
$canonicalLabelId = trim((string)($label['id'] ?? $id));
if ($labelName === '' || $canonicalLabelId === '') {
    require __DIR__ . '/404.php';
}

$canonicalBase = public_url('label.php') . '?' . http_build_query(['id' => $canonicalLabelId]);
if ($name !== '' || $id !== $canonicalLabelId) {
    $redirect = $canonicalBase;
    if ($labelPage > 1) {
        $redirect .= '&' . http_build_query(['page' => $labelPage]);
    }
    header('Location: ' . $redirect, true, 301);
    exit;
}

$rows = [];
if (db_column_exists('item_labels', 'item_id')) {
    try {
        $sql = 'SELECT DISTINCT items.*
                FROM items
                INNER JOIN item_labels ON item_labels.item_id = items.id
                WHERE (item_labels.dmm_id = :label_id OR item_labels.label_name = :label_name)
                  AND ' . items_product_source_where('items') . '
                ORDER BY items.release_date DESC, items.id DESC
                LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':label_id', $canonicalLabelId, PDO::PARAM_STR);
        $stmt->bindValue(':label_name', $labelName, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        $rows = [];
    }
}
if ($rows === []) {
    $rows = fetch_items_by_label_name($labelName, $limit + 1, $offset);
}
$rows = dedupe_items_by_key($rows);
[$list, $hasNext] = paginate_items($rows, $limit);
if ($labelPage === 1 && $list === []) {
    require __DIR__ . '/404.php';
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
$accessRankingRows = pcf_public_weighted_ranking('labels', $accessRankingPeriod);
$accessRankingRows = array_values(array_filter($accessRankingRows, static function (array $row): bool {
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '' || pcf_is_noise_name($name)) {
        return false;
    }
    return preg_match('/[^\s\-_ー－―—–]+/u', $name) === 1;
}));

$title = $labelName;
$pageDescription = mb_strimwidth($labelName . 'レーベルの作品一覧。SOKUMIRUで販売中の最新作・人気作品を紹介。', 0, 150, '…', 'UTF-8');
$canonicalUrl = $canonicalBase . ($labelPage > 1 ? '&' . http_build_query(['page' => $labelPage]) : '');
if ($labelPage > 1) {
    $relPrev = $canonicalBase . ($labelPage - 1 > 1 ? '&' . http_build_query(['page' => $labelPage - 1]) : '');
}
if ($hasNext) {
    $relNext = $canonicalBase . '&' . http_build_query(['page' => $labelPage + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => 'レーベル一覧', 'url' => public_url('labels.php')],
    ['label' => $labelName],
]); ?>
<?php pcf_render_hero($labelName); ?>

<h2 class="pcf-section-title"><?= e($labelName) ?>一覧</h2>
<?php if ($list !== []): ?>
  <section class="pcf-related-grid pcf-label-related-grid">
    <?php foreach ($list as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($labelPage > 1): ?>
      <a class="pcf-pagination__link" href="<?= e($canonicalBase . ($labelPage - 1 > 1 ? '&' . http_build_query(['page' => $labelPage - 1]) : '')) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$labelPage) ?></span>
    <?php if ($hasNext): ?>
      <a class="pcf-pagination__link" href="<?= e($canonicalBase . '&' . http_build_query(['page' => $labelPage + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('このレーベルの商品はありません。'); ?>
<?php endif; ?>

<?php pcf_render_item_access_ranking(
    $accessRankingTabs,
    $accessRankingPeriod,
    static function (string $period) use ($canonicalLabelId): string {
        return public_url('label.php') . '?' . http_build_query([
            'id' => $canonicalLabelId,
            'rank_period' => $period,
        ]) . '#access-ranking';
    },
    $accessRankingRows,
    static function (array $rankingRow): string {
        $rankingId = trim((string)($rankingRow['id'] ?? ''));
        if ($rankingId === '') {
            return '';
        }
        return public_url('label.php') . '?' . http_build_query(['id' => $rankingId]);
    },
    '人気のレーベルランキングのデータがありません。',
    '人気のレーベルランキング'
); ?>

<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
