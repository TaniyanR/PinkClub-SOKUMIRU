<?php
/** CLI-only fixtures for repair_smoke.py, on an explicitly empty test database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$dbname = (string)getenv('PCF_TEST_DB_NAME');
check(preg_match('/\Apcf_test_[a-z0-9_]+\z/', $dbname) === 1, 'Use a dedicated pcf_test_* database.');
if (($argv[1] ?? '') === 'check-empty') {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('PCF_TEST_DB_HOST') ?: '127.0.0.1', (int)(getenv('PCF_TEST_DB_PORT') ?: 3306), $dbname), (string)getenv('PCF_TEST_DB_USER'), (string)getenv('PCF_TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check($pdo->query('SHOW TABLES')->fetchColumn() === false, 'The test database must be empty. No existing data will be reset.');
    exit;
}
require dirname(__DIR__) . '/lib/bootstrap.php';
check(installer_status()['completed'], 'Setup did not complete.');
$pdo = db();
check((int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === count(glob(dirname(__DIR__) . '/sql/migrations/*.sql')), 'Not all migrations were applied.');
check(db_table_exists('fixed_pages'), 'Fixed pages schema is missing.');
foreach (['actresses' => '検証出演者', 'genres' => '検証ジャンル', 'makers' => '検証メーカー', 'series_master' => '検証シリーズ', 'authors' => '検証作者'] as $table => $name) {
    $pdo->prepare("INSERT INTO $table (id,dmm_id,name,ruby) VALUES (101,'9001',?,'けんしょう')")->execute([$name]);
}
$pdo->exec("INSERT INTO actresses (id,dmm_id,name,image_small) VALUES (190903,'190903','プロフィール検証女優','https://example.org/profile.jpg'),(1611931,'1611931','出演作品未登録の女優','https://example.org/profile2.jpg')");
$pdo->exec("INSERT INTO actresses (id,dmm_id,name) VALUES (190904,'190904','漢字名出演者')");
site_setting_set('site.start_year', '2020');
$raw = ['title' => '動作確認作品', 'comment' => 'ページ表示を確認する架空データです。', 'imageURL' => ['small' => 'https://img.sokmil.com/image/capture/ss_fixture001.jpg', 'large' => 'https://img.sokmil.com/image/capture/ol_fixture001.jpg']];
$pdo->prepare('INSERT INTO items (id,content_id,title,item_source,release_date,image_large,raw_json,url,affiliate_url) VALUES (101,?,?,?,CURDATE(),?,?,?,?)')->execute(['fixture001', '動作確認作品', 'sokumiru_product', $raw['imageURL']['large'], json_encode($raw, JSON_UNESCAPED_UNICODE), 'https://www.sokmil.com/av/_item/item_ID=fixture001.htm', 'https://www.sokmil.com/av/_item/item_ID=fixture001.htm']);
foreach (['actresses' => ['actress', '出演者'], 'genres' => ['genre', 'ジャンル'], 'makers' => ['maker', 'メーカー'], 'series' => ['series', 'シリーズ'], 'authors' => ['author', '作者'], 'labels' => ['label', 'レーベル']] as $suffix => [$singular, $label]) {
    $pdo->prepare("INSERT INTO item_$suffix (item_id,dmm_id,{$singular}_name) VALUES (101,'9001',?)")->execute(['検証' . $label]);
}
$pdo->exec("INSERT INTO item_actresses (item_id,dmm_id,actress_name) VALUES (101,'190904','漢字名出演者')");
site_setting_set('fixture.keep', 'preserved');
$pdo->exec("INSERT INTO fixed_pages (slug,title,body) VALUES ('about','About','個人情報保護方針については下記のページをご覧下さい。・ [Privacy Policy(URL付き)]ページ')");
$admin = $pdo->query('SELECT password_hash FROM admins LIMIT 1')->fetchColumn();
$pdo->exec("INSERT INTO fixed_pages (slug,title,body) VALUES ('fixture','Keep','Keep this page')");
$result = installer_run();
check($result['success'], 'Re-running the installer failed.');
check(!isset($result['initial_credentials']), 'Re-running reset the admin.');
check(site_setting_get('fixture.keep') === 'preserved', 'Existing setting was lost.');
check($pdo->query('SELECT password_hash FROM admins LIMIT 1')->fetchColumn() === $admin, 'Admin password changed.');
check((int)$pdo->query('SELECT COUNT(*) FROM items WHERE id=101')->fetchColumn() === 1, 'Product was lost.');
check($pdo->query("SELECT body FROM fixed_pages WHERE slug='fixture'")->fetchColumn() === 'Keep this page', 'Fixed page was changed.');
echo "PASS: all migrations, fixtures and data-preserving installer rerun\n";
