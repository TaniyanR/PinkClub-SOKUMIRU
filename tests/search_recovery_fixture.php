<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || preg_match('/\Apcf_test_[a-z0-9_]+\z/', (string)getenv('PCF_TEST_DB_NAME')) !== 1) exit(1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/search_lifecycle.php';
require dirname(__DIR__) . '/lib/public_rankings.php';
require dirname(__DIR__) . '/lib/seo_metadata.php';
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = db();
$mode = $argv[1] ?? 'verify';
if ($mode === 'outage_on' || $mode === 'outage_off') {
    $pdo->exec($mode === 'outage_on' ? 'RENAME TABLE items TO items_search_test_down' : 'RENAME TABLE items_search_test_down TO items');
    pcf_search_cache_invalidate();
    exit;
}
verify(db_table_exists('indexnow_queue'), 'Migration missing');
if ($mode === 'ranking_failure') {
    $pdo->exec('INSERT INTO page_views(item_id,viewed_at) VALUES (101,NOW())');
    $before = pcf_public_weighted_ranking('items','daily',200,true);
    verify(count($before) > 0, 'Need a non-empty healthy cache');
    $cache = setting_get('public.weighted_ranking.v3.items.daily','');
    $pdo->exec('RENAME TABLE item_out_click_daily TO item_out_click_daily_search_test_down');
    try {
        verify(pcf_public_weighted_ranking('items','daily',200,true) === $before, 'Failed query lost stale ranking');
        verify(setting_get('public.weighted_ranking.v3.items.daily','') === $cache, 'Failed query overwrote cache');
    } finally {
        $pdo->exec('RENAME TABLE item_out_click_daily_search_test_down TO item_out_click_daily');
    }
    echo "PASS: ranking retains last successful cache after SQL failure\n";
    exit;
}
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO items (id,content_id,title,item_source,release_date) VALUES (102,'fixture002','検証用の別作品','sokumiru_product',CURDATE())");
    $pdo->exec("INSERT INTO page_views (item_id,viewed_at) VALUES (101,NOW()),(102,NOW()),(102,NOW())");
    $pdo->exec("INSERT INTO item_out_click_daily (item_id,click_date,visitor_hash,clicked_at) VALUES (101,CURDATE(),'search-fixture',NOW())");
    verify(pcf_public_weighted_ranking('items','daily',1) === [], 'Cold request ran aggregation');
    verify(count(pcf_public_ranking_refresh_queue()) > 0, 'Cold refresh not queued');
    $one = pcf_public_weighted_ranking('items','daily',1,true);
    verify(count($one) === 1 && (int)$one[0]['id'] === 101 && (int)$one[0]['access_count'] >= 4, 'PV plus OUT*3 incorrect');
    verify(count(pcf_public_weighted_ranking('items','daily',200)) === 2, 'Small request truncated shared cache');
    verify(count(pcf_public_weighted_ranking('genres','daily',200,true)) >= 1, 'Genre ranking missing');
    pcf_item_mark_gone(101,'テストで確認した掲載終了');
    verify(pcf_item_is_gone(101) && pcf_item_is_gone(0,'fixture001'), 'Tombstone missing');
    verify(fetch_item_by_content_id('fixture001') === null, 'Gone product still public');
    pcf_item_restore(101);
    verify(fetch_item_by_content_id('fixture001') !== null, 'Restore failed');
    $url = public_url('item.php') . '?id=101';
    verify(pcf_indexnow_valid_url($url), 'Canonical URL rejected');
    foreach (['https://evil.example/item.php?id=101',pcf_indexnow_origin().'/admin/index.php',$url.'&password=test',pcf_indexnow_origin().'/item.php?id[]=1'] as $bad) verify(!pcf_indexnow_valid_url($bad),'Unsafe URL accepted');
    setting_set('indexnow.key','search-fixture-key-12345');
    setting_set('indexnow.origin',pcf_indexnow_origin());
    setting_set('indexnow.enabled','1');
    $pdo->exec('DELETE FROM indexnow_queue');
    pcf_indexnow_item_changed(101);
    $revision = (int)$pdo->query('SELECT revision FROM indexnow_queue')->fetchColumn();
    pcf_indexnow_item_changed(101);
    verify((int)$pdo->query('SELECT revision FROM indexnow_queue')->fetchColumn() === $revision,'Unchanged item queued twice');
    $payload = pcf_indexnow_payload([$url,$url,'https://evil.example/']);
    verify(count($payload['urlList']) === 1 && $payload['keyLocation'] === pcf_indexnow_origin().'/indexnow-key.php','Payload scope incorrect');
    $result = pcf_indexnow_dispatch(static fn($payload)=>['status'=>429,'retry_after'=>3600]);
    verify($result['status'] === 'retry' && (int)$pdo->query('SELECT attempts FROM indexnow_queue')->fetchColumn() === 1,'Failed submission lost');
    verify(pcf_indexnow_dispatch(static function(){throw new RuntimeException('Should not run');})['status'] === 'cooldown','Retry-After ignored');
    $pdo->exec('UPDATE indexnow_queue SET next_attempt_at=NOW()');
    setting_set('indexnow.next_dispatch_at','0');
    pcf_indexnow_dispatch(static function($payload) use ($url) { pcf_indexnow_enqueue($url); return ['status'=>200]; });
    verify((int)$pdo->query('SELECT COUNT(*) FROM indexnow_queue')->fetchColumn() === 1,'Concurrent update lost');
    setting_set('indexnow.next_dispatch_at','0');
    pcf_indexnow_dispatch(static fn($payload)=>['status'=>202]);
    verify((int)$pdo->query('SELECT COUNT(*) FROM indexnow_queue')->fetchColumn() === 0,'202 not acknowledged');
    setting_set('indexnow.origin','https://other.example');
    verify(pcf_indexnow_dispatch(static function(){throw new RuntimeException('Must not send');})['status'] === 'disabled','Cloned environment sent URLs');
    $desc = pcf_meta_description('短い説明','テスト作品','item.php','PinkClub SOKUMIRU');
    verify(mb_strlen($desc) >= 70 && mb_strlen($desc) <= 160,'Description length incorrect');
    verify(pcf_video_object('x','y','https://example.org/image.jpg','https://example.org/embed','') === null,'Invented upload date');
    $video = pcf_video_object('x','y','https://example.org/image.jpg','https://example.org/embed','2020-01-02T10:00:00+09:00');
    verify($video !== null && !isset($video['contentUrl']) && str_starts_with($video['uploadDate'],'2020-01-02'),'Video schema invalid');
    echo "PASS: ranking weight/limit/cold cache, lifecycle, IndexNow scope/dedupe/retry/concurrency/202, metadata\n";
} finally {
    $pdo->rollBack();
}
