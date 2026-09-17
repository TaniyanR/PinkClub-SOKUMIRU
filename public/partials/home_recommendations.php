<?php
declare(strict_types=1);
?>
<section id="pcf-recommendations" class="pcf-recommendations" data-endpoint="<?= e(public_url('recommendations.php')) ?>" aria-labelledby="pcf-recommendations-title" hidden>
  <div class="pcf-recommendations__heading">
    <div>
      <h2 id="pcf-recommendations-title">あなたへのおすすめ</h2>
      <p>最近見た作品の傾向をもとに、このブラウザ内で選んでいます。</p>
    </div>
    <button id="pcf-recommendations-hide" class="pcf-recommendations__control" type="button">おすすめを表示しない</button>
  </div>
  <div id="pcf-recommendations-list" class="pcf-recommendations__list" aria-live="polite"></div>
</section>
<div id="pcf-recommendations-restore" class="pcf-recommendations-restore" hidden>
  <button id="pcf-recommendations-show" type="button">あなたへのおすすめを表示する</button>
</div>
