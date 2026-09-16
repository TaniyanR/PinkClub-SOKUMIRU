<?php

if (!function_exists('get_ad_code')) {
    function get_ad_code(string $position_key): ?string
    {
        static $cache = [];
        if (array_key_exists($position_key, $cache)) return $cache[$position_key];
        if (!function_exists('db')) return null;
        try {
            $stmt = db()->prepare('SELECT snippet_html FROM code_snippets WHERE slot_key = :slot AND is_enabled = 1 LIMIT 1');
            $stmt->execute([':slot' => $position_key]);
            $html = $stmt->fetchColumn();
            $code = is_string($html) ? trim($html) : '';
            return $cache[$position_key] = ($code !== '' ? $code : null);
        } catch (Throwable) { return $cache[$position_key] = null; }
    }
}

if (!function_exists('render_ad')) {
    function render_ad(string $position_key, string $page_type = 'home', string $device = 'pc'): void
    {
        $html = get_ad_code($position_key);
        if ($html === null) {
            return;
        }

        // These configured mobile providers render their banner in the parent document.
        // A sandboxed srcdoc isolates the provider script and leaves the visible slot empty.
        if (in_array($position_key, ['sp_header_below', 'sp_footer_above'], true)) {
            echo $html;
            return;
        }

        render_deferred_ad_html($html, $position_key);
    }
}

if (!function_exists('render_deferred_ad_html')) {
    function render_deferred_ad_html(string $html, string $positionKey = ''): void
    {
        $html = trim($html);
        if ($html === '') return;

        static $counter = 0;
        static $listenerRendered = false;
        $counter++;
        $token = 'pcf-ad-' . $counter;
        $isRectangle = str_contains($positionKey, 'sidebar') || $positionKey === 'content_bottom';
        $height = $isRectangle ? 250 : 100;
        $loading = str_contains($positionKey, 'header') ? 'eager' : 'lazy';

        if (!$listenerRendered) {
            $listenerRendered = true;
            ?>
<script>
(function(){
  if(window.__pcfAdResizeReady)return;
  window.__pcfAdResizeReady=true;
  window.addEventListener('message',function(event){
    var data=event.data;
    if(!data||data.type!=='pcf-ad-height'||typeof data.token!=='string')return;
    var frames=document.querySelectorAll('iframe[data-pcf-ad-token]');
    var frame=null;
    for(var i=0;i<frames.length;i++){
      if(frames[i].dataset.pcfAdToken===data.token){frame=frames[i];break;}
    }
    if(!frame||event.source!==frame.contentWindow)return;
    var height=Math.max(20,Math.min(1200,Number(data.height)||0));
    if(height)frame.style.height=Math.ceil(height)+'px';
  });
}());
</script>
            <?php
        }

        $resizeScript = '<script>(function(){var token=' . json_encode($token, JSON_UNESCAPED_SLASHES) . ';var send=function(){var d=document.documentElement,b=document.body;var h=Math.max(d?d.scrollHeight:0,b?b.scrollHeight:0);parent.postMessage({type:"pcf-ad-height",token:token,height:h},"*");};if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",send,{once:true});else send();window.addEventListener("load",send,{once:true});if("ResizeObserver" in window)new ResizeObserver(send).observe(document.documentElement);}());</script>';
        $document = '<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base target="_blank"><style>html,body{margin:0;padding:0;overflow:hidden;text-align:center}img,iframe{max-width:100%}</style></head><body>' . $html . $resizeScript . '</body></html>';
        ?>
<iframe
  class="pcf-deferred-ad"
  data-pcf-ad-token="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"
  title="広告"
  srcdoc="<?= htmlspecialchars($document, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
  loading="<?= $loading ?>"
  referrerpolicy="strict-origin-when-cross-origin"
  sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
  style="display:block;width:100%;height:<?= $height ?>px;border:0;overflow:hidden"
></iframe>
        <?php
    }
}

if (!function_exists('should_show_ad')) {
    function should_show_ad(string $position_key, string $page_type = 'home', string $device = 'pc'): bool
    { return get_ad_code($position_key) !== null; }
}

if (!function_exists('rss_fragment_loader_script')) {
    function rss_fragment_loader_script(): void
    {
        static $rendered = false;
        if ($rendered || !empty($GLOBALS['pcf_rss_fragment_request'])) return;
        $rendered = true;
        $endpoint = function_exists('public_url') ? public_url('rss_trade_fragment.php') : '/rss_trade_fragment.php';
        ?>
<script>
(function(){
  var endpoint=<?= json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var refresh=function(){
    var groups={};
    document.querySelectorAll('[data-rss-fragment]').forEach(function(node){var type=node.getAttribute('data-rss-fragment');if(!type)return;if(!groups[type])groups[type]=[];groups[type].push(node);});
    Object.keys(groups).forEach(function(type){var nodes=groups[type];if(!nodes.length)return;fetch(endpoint+'?type='+encodeURIComponent(type),{credentials:'same-origin',cache:'no-store'}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();}).then(function(html){if(!html)return;var holder=document.createElement('div');holder.innerHTML=html.trim();var replacement=holder.firstElementChild;if(!replacement)return;nodes.forEach(function(node){node.replaceWith(replacement.cloneNode(true));});}).catch(function(){});});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
}());
</script>
        <?php
    }
}

if (!function_exists('render_shared_text_rss_widget')) {
    function render_shared_text_rss_widget(): void
    {
        $prev = $GLOBALS['pcf_rss_widget_max_items'] ?? null;
        unset($GLOBALS['pcf_rss_widget_max_items']);
        include __DIR__ . '/rss_text_widget.php';
        if ($prev === null) unset($GLOBALS['pcf_rss_widget_max_items']); else $GLOBALS['pcf_rss_widget_max_items'] = $prev;
    }
}

if (!function_exists('render_shared_mobile_rss_widget')) {
    function render_shared_mobile_rss_widget(): void { render_shared_text_rss_widget(); }
}

if (!function_exists('render_shared_content_ad_row')) {
    function render_shared_content_ad_row(string $position_key, string $page_type): void
    {
        if ($position_key !== 'content_bottom') return;
        require_once __DIR__ . '/../../lib/app_features.php';
        require_once __DIR__ . '/../../lib/rss_display_balance.php';
        require_once __DIR__ . '/../../lib/rss_access_trade.php';
        require_once __DIR__ . '/../../lib/rss_access_trade_host.php';
        require_once __DIR__ . '/../../lib/rss_access_trade_candidate.php';
        $items=[];
        try { rss_widget_bootstrap(false); $items=rss_trade_select_host_aware(rss_trade_candidate_pool(60,false,14),40,40,30); }
        catch(Throwable $e){ error_log('[rss] bottom access-trade widget skipped: '.$e->getMessage()); }
        [$leftItems,$rightItems]=rss_trade_split_columns($items);
        $renderColumn=static function(array $columnItems): string {
            ob_start(); echo '<div class="rss-widget rss-widget--text block"><div class="rss-box">';
            if($columnItems===[]) echo '<p class="sidebar-empty">テキストRSSの記事がありません。</p>';
            else { echo '<ul class="rss-list">'; foreach($columnItems as $item){$href=rss_trade_out_url($item);echo '<li class="rss-list__item"><a href="'.e($href).'" target="_blank" rel="noopener">'.e((string)($item['title']??'')).'</a></li>'; } echo '</ul>'; }
            echo '</div></div>'; return (string)ob_get_clean();
        };
        echo '<div class="content-ad-row content-ad-row--rss-split" data-rss-fragment="bottom" style="margin-top:20px;">';
        echo '<div class="content-ad-row__rss">'.$renderColumn($leftItems).'</div><div class="content-ad-row__rss">'.$renderColumn($rightItems).'</div></div>';
        rss_fragment_loader_script();
    }
}
