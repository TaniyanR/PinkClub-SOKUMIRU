<?php
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function db(): PDO { return $GLOBALS['pdo']; }
function items_product_source_where(string $alias): string { return '1=1'; }
function pcf_item_title(array $item): string { return $item['title']; }
function public_url(string $url): string { return '/'.$url; }
// Use the actual items schema column names, without adding invented compatibility columns.
$schema = file_get_contents(dirname(__DIR__).'/sql/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS items \((.*?)\) ENGINE/s', $schema, $m);
preg_match_all('/^  ([a-z_]+) (?:INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|TINYINT|DATE)/m', $m[1], $cols);
$pdo->exec('CREATE TABLE items ('.implode(',', array_map(fn($c)=>$c.' TEXT', $cols[1])).')');
$pdo->exec("INSERT INTO items(id,title,image_small) VALUES(288,'Test product','https://www.sokmil.com/test.jpg')");
if (($argv[1]??'')==='failure') $pdo->exec('DROP TABLE items');
$_GET['ids']='288,999'; $_SERVER['REQUEST_METHOD']='GET';
$code=file_get_contents(dirname(__DIR__).'/public/recently_viewed_items.php');
$code=preg_replace('/^require_once .*;$/m','',$code);
ob_start();
eval('?>'.$code);
$output = json_decode(ob_get_clean(), true);
if (($output['items'][0]['id'] ?? null) !== 288 || count($output['items'] ?? []) !== 1) {
    throw new RuntimeException('Existing products must be returned using the installed schema.');
}
echo "PASS recently viewed query against items schema\n";
