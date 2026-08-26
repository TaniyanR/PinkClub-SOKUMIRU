<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rss_display_balance.php';

function rss_trade_enrich_items(array $items): array
{
    $sourceIds = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $sourceId = (int)($item['source_id'] ?? 0);
        if ($sourceId > 0) $sourceIds[$sourceId] = true;
    }
    if ($sourceIds === []) return $items;
    $metaBySource = [];
    try {
        $ids = array_keys($sourceIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare('SELECT rs.id AS source_id, pr.partner_site_id, ps.ref_code, ps.url AS partner_site_url FROM rss_sources rs LEFT JOIN partner_rss pr ON pr.id = rs.source_ref_id LEFT JOIN partner_sites ps ON ps.id = pr.partner_site_id WHERE rs.id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $sourceId = (int)($row['source_id'] ?? 0);
            if ($sourceId <= 0) continue;
            $metaBySource[$sourceId] = ['partner_site_id'=>(int)($row['partner_site_id']??0),'partner_ref_code'=>trim((string)($row['ref_code']??'')),'partner_site_url'=>trim((string)($row['partner_site_url']??''))];
        }
    } catch (Throwable $e) { error_log('[rss] access-trade metadata lookup failed: ' . $e->getMessage()); return $items; }
    foreach ($items as &$item) {
        if (!is_array($item)) continue;
        $meta = $metaBySource[(int)($item['source_id'] ?? 0)] ?? null;
        if (!is_array($meta)) continue;
        $item = array_merge($item, $meta);
    }
    unset($item);
    return $items;
}

function rss_trade_metrics_by_ref(array $refs, int $days = 30): array
{
    $refs = array_values(array_unique(array_filter(array_map('strval',$refs), static fn(string $v): bool => trim($v)!=='')));
    if ($refs === []) return [];
    $days = max(1,min(365,$days));
    $metrics=[]; foreach($refs as $ref) $metrics[$ref]=['in'=>0,'out'=>0];
    try {
        $ph=implode(',',array_fill(0,count($refs),'?'));
        $stmt=db()->prepare('SELECT ref_code,COUNT(*) c FROM in_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL '.$days.' DAY) AND ref_code IN ('.$ph.') GROUP BY ref_code'); $stmt->execute($refs);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$ref=(string)($r['ref_code']??''); if(isset($metrics[$ref]))$metrics[$ref]['in']=(int)($r['c']??0);}
        $stmt=db()->prepare('SELECT ref_code,COUNT(*) c FROM out_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL '.$days.' DAY) AND ref_code IN ('.$ph.') GROUP BY ref_code'); $stmt->execute($refs);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$ref=(string)($r['ref_code']??''); if(isset($metrics[$ref]))$metrics[$ref]['out']=(int)($r['c']??0);}
    } catch(Throwable $e){error_log('[rss] access-trade metrics lookup failed: '.$e->getMessage());}
    return $metrics;
}

function rss_trade_weight(int $inCount,int $outCount): float
{
    $inCount=max(0,$inCount); $outCount=max(0,$outCount);
    if($inCount===0&&$outCount===0)return 0.5;
    $debt=max(0,$inCount-$outCount);
    if($debt>0)return min(120.0,1.0+(float)$debt+min(10.0,sqrt((float)$inCount)/2.0));
    return max(0.15,0.5/(1.0+(max(0,$outCount-$inCount)/10.0)));
}

function rss_trade_split_columns(array $items): array
{
    $left=[];$right=[];$lastLeft=null;$lastRight=null;
    foreach($items as $item){if(!is_array($item))continue;$key=rss_partner_display_source_key($item);$lc=count($left);$rc=count($right);
        if($lc<$rc)$target='left'; elseif($rc<$lc)$target='right'; else { $lr=$key!==''&&$key===$lastLeft; $rr=$key!==''&&$key===$lastRight; if($lr&&!$rr)$target='right'; elseif($rr&&!$lr)$target='left'; else $target=random_int(0,1)===0?'left':'right'; }
        if($target==='left'){$left[]=$item;$lastLeft=$key;}else{$right[]=$item;$lastRight=$key;}
    }
    return [$left,$right];
}

function rss_trade_out_url(array $item): string
{
    $target=trim((string)($item['link']??''));$partnerId=(int)($item['partner_site_id']??0);$ref=trim((string)($item['partner_ref_code']??''));
    if($target===''||$partnerId<=0||$ref==='')return $target;
    $query=http_build_query(['partner'=>$partnerId,'ref'=>$ref,'to'=>$target]);
    return function_exists('public_url')?public_url('out.php?'.$query):'/out.php?'.$query;
}
