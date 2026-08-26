<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
header('Content-Type: application/xml; charset=UTF-8');

function sitemap_e(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function sitemap_url(string $loc, string $changefreq, string $priority, string $lastmod = ''): void
{
    echo "  <url>\n";
    echo '    <loc>' . sitemap_e($loc) . "</loc>\n";
    if ($lastmod !== '') echo '    <lastmod>' . sitemap_e(substr($lastmod, 0, 10)) . "</lastmod>\n";
    echo '    <changefreq>' . sitemap_e($changefreq) . "</changefreq>\n";
    echo '    <priority>' . sitemap_e($priority) . "</priority>\n";
    echo "  </url>\n";
}
function sitemap_product_where(string $alias): string { return items_product_source_where($alias); }
function sitemap_sources(): array
{
    $sources=[];
    if(db_table_exists('items'))$sources[]=['from'=>'items entity','path'=>'item.php','changefreq'=>'weekly','priority'=>'0.8','where'=>sitemap_product_where('entity')];
    $masterSources=['genres'=>['relation'=>'item_genres','path'=>'genre.php'],'series_master'=>['relation'=>'item_series','path'=>'series_detail.php'],'actresses'=>['relation'=>'item_actresses','path'=>'actress.php'],'makers'=>['relation'=>'item_makers','path'=>'maker.php']];
    foreach($masterSources as $table=>$config){$relation=(string)$config['relation'];if(!db_table_exists($table)||!db_table_exists($relation))continue;$where=["TRIM(COALESCE(entity.name, '')) <> ''","LOWER(entity.name) NOT LIKE '%http://%'","LOWER(entity.name) NOT LIKE '%https://%'","LOWER(entity.name) NOT LIKE '%www.%'","entity.name NOT LIKE '%/%'"];
        if($table==='series_master'){$redirectSeriesIds=array_keys(series_canonical_maker_redirects());if($redirectSeriesIds!==[])$where[]='entity.id NOT IN ('.implode(',',array_map('intval',$redirectSeriesIds)).')';}
        if($table==='makers'&&db_table_exists('mutual_links'))$where[]='NOT EXISTS (SELECT 1 FROM mutual_links ml WHERE ml.site_name = entity.name)';
        if(db_column_exists($relation,'item_id'))$where[]='EXISTS (SELECT 1 FROM '.$relation.' relation_row INNER JOIN items related_item ON related_item.id = relation_row.item_id WHERE relation_row.dmm_id = entity.dmm_id AND '.sitemap_product_where('related_item').')';
        else{$legacyIdColumn=match($table){'genres'=>'genre_id','series_master'=>'series_id','actresses'=>'actress_id','makers'=>'maker_id'};$where[]='EXISTS (SELECT 1 FROM '.$relation.' relation_row INNER JOIN items related_item ON related_item.content_id = relation_row.content_id WHERE relation_row.'.$legacyIdColumn.' = entity.id AND '.sitemap_product_where('related_item').')';}
        $sources[]=['from'=>$table.' entity','path'=>(string)$config['path'],'changefreq'=>'weekly','priority'=>'0.7','where'=>implode(' AND ',$where)];
    }
    return $sources;
}
function sitemap_source_count(array $source): int
{
    try{return (int)db()->query('SELECT COUNT(*) FROM '.$source['from'].' WHERE '.$source['where'])->fetchColumn();}
    catch(Throwable $e){error_log('sitemap count failed: '.$source['from'].': '.$e->getMessage());return 0;}
}
function sitemap_emit_source(array $source,int $start,int &$remaining): int
{
    $count=sitemap_source_count($source);if($remaining<=0||$start>=$count)return $count;$limit=min($remaining,$count-$start);
    try{$sql='SELECT entity.id FROM '.$source['from'].' WHERE '.$source['where'].' ORDER BY entity.id ASC LIMIT :limit OFFSET :offset';$stmt=db()->prepare($sql);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$start,PDO::PARAM_INT);$stmt->execute();while($row=$stmt->fetch()){$id=(int)($row['id']??0);if($id<=0)continue;sitemap_url(public_url($source['path']).'?id='.rawurlencode((string)$id),$source['changefreq'],$source['priority']);$remaining--;}}
    catch(Throwable $e){error_log('sitemap rows failed: '.$source['from'].': '.$e->getMessage());}
    return $count;
}

$perSitemap=10000;
$staticUrls=[[public_url('index.php'),'daily','1.0'],[public_url('items.php'),'daily','0.9']];
$sources=sitemap_sources();$totalUrls=count($staticUrls);foreach($sources as $source)$totalUrls+=sitemap_source_count($source);
$totalParts=max(1,(int)ceil($totalUrls/$perSitemap));

if((isset($_GET['index'])&&(string)$_GET['index']==='1')||($totalUrls>$perSitemap&&!isset($_GET['part']))){
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    for($i=1;$i<=$totalParts;$i++){echo "  <sitemap>\n".'    <loc>'.sitemap_e(public_url('sitemap.php').'?part='.$i)."</loc>\n  </sitemap>\n";}
    echo "</sitemapindex>\n";return;
}

$part=1;
if(isset($_GET['part'])){
    $validated=filter_var($_GET['part'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$totalParts]]);
    if($validated===false){http_response_code(404);echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>\n";return;}
    $part=(int)$validated;
}
$start=($part-1)*$perSitemap;$remaining=$perSitemap;
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach($staticUrls as $index=>$url){if($index<$start)continue;if($remaining<=0)break;sitemap_url((string)$url[0],(string)$url[1],(string)$url[2]);$remaining--;}
$start=max(0,$start-count($staticUrls));
foreach($sources as $source){$count=sitemap_emit_source($source,$start,$remaining);$start=max(0,$start-$count);}
echo "</urlset>\n";
