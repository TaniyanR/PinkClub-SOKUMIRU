<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';

auth_require_admin();
$title = '女優情報 API取得';
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    try {
        $offset = max(1, min(50000, (int)post('offset', 1)));
        $hits = max(1, min(100, (int)post('hits', 100)));
        $count = sokumiru_sync_service('actresses')->syncMaster('actress', null, $offset, $hits, [
            'keyword' => trim((string)post('keyword', '')),
        ]);
        $message = '女優情報を' . $count . '件取得して保存しました。';
    } catch (Throwable $e) {
        $message = '取得に失敗しました: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$total = 0;
try {
    $total = (int)db()->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
} catch (Throwable) {
}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>女優情報 API取得</h1>
  <p>SOKUMIRU出演者検索APIから、女性・アダルト動画（av）の出演者だけを取得します。認証情報は商品情報API設定と共通です。</p>
  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>"><p><?= e($message) ?></p></div>
  <?php endif; ?>
  <form method="post" class="stack" style="max-width:700px;">
    <?= csrf_input() ?>
    <label>キーワード（任意）<br><input type="text" name="keyword" value="" style="width:100%"></label>
    <label>取得件数<br><select name="hits"><option>20</option><option>50</option><option selected>100</option></select></label>
    <label>取得開始位置<br><input type="number" name="offset" value="1" min="1" max="50000"></label>
    <button type="submit">女優情報を取得して保存</button>
  </form>
  <p>保存済み女優：<?= e((string)$total) ?>件</p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
