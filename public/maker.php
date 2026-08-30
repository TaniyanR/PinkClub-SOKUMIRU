<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

$id = (int)get('id', 0);
$row = null;
$list = [];
$makerPage = max(1, (int)get('page', 1));
$limit = 20;
$offset = ($makerPage - 1) * $limit;
$hasNext = false;
try {
    $row = fetch_maker($id);
    if ($row !== null) {
        $rows = dedupe_items_by_key(fetch_items_by_maker((int)$row['id'], $limit + 1, $offset));
        [$list, $hasNext] = paginate_items($rows, $limit);
    }
} catch (Throwable) {
    $row = null;
    $list = [];
}
if ($row !== null && $makerPage === 1 && $list === []) {
    require __DIR__ . '/404.php';
}
$makerName = trim((string)($row['name'] ?? ''));
$makerNameSql = db_column_exists('item_makers', 'item_id')
    ? "SELECT im.maker_name FROM item_makers im INNER JOIN makers m ON m.id = :id AND im.dmm_id = m.dmm_id WHERE TRIM(COALESCE(im.maker_name, '')) <> '' GROUP BY im.maker_name ORDER BY COUNT(*) DESC, im.maker_name ASC LIMIT 1"
    : "SELECT maker_name FROM item_makers WHERE maker_id = :id AND TRIM(COALESCE(maker_name, '')) <> '' GROUP BY maker_name ORDER BY COUNT(*) DESC, maker_name ASC LIMIT 1";
try {
    $makerNameStmt = db()->prepare($makerNameSql);
    $makerNameStmt->execute([':id' => $id]);
    $makerNameCandidate = trim((string)($makerNameStmt->fetchColumn() ?: ''));
    if ($makerNameCandidate !== '' && !pcf_is_noise_name($makerNameCandidate)) {
        $makerName = $makerNameCandidate;
    }
} catch (Throwable) {
}
$makerNameIsMutualLink = false;
if ($makerName !== '') {
    try {
        $mutualLinkStmt = db()->prepare('SELECT id FROM mutual_links WHERE site_name = :name LIMIT 1');
        $mutualLinkStmt->execute([':name' => $makerName]);
        $makerNameIsMutualLink = (bool)$mutualLinkStmt->fetchColumn();
    } catch (Throwable) {
        $makerNameIsMutualLink = false;
    }
}
if ($row === null || $makerName === '' || pcf_is_noise_name($makerName) || $makerNameIsMutualLink) {
    require __DIR__ . '/404.php';
}

try {
    analytics_log_maker_page_view((int)$row['id']);
} catch (Throwable $e) {
    error_log('maker page view logging failed: ' . $e->getMessage());
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
$accessRankingRows = pcf_public_weighted_ranking('makers', $accessRankingPeriod);

$title = $makerName;
$pageDescription = mb_strimwidth($makerName . 'の作品一覧。SOKUMIRUで販売中の最新作・人気作品を紹介。', 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('maker.php') . '?' . http_build_query([
    'id' => $id,
    'page' => $makerPage > 1 ? $makerPage : null,
]);
if ($makerPage > 1) {
    $relPrev = public_url('maker.php') . '?' . http_build_query(['id' => $id, 'page' => $makerPage - 1]);
}
if ($hasNext) {
    $relNext = public_url('maker.php') . '?' . http_build_query(['id' => $id, 'page' => $makerPage + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => 'メーカー一覧', 'url' => public_url('makers.php')],
    ['label' => $makerName],
]); ?>

<section class="pcf-hero">
  <h1 class="pcf-hero__title"><?= e($makerName) ?></h1>
  <?php if (!empty($row['ruby'])): ?><p class="pcf-hero__subtitle">読み: <?= e((string)$row['ruby']) ?></p><?php endif; ?>
</section>

<h2 class="pcf-section-title"><?= e($makerName) ?>一覧</h2>
<?php if ($list !== []): ?>
  <section class="pcf-related-grid pcf-maker-related-grid">
    <?php foreach ($list as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($makerPage > 1): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('maker.php') . '?' . http_build_query(['id' => $id, 'page' => $makerPage - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$makerPage) ?></span>
    <?php if ($hasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('maker.php') . '?' . http_build_query(['id' => $id, 'page' => $makerPage + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('このメーカーの商品はありません。'); ?>
<?php endif; ?>

<?php pcf_render_item_access_ranking(
    $accessRankingTabs,
    $accessRankingPeriod,
    static function (string $period) use ($id): string {
        return public_url('maker.php') . '?' . http_build_query([
            'id' => $id,
            'rank_period' => $period,
        ]) . '#access-ranking';
    },
    $accessRankingRows,
    static function (array $rankingRow): string {
        $rankingId = (int)($rankingRow['id'] ?? 0);
        return $rankingId > 0
            ? public_url('maker.php') . '?id=' . rawurlencode((string)$rankingId)
            : '';
    },
    '人気のメーカーランキングのデータがありません。',
    '人気のメーカーランキング'
); ?>

<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
