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
if ($labelName === '') {
    require __DIR__ . '/404.php';
}

$rows = dedupe_items_by_key(fetch_items_by_label_name($labelName, $limit + 1, $offset));
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
    'id' => (string)($label['id'] ?? $id),
    'name' => $labelName,
    'page' => $labelPage > 1 ? $labelPage : null,
]);
if ($labelPage > 1) {
    $relPrev = public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage - 1]);
}
if ($hasNext) {
    $relNext = public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage + 1]);
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
      <a class="pcf-pagination__link" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$labelPage) ?></span>
    <?php if ($hasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('このレーベルの商品はありません。'); ?>
<?php endif; ?>

<?php pcf_render_item_access_ranking(
    $accessRankingTabs,
    $accessRankingPeriod,
    static function (string $period) use ($label, $id, $labelName): string {
        return public_url('label.php') . '?' . http_build_query([
            'id' => (string)($label['id'] ?? $id),
            'name' => $labelName,
            'rank_period' => $period,
        ]) . '#access-ranking';
    },
    $accessRankingRows,
    static function (array $rankingRow): string {
        $rankingName = trim((string)($rankingRow['name'] ?? ''));
        $rankingId = trim((string)($rankingRow['id'] ?? ''));
        if ($rankingName === '' || $rankingId === '') {
            return '';
        }
        return public_url('label.php') . '?' . http_build_query([
            'id' => $rankingId,
            'name' => $rankingName,
        ]);
    },
    '人気のレーベルランキングのデータがありません。',
    '人気のレーベルランキング'
); ?>


<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
