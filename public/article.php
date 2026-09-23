<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

$articleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($articleId) || $articleId <= 0) {
    require __DIR__ . '/404.php';
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM articles WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $articleId]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($article)) {
    require __DIR__ . '/404.php';
}

// article.php is a legacy product-article route. When the corresponding
// current product exists, consolidate users and search engines on item.php.
$productId = trim((string)($article['product_id'] ?? ''));
if ($productId !== '') {
    try {
        $sourceWhere = function_exists('items_product_source_where') ? items_product_source_where('items') : '1=1';
        $itemStmt = $pdo->prepare(
            'SELECT items.id FROM items WHERE (items.content_id = :product_id OR items.product_id = :product_id)'
            . ' AND ' . $sourceWhere . ' ORDER BY items.id DESC LIMIT 1'
        );
        $itemStmt->execute([':product_id' => $productId]);
        $itemId = (int)($itemStmt->fetchColumn() ?: 0);
        if ($itemId > 0) {
            header('Location: ' . public_url('item.php') . '?id=' . rawurlencode((string)$itemId), true, 301);
            exit;
        }
    } catch (Throwable $e) {
        error_log('legacy article canonical lookup failed: ' . $e->getMessage());
    }
}

$title = trim((string)($article['title'] ?? ''));
if ($title === '') {
    $title = '商品記事';
}
$pageDescription = mb_strimwidth(trim((string)($article['description'] ?? '')), 0, 150, '…', 'UTF-8');
$robotsMeta = 'noindex,follow';
$canonicalUrl = public_url('article.php') . '?id=' . rawurlencode((string)$articleId);

require __DIR__ . '/partials/header.php';
?>
<article>
  <h1><?= e($title) ?></h1>
  <div class="meta">発売日: <?= e((string)($article['release_date'] ?? '未設定')) ?></div>
  <?php if (!empty($article['image_url'])): ?>
    <p><img src="<?= e((string)$article['image_url']) ?>" alt="<?= e($title) ?>" loading="lazy" decoding="async"></p>
  <?php endif; ?>
  <?php if (!empty($article['description'])): ?>
    <p><?= nl2br(e((string)$article['description'])) ?></p>
  <?php endif; ?>
  <?php if (!empty($article['price'])): ?>
    <p>価格: <?= e((string)$article['price']) ?>円</p>
  <?php endif; ?>
  <?php if (!empty($article['affiliate_url'])): ?>
    <p><a href="<?= e((string)$article['affiliate_url']) ?>" target="_blank" rel="noopener sponsored nofollow">SOKUMIRU商品ページへ</a></p>
  <?php endif; ?>
</article>
<?php
require __DIR__ . '/partials/footer.php';
