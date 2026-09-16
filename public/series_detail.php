<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

$id = (int)get('id', 0);
$page = max(1, (int)get('page', 1));
$canonicalMakerId = series_canonical_maker_redirects()[$id] ?? 0;
if ($canonicalMakerId > 0) {
    header('Location: ' . public_url('maker.php') . '?id=' . rawurlencode((string)$canonicalMakerId), true, 301);
    exit;
}
$per = 24;
$viewport = (string)($_COOKIE['pcf_viewport'] ?? '');
$clientHintMobile = trim((string)($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? ''));
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($viewport === 'sp' || $clientHintMobile === '?1' || ($userAgent !== '' && preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|webOS/i', $userAgent))) {
    $per = 20;
}
$seriesViewportMode = $per === 20 ? 'sp' : 'pc';
$series = null;
$seriesItems = [];
$total = 0;
$pg = paginate(0, $page, $per);
try {
    $series = fetch_series_one($id);
    if ($series !== null) {
        $total = function_exists('count_items_by_series') ? count_items_by_series((int)$series['id']) : 0;
        $pg = paginate($total, $page, $per);
        $seriesItems = dedupe_items_by_key(fetch_items_by_series((int)$series['id'], (int)$pg['perPage'], (int)$pg['offset']));
    }
} catch (Throwable) {
    $series = null;
    $seriesItems = [];
    $total = 0;
    $pg = paginate(0, $page, $per);
}
if ($series === null) {
    require __DIR__ . '/404.php';
}
if ($total === 0) {
    require __DIR__ . '/404.php';
}

$seriesName = (string)($series['name'] ?? 'シリーズ詳細');
try {
    analytics_log_series_page_view((int)$series['id']);
} catch (Throwable $e) {
    error_log('series page view logging failed: ' . $e->getMessage());
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
$accessRankingRows = pcf_public_weighted_ranking('series', $accessRankingPeriod);

$title = $seriesName;
$pageDescription = mb_strimwidth($seriesName . 'シリーズの作品一覧。全' . (int)$total . '作品を掲載。', 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('series_detail.php') . '?' . http_build_query([
    'id' => $id,
    'page' => (int)($pg['page'] ?? 1) > 1 ? (int)$pg['page'] : null,
]);
if ((int)($pg['page'] ?? 1) > 1) {
    $relPrev = public_url('series_detail.php') . '?' . http_build_query(['id' => $id, 'page' => (int)$pg['page'] - 1]);
}
if ((int)($pg['page'] ?? 1) < (int)($pg['pages'] ?? 1)) {
    $relNext = public_url('series_detail.php') . '?' . http_build_query(['id' => $id, 'page' => (int)$pg['page'] + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<script>
(() => {
  if (!window.matchMedia) return;
  const expected = window.matchMedia('(max-width: 768px)').matches ? 'sp' : 'pc';
  const rendered = '<?= e($seriesViewportMode) ?>';
  const current = document.cookie.split('; ').find((row) => row.startsWith('pcf_viewport='))?.split('=')[1] || '';
  if (current !== expected) {
    document.cookie = 'pcf_viewport=' + expected + '; path=/; max-age=86400; SameSite=Lax';
  }
  if (rendered !== expected) {
    window.location.reload();
  }
})();
</script>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => 'シリーズ一覧', 'url' => public_url('series_list.php')],
    ['label' => $seriesName],
]); ?>
<?php pcf_render_hero($seriesName); ?>

<h2 class="pcf-section-title"><?= e($seriesName) ?>一覧</h2>
<?php if ($seriesItems !== []): ?>
  <section class="pcf-related-grid pcf-series-related-grid">
    <?php foreach ($seriesItems as $item): pcf_render_item_card(is_array($item) ? $item : [], 180, $seriesViewportMode === 'sp'); endforeach; ?>
  </section>
  <?php pcf_render_pagination($pg, public_url('series_detail.php'), ['id' => (int)$series['id']]); ?>
<?php else: ?>
  <?php pcf_render_empty('このシリーズの作品はまだありません。'); ?>
<?php endif; ?>

<?php pcf_render_entity_access_ranking(
    '人気のシリーズランキング',
    $accessRankingTabs,
    $accessRankingPeriod,
    static fn(string $period): string => public_url('series_detail.php') . '?' . http_build_query(['id' => (int)$series['id'], 'rank_period' => $period]) . '#access-ranking',
    $accessRankingRows,
    static fn(array $rankingRow): string => public_url('series_detail.php') . '?id=' . rawurlencode((string)($rankingRow['id'] ?? '')),
    '人気のシリーズランキングのデータがありません。'
); ?>

<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
