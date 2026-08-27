<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/app_features.php';
require_once __DIR__ . '/../../lib/rss_display_balance.php';
require_once __DIR__ . '/../../lib/rss_access_trade.php';
require_once __DIR__ . '/../../lib/rss_access_trade_host.php';
require_once __DIR__ . '/../../lib/rss_access_trade_candidate.php';
require_once __DIR__ . '/../../lib/db.php';

$items=[];
try {
    rss_widget_bootstrap(false);
    $items=rss_trade_select_host_aware(rss_trade_candidate_pool(20,true,14),5,2,30);
} catch(Throwable $e){error_log('[rss] image access-trade selection skipped: '.$e->getMessage());}
?>
<div class="rss-widget rss-widget--image" data-rss-fragment="image">
  <?php if($items!==[]): ?><ul class="rss-image-list">
    <?php foreach($items as $item): ?><li class="rss-image-list__item">
      <?php if(trim((string)($item['image_url']??''))!==''): ?><img src="<?= e((string)$item['image_url']) ?>" alt="" loading="lazy" decoding="async" onerror="this.closest('li').remove();"><?php endif; ?>
      <a href="<?= e(rss_trade_out_url($item)) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($item['title']??'')) ?></a>
    </li><?php endforeach; ?>
  </ul><?php else: ?><p class="sidebar-empty">画像RSSの記事がありません。</p><?php endif; ?>
</div>
<?php rss_fragment_loader_script(); ?>
