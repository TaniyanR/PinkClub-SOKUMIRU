<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
$title = 'アクセス解析';
analytics_ensure_tables();

$migrationNotice = '';
try {
    $appliedMigrationCount = installer_apply_migrations(dirname(__DIR__) . '/sql/migrations', 'admin_access_analytics_v2');
    $migrationNotice = $appliedMigrationCount > 0
        ? 'アクセス解析用DB更新を' . $appliedMigrationCount . '件適用しました。'
        : 'アクセス解析用DB更新は適用済みです。';
} catch (Throwable $e) {
    $migrationNotice = 'アクセス解析用DB更新を適用できませんでした。logs/install.log を確認してください。';
    installer_log_exception('admin_access_analytics_v2', $e);
}

$tab = (string)get('tab', 'graph');
$allowedTabs = ['graph', 'referrer', 'destination', 'engine', 'keyword', 'duration'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'graph';

$periodKey = (string)get('period', 'week');
$days = match ($periodKey) {
    'day' => 1,
    'month' => 30,
    'quarter' => 90,
    'year' => 365,
    default => 7,
};
$periodKey = match ($days) { 1 => 'day', 30 => 'month', 90 => 'quarter', 365 => 'year', default => 'week' };

$to = new DateTimeImmutable('today');
$from = $to->sub(new DateInterval('P' . ($days - 1) . 'D'));
$prevFrom = $from->sub(new DateInterval('P' . $days . 'D'));
$prevTo = $from->sub(new DateInterval('P1D'));
$fromTs = $from->format('Y-m-d 00:00:00');
$toTs = $to->format('Y-m-d 23:59:59');

$rowsByDate = [];
for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
    $date = $day->format('Y-m-d');
    $rowsByDate[$date] = ['stat_date' => $date, 'pv' => 0, 'uu' => 0, 'in_count' => 0, 'out_count' => 0];
}
$dailyStmt = db()->prepare('SELECT stat_date,pv,uu,in_count,out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to ORDER BY stat_date');
$dailyStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $date = (string)($row['stat_date'] ?? '');
    if (isset($rowsByDate[$date])) {
        $rowsByDate[$date] = [
            'stat_date' => $date,
            'pv' => (int)($row['pv'] ?? 0),
            'uu' => (int)($row['uu'] ?? 0),
            'in_count' => (int)($row['in_count'] ?? 0),
            'out_count' => (int)($row['out_count'] ?? 0),
        ];
    }
}
$rows = array_values($rowsByDate);

$sumStmt = db()->prepare('SELECT COALESCE(SUM(pv),0) pv,COALESCE(SUM(in_count),0) in_count,COALESCE(SUM(out_count),0) out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to');
$sumStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
$sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['pv' => 0, 'in_count' => 0, 'out_count' => 0];
$sum = ['pv' => (int)$sum['pv'], 'in_count' => (int)$sum['in_count'], 'out_count' => (int)$sum['out_count'], 'uu' => 0];
$uuStmt = db()->prepare('SELECT COUNT(DISTINCT visitor_hash) FROM visit_sessions WHERE stat_date BETWEEN :from AND :to');
$uuStmt->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
$sum['uu'] = (int)$uuStmt->fetchColumn();

$prevStmt = db()->prepare('SELECT COALESCE(SUM(pv),0) pv,COALESCE(SUM(in_count),0) in_count,COALESCE(SUM(out_count),0) out_count FROM daily_stats WHERE stat_date BETWEEN :from AND :to');
$prevStmt->execute([':from' => $prevFrom->format('Y-m-d'), ':to' => $prevTo->format('Y-m-d')]);
$prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['pv' => 0, 'in_count' => 0, 'out_count' => 0];
$uuStmt->execute([':from' => $prevFrom->format('Y-m-d'), ':to' => $prevTo->format('Y-m-d')]);
$prev['uu'] = (int)$uuStmt->fetchColumn();

$refRows = $outRows = $keywordRows = $durationRows = [];
$engineRows = [];
$durationSummary = ['views' => 0, 'avg_duration' => 0, 'avg_active' => 0, 'avg_scroll' => 0, 'engaged' => 0, 'short' => 0];

if ($tab === 'referrer' || $tab === 'engine') {
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(referer_host,''),'（不明）') referer_host,COUNT(*) cnt FROM in_logs WHERE created_at BETWEEN :from AND :to GROUP BY referer_host ORDER BY cnt DESC LIMIT 200");
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $refRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($tab === 'destination') {
    $stmt = db()->prepare('SELECT target_url,COUNT(*) cnt FROM out_logs WHERE created_at BETWEEN :from AND :to GROUP BY target_url ORDER BY cnt DESC LIMIT 200');
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $outRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($tab === 'engine') {
    foreach ($refRows as $row) {
        $host = strtolower((string)($row['referer_host'] ?? ''));
        $count = (int)($row['cnt'] ?? 0);
        $engine = null;
        if (str_contains($host, 'google.')) $engine = 'Google';
        elseif (str_contains($host, 'yahoo.')) $engine = 'Yahoo!';
        elseif (str_contains($host, 'bing.')) $engine = 'Bing';
        elseif (str_contains($host, 'duckduckgo.')) $engine = 'DuckDuckGo';
        elseif (str_contains($host, 'baidu.')) $engine = 'Baidu';
        elseif (str_contains($host, 'yandex.')) $engine = 'Yandex';
        if ($engine !== null) $engineRows[$engine] = ($engineRows[$engine] ?? 0) + $count;
    }
    arsort($engineRows);
}
if ($tab === 'keyword') {
    $stmt = db()->prepare("SELECT path,COUNT(*) cnt FROM site_events WHERE event_type='pv' AND session_id_hash=:marker AND path LIKE '%/search.php?%' AND created_at BETWEEN :from AND :to GROUP BY path ORDER BY cnt DESC LIMIT 1000");
    $stmt->execute([':marker' => analytics_beacon_marker_hash(), ':from' => $fromTs, ':to' => $toTs]);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $query = (string)parse_url((string)($row['path'] ?? ''), PHP_URL_QUERY);
        $params = []; parse_str($query, $params);
        $word = trim((string)($params['q'] ?? ''));
        if ($word !== '') $counts[$word] = ($counts[$word] ?? 0) + (int)($row['cnt'] ?? 0);
    }
    arsort($counts);
    foreach (array_slice($counts, 0, 200, true) as $word => $count) $keywordRows[] = ['keyword' => $word, 'cnt' => $count];
}

if (db_table_exists('analytics_page_engagement')) {
    $stmt = db()->prepare('SELECT COUNT(*) views,COALESCE(AVG(duration_seconds),0) avg_duration,COALESCE(AVG(active_seconds),0) avg_active,COALESCE(AVG(max_scroll_percent),0) avg_scroll,SUM(CASE WHEN active_seconds>=10 THEN 1 ELSE 0 END) engaged,SUM(CASE WHEN duration_seconds<10 THEN 1 ELSE 0 END) short FROM analytics_page_engagement WHERE viewed_at BETWEEN :from AND :to');
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $durationSummary = [
        'views' => (int)($d['views'] ?? 0), 'avg_duration' => (int)round((float)($d['avg_duration'] ?? 0)),
        'avg_active' => (int)round((float)($d['avg_active'] ?? 0)), 'avg_scroll' => (int)round((float)($d['avg_scroll'] ?? 0)),
        'engaged' => (int)($d['engaged'] ?? 0), 'short' => (int)($d['short'] ?? 0),
    ];
    if ($tab === 'duration') {
        $stmt = db()->prepare('SELECT path,COUNT(*) views,ROUND(AVG(duration_seconds)) avg_duration,ROUND(AVG(active_seconds)) avg_active,ROUND(AVG(max_scroll_percent)) avg_scroll FROM analytics_page_engagement WHERE viewed_at BETWEEN :from AND :to GROUP BY path ORDER BY avg_active DESC,views DESC LIMIT 200');
        $stmt->execute([':from' => $fromTs, ':to' => $toTs]);
        $durationRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$fmtSeconds = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds < 60) return $seconds . '秒';
    $minutes = intdiv($seconds, 60); $rest = $seconds % 60;
    if ($minutes < 60) return $minutes . '分' . ($rest ? $rest . '秒' : '');
    $hours = intdiv($minutes, 60); $minutes %= 60;
    return $hours . '時間' . ($minutes ? $minutes . '分' : '');
};
$delta = static fn(int $current, int $previous): string => (($current - $previous) > 0 ? '+' : '') . number_format($current - $previous);
$link = static fn(string $targetTab, string $targetPeriod): string => admin_url('analytics.php') . '?' . http_build_query(['tab' => $targetTab, 'period' => $targetPeriod]);

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>アクセス解析</h1>
  <p><?= e($migrationNotice) ?></p>
  <div class="admin-actions" style="margin-bottom:14px">
    <?php foreach (['day'=>'今日','week'=>'7日','month'=>'30日','quarter'=>'90日','year'=>'365日'] as $key=>$label): ?>
      <a class="button-secondary" href="<?= e($link($tab,$key)) ?>" <?= $periodKey===$key?'aria-current="page"':'' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="admin-actions" style="margin-bottom:14px">
    <?php foreach (['graph'=>'概要','referrer'=>'流入元','destination'=>'流出先','engine'=>'検索エンジン','keyword'=>'検索語','duration'=>'滞在時間'] as $key=>$label): ?>
      <a class="button-secondary" href="<?= e($link($key,$periodKey)) ?>" <?= $tab===$key?'aria-current="page"':'' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'graph'): ?>
    <div class="admin-status-grid">
      <article class="admin-card admin-status-card"><strong>PV</strong><p><?= e(number_format($sum['pv'])) ?></p><small>前期間 <?= e($delta($sum['pv'],(int)$prev['pv'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>UU</strong><p><?= e(number_format($sum['uu'])) ?></p><small>前期間 <?= e($delta($sum['uu'],(int)$prev['uu'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>IN</strong><p><?= e(number_format($sum['in_count'])) ?></p><small>前期間 <?= e($delta($sum['in_count'],(int)$prev['in_count'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>OUT</strong><p><?= e(number_format($sum['out_count'])) ?></p><small>前期間 <?= e($delta($sum['out_count'],(int)$prev['out_count'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>平均滞在</strong><p><?= e($fmtSeconds($durationSummary['avg_duration'])) ?></p><small>有効閲覧 <?= e(number_format($durationSummary['engaged'])) ?></small></article>
      <article class="admin-card admin-status-card"><strong>平均アクティブ</strong><p><?= e($fmtSeconds($durationSummary['avg_active'])) ?></p><small>平均スクロール <?= e((string)$durationSummary['avg_scroll']) ?>%</small></article>
    </div>
    <?php $maxCount=1; foreach($rows as $r){$maxCount=max($maxCount,(int)$r['pv'],(int)$r['uu']);} ?>
    <h2>日別PV / UU</h2>
    <div class="analytics-bars analytics-bars--vertical">
      <?php foreach($rows as $r): $pv=(int)$r['pv'];$uu=(int)$r['uu']; ?>
        <div class="analytics-bars__col"><div class="analytics-bars__values"><span>PV <?= e((string)$pv) ?></span><span>UU <?= e((string)$uu) ?></span></div><div class="analytics-bars__pair"><div class="analytics-bars__track"><span class="analytics-bars__fill" style="height:<?= e((string)round($pv/$maxCount*100)) ?>%"></span></div><div class="analytics-bars__track"><span class="analytics-bars__fill analytics-bars__fill--uu" style="height:<?= e((string)round($uu/$maxCount*100)) ?>%"></span></div></div><span class="analytics-bars__date"><?= e(date('m/d',strtotime($r['stat_date']))) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($tab === 'referrer'): ?>
    <table class="admin-table"><tr><th>リンク元</th><th>件数</th></tr><?php foreach($refRows as $r): ?><tr><td><?= e((string)$r['referer_host']) ?></td><td><?= e((string)$r['cnt']) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'destination'): ?>
    <table class="admin-table"><tr><th>クリック先</th><th>件数</th></tr><?php foreach($outRows as $r): ?><tr><td><?= e((string)$r['target_url']) ?></td><td><?= e((string)$r['cnt']) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'engine'): ?>
    <table class="admin-table"><tr><th>検索エンジン</th><th>件数</th></tr><?php foreach($engineRows as $name=>$cnt): ?><tr><td><?= e($name) ?></td><td><?= e((string)$cnt) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'keyword'): ?>
    <table class="admin-table"><tr><th>検索キーワード</th><th>件数</th></tr><?php foreach($keywordRows as $r): ?><tr><td><?= e((string)$r['keyword']) ?></td><td><?= e((string)$r['cnt']) ?></td></tr><?php endforeach; ?></table>
  <?php elseif ($tab === 'duration'): ?>
    <div class="admin-status-grid">
      <article class="admin-card admin-status-card"><strong>記録閲覧</strong><p><?= e(number_format($durationSummary['views'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>平均滞在</strong><p><?= e($fmtSeconds($durationSummary['avg_duration'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>平均アクティブ</strong><p><?= e($fmtSeconds($durationSummary['avg_active'])) ?></p></article>
      <article class="admin-card admin-status-card"><strong>平均スクロール</strong><p><?= e((string)$durationSummary['avg_scroll']) ?>%</p></article>
    </div>
    <table class="admin-table"><tr><th>ページ</th><th>閲覧</th><th>平均滞在</th><th>平均アクティブ</th><th>スクロール</th></tr><?php foreach($durationRows as $r): ?><tr><td><?= e((string)$r['path']) ?></td><td><?= e((string)$r['views']) ?></td><td><?= e($fmtSeconds((int)$r['avg_duration'])) ?></td><td><?= e($fmtSeconds((int)$r['avg_active'])) ?></td><td><?= e((string)$r['avg_scroll']) ?>%</td></tr><?php endforeach; ?></table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php';
