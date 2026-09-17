<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';
require_once __DIR__ . '/../lib/repository.php';

$id = (int)get('id', 0);
$row = false;
try {
    if (db_table_exists('authors')) {
        $stmt = db()->prepare('SELECT * FROM authors WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
} catch (Throwable) {
    $row = false;
}
if (!$row) {
    require __DIR__ . '/404.php';
}

$list = [];
if (db_table_exists('item_authors')) {
    try {
        $itemStmt = db()->prepare('SELECT items.* FROM items INNER JOIN item_authors ia ON items.content_id = ia.content_id WHERE ia.author_id = :id AND ' . items_product_source_where('items') . ' ORDER BY items.date_published DESC LIMIT 100');
        $itemStmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $itemStmt->execute();
        $list = $itemStmt->fetchAll() ?: [];
    } catch (Throwable) {
        $list = [];
    }

    if ($list === []) {
        try {
            $itemStmt = db()->prepare('SELECT items.* FROM items INNER JOIN item_authors ia ON items.id = ia.item_id WHERE ia.author_id = :id AND ' . items_product_source_where('items') . ' ORDER BY items.date_published DESC LIMIT 100');
            $itemStmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
            $itemStmt->execute();
            $list = $itemStmt->fetchAll() ?: [];
        } catch (Throwable) {
            $list = [];
        }
    }

    if ($list === [] && trim((string)($row['dmm_id'] ?? '')) !== '') {
        try {
            $itemStmt = db()->prepare('SELECT items.* FROM items INNER JOIN item_authors ia ON ia.item_id = items.id WHERE ia.dmm_id = :dmm_id AND ' . items_product_source_where('items') . ' ORDER BY items.release_date DESC, items.id DESC LIMIT 100');
            $itemStmt->bindValue(':dmm_id', (string)($row['dmm_id'] ?? ''), PDO::PARAM_STR);
            $itemStmt->execute();
            $list = $itemStmt->fetchAll() ?: [];
        } catch (Throwable) {
            $list = [];
        }
    }

    if ($list === []) {
        try {
            $itemStmt = db()->prepare('SELECT items.* FROM items INNER JOIN item_authors ia ON ia.item_id = items.id WHERE ia.author_name = :name AND ' . items_product_source_where('items') . ' ORDER BY items.release_date DESC, items.id DESC LIMIT 100');
            $itemStmt->bindValue(':name', (string)($row['name'] ?? ''), PDO::PARAM_STR);
            $itemStmt->execute();
            $list = $itemStmt->fetchAll() ?: [];
        } catch (Throwable) {
            $list = [];
        }
    }
}

$list = dedupe_items_by_key($list);

$oldestItem = pcf_pick_oldest_item($list);
$oldestImage = pcf_item_image(is_array($oldestItem) ? $oldestItem : []);

$authorName = trim((string)($row['name'] ?? ''));
$title = $authorName !== '' ? $authorName : '作者詳細';
$pageDescription = mb_strimwidth($title . 'の関連作品一覧。SOKUMIRUで取り扱う関連作品を紹介。', 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('author.php') . '?id=' . rawurlencode((string)$id);
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => $title],
]); ?>

<section class="pcf-topic-head">
  <?php if ($oldestImage !== ''): ?><img class="pcf-topic-head__image" src="<?= e($oldestImage) ?>" alt="<?= e($authorName) ?>" loading="lazy" decoding="async"><?php endif; ?>
  <div>
    <h1 class="pcf-hero__title"><?= e($title) ?></h1>
    <?php if (!empty($row['ruby'])): ?><p class="pcf-list-card__meta">読み: <?= e((string)$row['ruby']) ?></p><?php endif; ?>
    <p class="pcf-list-card__meta">関連作品: <?= e((string)count($list)) ?>件</p>
  </div>
</section>

<h2 class="pcf-section-title">関連商品</h2>
<?php if ($list !== []): ?>
  <section class="pcf-related-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));">
    <?php foreach ($list as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
<?php else: ?>
  <?php pcf_render_empty('この作者の関連商品はまだありません。'); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
