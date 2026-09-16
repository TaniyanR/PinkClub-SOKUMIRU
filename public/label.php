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

// item_labels は dmm_id / label_name を保持する。マスターに未登録でも、
// 関連テーブルからIDまたは名称を一意なプレースホルダーで解決する。
if ($label === null && db_column_exists('item_labels', 'item_id')) {
    try {
        $stmt = db()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(dmm_id), ""), TRIM(label_name)) AS id, '
            . 'TRIM(label_name) AS name '
            . 'FROM item_labels '
            . 'WHERE TRIM(label_name) <> "" '
            . 'AND ('
            . '(:id_present <> "" AND (TRIM(dmm_id) = :id_dmm OR TRIM(label_name) = :id_name)) '
            . 'OR (:name_present <> "" AND TRIM(label_name) = :name_exact)'
            . ') LIMIT 1'
        );
        $stmt->execute([
            ':id_present' => $id,
            ':id_dmm' => $id,
            ':id_name' => $id,
            ':name_present' => $name,
            ':name_exact' => $name,
        ]);
        $resolved = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($resolved)) {
            $label = $resolved;
        }
    } catch (Throwable $e) {
        error_log('[label] relation resolution failed: ' . $e->getMessage());
    }
}
if ($label === null) {
    require __DIR__ . '/404.php';
}

$labelName = trim((string)($label['name'] ?? ''));
$canonicalLabelId = trim((string)($label['id'] ?? $id));
if ($labelName === '' || $canonicalLabelId === '') {
    require __DIR__ . '/404.php';
}

$rows = [];
if (db_column_exists('item_labels', 'item_id')) {
    try {
        $stmt = db()->prepare(
            'SELECT DISTINCT items.* FROM items '
            . 'INNER JOIN item_labels ON item_labels.item_id = items.id '
            . 'WHERE (TRIM(COALESCE(item_labels.dmm_id, "")) = :label_id '
            . 'OR TRIM(item_labels.label_name) = :label_name) '
            . 'AND ' . items_product_source_where('items') . ' '
            . 'ORDER BY items.release_date DESC, items.id DESC '
            . 'LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':label_id', $canonicalLabelId, PDO::PARAM_STR);
        $stmt->bindValue(':label_name', $labelName, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[label] item lookup by relation failed: ' . $e->getMessage());
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
$canonicalUrl = public_url('label.php') . '?' . http_build_query([
    'id' => $canonicalLabelId,
    'page' => $labelPage > 1 ? $labelPage : null,
]);
if ($labelPage > 1) {
    $relPrev = public_url('label.php') . '?' . http_build_query(['id' => $canonicalLabelId, 'page' => $labelPage - 1]);
}
if ($hasNext) {
    $relNext = public_url('label.php') . '?' . http_build_query(['id' => $canonicalLabelId, 'page' => $labelPage + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<style>
.pcf-label-related-grid { grid-template-columns:repeat(4,minmax(0,1fr)); }
@media (max-width:1100px){.pcf-label-related-grid{grid-template-columns:repeat(3,minmax(0,1fr));}}
@media (max-width:900px){.pcf-label-related-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media (max-width:768px){.pcf-label-related-grid{grid-template-columns:1fr}.pcf-label-related-grid .pcf-dm-card__image-link,.pcf-label-related-grid .pcf-dm-card__image{height:auto}}
</style>
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
      <a class="pcf-pagination__link" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => $canonicalLabelId, 'page' => $labelPage - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$labelPage) ?></span>
    <?php if ($hasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => $canonicalLabelId, 'page' => $labelPage + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('このレーベルの商品はありません。'); ?>
<?php endif; ?>

<?php pcf_render_entity_access_ranking(
    '人気のレーベルランキング',
    $accessRankingTabs,
    $accessRankingPeriod,
    static fn(string $period): string => public_url('label.php') . '?' . http_build_query(['id' => $canonicalLabelId, 'rank_period' => $period]) . '#access-ranking',
    $accessRankingRows,
    static fn(array $rankingRow): string => public_url('label.php') . '?' . http_build_query(['id' => (string)($rankingRow['id'] ?? '')]),
    '人気のレーベルランキングのデータがありません。'
); ?>

<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
