<?php
declare(strict_types=1);
?>
<section id="pcf-recently-viewed" class="pcf-recent" aria-labelledby="pcf-recent-title" data-endpoint="<?= e(public_url('recently_viewed_items.php')) ?>" hidden>
  <div class="pcf-recent__heading">
    <div>
      <h2 id="pcf-recent-title">最近見た作品</h2>
      <p>閲覧履歴は、このブラウザ内だけに保存されます。</p>
    </div>
    <div class="pcf-recent__heading-actions">
      <button id="pcf-recent-hide" class="pcf-recent__control" type="button" onclick="try{localStorage.setItem('pcf_recently_viewed_hidden_v1','1')}catch(e){}var s=document.getElementById('pcf-recently-viewed');var r=document.getElementById('pcf-recent-restore');if(s)s.hidden=true;if(r)r.hidden=false;">履歴を表示しない</button>
      <button id="pcf-recent-clear" class="pcf-recent__control" type="button">履歴をすべて削除</button>
    </div>
  </div>
  <div id="pcf-recent-list" class="pcf-recent__list" aria-live="polite"></div>
</section>
<div id="pcf-recent-restore" class="pcf-recent-restore" hidden>
  <button id="pcf-recent-show" type="button" onclick="try{localStorage.removeItem('pcf_recently_viewed_hidden_v1')}catch(e){}var s=document.getElementById('pcf-recently-viewed');var r=document.getElementById('pcf-recent-restore');if(r)r.hidden=true;if(s)s.hidden=false;">最近見た作品を表示する</button>
</div>
<script src="<?= e(asset_url('js/recently-viewed-front-cover.js')) ?>" defer></script>
