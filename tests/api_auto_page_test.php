<?php
$mode = $argv[1] ?? 'get';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($mode !== 'missing') {
 $pdo->exec('CREATE TABLE sync_job_state (job_key TEXT PRIMARY KEY,next_offset INTEGER)');
 $pdo->exec("INSERT INTO sync_job_state VALUES('items',321)");
}
$GLOBALS['writes']=[];
function db(): PDO { return $GLOBALS['pdo']; }
function auth_require_admin(): void {}
function csrf_validate_or_fail($v): void {}
function csrf_input(): string {return '';}
function post($k,$default=''){return $_POST[$k]??$default;}
function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES);}
function settings_get(): array {return ['item_sync_interval_minutes'=>60,'item_sync_batch'=>100];}
function settings_bool($k,$v): bool {return false;}
function site_setting_get($k,$v): string {return $v;}
function db_table_exists($k): bool {return false;}
function scheduler_ensure_schedule_table($pdo): void {
 $GLOBALS['writes'][]='prepare';
 if(in_array($GLOBALS['mode'],['get','missing','save_fail'],true))throw new RuntimeException('DDL denied');
}
function scheduler_seed_default_schedules($pdo):void {$GLOBALS['writes'][]='seed';}
function site_setting_set_many($data):void {$GLOBALS['writes'][]='settings';}
function scheduler_apply_auto_settings($pdo):void {
 $GLOBALS['writes'][]='apply';
 if($GLOBALS['mode']==='apply_fail')throw new RuntimeException('update denied');
}
$_SERVER['REQUEST_METHOD']=in_array($mode,['get','missing'],true)?'GET':'POST';
$_POST=[];
$code=file_get_contents(dirname(__DIR__).'/admin/api_auto.php');
$code=preg_replace('/^require_once .*;$/m','',$code);
$code=preg_replace('/require __DIR__ \. \'\/includes\/(?:header|footer)\.php\';/','',$code);
ob_start();eval('?>'.$code);$html=ob_get_clean();
function check($b,$m){if(!$b)throw new RuntimeException($m);}
check(str_contains($html,'<h1>自動設定</h1>'),'page rendered');
if(in_array($mode,['get','missing'],true))check($GLOBALS['writes']===[],'GET must never write');
if($mode==='get')check(str_contains($html,'321'),'legacy schema offset preserved');
if(in_array($mode,['missing','save_fail','apply_fail'],true))check(str_contains($html,'エラー識別番号'),'failure visible');
if($mode==='save_fail')check(!in_array('settings',$GLOBALS['writes'],true),'no partial settings on prepare failure');
if($mode==='apply_fail')check(str_contains($html,'設定値は保存されましたが'),'partial save explicitly shown');
if($mode==='save')check($GLOBALS['writes']===['prepare','seed','settings','apply']&&str_contains($html,'自動設定を保存しました。'),'successful save');
echo 'PASS '.$mode."\n";
