<?php
declare(strict_types=1);

function analytics_beacon_injector_start(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true) || $method === 'HEAD') return;
    if (function_exists('auth_user') && auth_user()) return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['analytics.php','analytics_token.php','page_view_beacon.php','rss_trade_fragment.php','feed.php','rss.php','sitemap.php','robots.php','out.php'], true)) return;

    $tokenEndpoint = function_exists('public_url') ? public_url('analytics_token.php') : '/analytics_token.php';
    $beaconEndpoint = function_exists('public_url') ? public_url('analytics.php') : '/analytics.php';
    $js = '<script>(function(){if(navigator.doNotTrack==="1"||window.doNotTrack==="1"||navigator.globalPrivacyControl===true||navigator.webdriver===true)return;var p=location.pathname+location.search;var t=' . json_encode($tokenEndpoint, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . ';var a=' . json_encode($beaconEndpoint, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . ';var sent=false;function go(){if(sent||document.visibilityState!=="visible")return;fetch(t+"?path="+encodeURIComponent(p),{credentials:"same-origin",cache:"no-store",headers:{"Accept":"application/json"}}).then(function(r){if(!r.ok)throw new Error("token");return r.json();}).then(function(j){if(!j||!j.token)return;setTimeout(function(){if(sent||document.visibilityState!=="visible")return;var d=new FormData();d.append("path",p);d.append("referrer",document.referrer||"");try{d.append("ref",new URLSearchParams(location.search).get("ref")||"");}catch(e){d.append("ref","");}d.append("token",j.token);sent=true;if(!(navigator.sendBeacon&&navigator.sendBeacon(a,d))&&window.fetch)fetch(a,{method:"POST",body:d,credentials:"same-origin",keepalive:true}).catch(function(){});},2500);}).catch(function(){});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",go,{once:true});else go();document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible"&&!sent)go();});}());</script>';

    ob_start(static function (string $html) use ($js): string {
        if ($html === '' || !str_contains($html, '</body>')) return $html;
        return str_replace('</body>', $js . '</body>', $html);
    });
}
