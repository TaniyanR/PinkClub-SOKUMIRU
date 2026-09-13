<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PCF_SITE_MEDIA_KEYS = ['logo', 'favicon', 'ogp'];

function site_media_key_allowed(string $key): bool { return in_array($key, PCF_SITE_MEDIA_KEYS, true); }

function site_media_ensure_table(): bool
{
    static $attempted=false,$ready=false;
    if ($attempted) return $ready;
    $attempted=true;
    try { db()->query('SELECT 1 FROM site_media LIMIT 1'); return $ready=true; } catch (Throwable) {}
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS site_media (media_key VARCHAR(32) NOT NULL,file_name VARCHAR(255) NOT NULL,mime_type VARCHAR(64) NOT NULL,width INT UNSIGNED NOT NULL DEFAULT 0,height INT UNSIGNED NOT NULL DEFAULT 0,byte_size INT UNSIGNED NOT NULL DEFAULT 0,sha256 CHAR(64) NOT NULL,media_data LONGBLOB NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(media_key),KEY idx_site_media_updated_at(updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ready=true;
    } catch (Throwable $e) { error_log('[site_media] ensure failed: '.$e->getMessage()); }
    return $ready;
}

function site_media_cache_clear(?string $key=null): void
{
    if ($key===null) { unset($GLOBALS['__site_media_meta_cache'],$GLOBALS['__site_media_blob_cache']); return; }
    foreach(['__site_media_meta_cache','__site_media_blob_cache'] as $cache){ if(isset($GLOBALS[$cache])&&is_array($GLOBALS[$cache])) unset($GLOBALS[$cache][$key]); }
}

function site_media_query(string $key,bool $blob): ?array
{
    if(!site_media_key_allowed($key)) return null;
    try {
        $columns=$blob?'media_key,file_name,mime_type,width,height,byte_size,sha256,media_data,created_at,updated_at':'media_key,file_name,mime_type,width,height,byte_size,sha256,created_at,updated_at';
        $stmt=db()->prepare('SELECT '.$columns.' FROM site_media WHERE media_key=:key LIMIT 1');$stmt->execute([':key'=>$key]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    } catch(Throwable) {
        if(!site_media_ensure_table()) return null;
        try { $columns=$blob?'media_key,file_name,mime_type,width,height,byte_size,sha256,media_data,created_at,updated_at':'media_key,file_name,mime_type,width,height,byte_size,sha256,created_at,updated_at';$stmt=db()->prepare('SELECT '.$columns.' FROM site_media WHERE media_key=:key LIMIT 1');$stmt->execute([':key'=>$key]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null; } catch(Throwable $e){error_log('[site_media] read failed: '.$e->getMessage());return null;}
    }
}

function site_media_meta_get(string $key): ?array
{
    $cache=$GLOBALS['__site_media_meta_cache']??[];if(is_array($cache)&&array_key_exists($key,$cache))return is_array($cache[$key])?$cache[$key]:null;
    $value=site_media_query($key,false);if(!isset($GLOBALS['__site_media_meta_cache'])||!is_array($GLOBALS['__site_media_meta_cache']))$GLOBALS['__site_media_meta_cache']=[];$GLOBALS['__site_media_meta_cache'][$key]=$value;return $value;
}
function site_media_get(string $key): ?array { return site_media_query($key,true); }
function site_media_exists(string $key): bool { return site_media_meta_get($key)!==null; }

function site_media_put(string $key,string $fileName,string $mimeType,int $width,int $height,string $bytes): void
{
    if(!site_media_key_allowed($key)||$bytes==='') throw new InvalidArgumentException('Invalid site media.');
    if(!site_media_ensure_table()) throw new RuntimeException('site_media table unavailable.');
    $stmt=db()->prepare('INSERT INTO site_media(media_key,file_name,mime_type,width,height,byte_size,sha256,media_data,created_at,updated_at) VALUES(:key,:file,:mime,:w,:h,:size,:sha,:data,NOW(),NOW()) ON DUPLICATE KEY UPDATE file_name=VALUES(file_name),mime_type=VALUES(mime_type),width=VALUES(width),height=VALUES(height),byte_size=VALUES(byte_size),sha256=VALUES(sha256),media_data=VALUES(media_data),updated_at=NOW()');
    $stmt->bindValue(':key',$key);$stmt->bindValue(':file',$fileName);$stmt->bindValue(':mime',$mimeType);$stmt->bindValue(':w',max(0,$width),PDO::PARAM_INT);$stmt->bindValue(':h',max(0,$height),PDO::PARAM_INT);$stmt->bindValue(':size',strlen($bytes),PDO::PARAM_INT);$stmt->bindValue(':sha',hash('sha256',$bytes));$stmt->bindValue(':data',$bytes,PDO::PARAM_LOB);$stmt->execute();site_media_cache_clear($key);
}
function site_media_delete(string $key): void { if(!site_media_key_allowed($key))return;try{db()->prepare('DELETE FROM site_media WHERE media_key=:key')->execute([':key'=>$key]);}catch(Throwable){}site_media_cache_clear($key); }

function site_media_public_path(string $key): string
{
    $m=site_media_meta_get($key);if(!is_array($m))return '';$rev=substr((string)($m['sha256']??''),0,12);$mime=strtolower(trim((string)($m['mime_type']??'')));$ext=match($mime){'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif','image/x-icon','image/vnd.microsoft.icon'=>'ico',default=>'bin'};$q=['key'=>$key];if($rev!=='')$q['v']=$rev;$q['file']=$key.'.'.$ext;return 'site-media.php?'.http_build_query($q);
}
function site_media_public_url(string $key): string { $path=site_media_public_path($key);return $path!==''?public_url($path):''; }
function site_media_url_or_legacy(string $key,string $legacyPath=''): string
{
    $db=site_media_public_url($key);if($db!=='')return $db;$legacyPath=ltrim(trim($legacyPath),'/');if($legacyPath==='')return '';$urlPath=str_starts_with($legacyPath,'uploads/site_settings/')?'public/'.$legacyPath:$legacyPath;$url=public_url($urlPath);$file=__DIR__.'/../public/'.$legacyPath;if(is_file($file)){$v=(string)filemtime($file);if($v!=='')$url.=(str_contains($url,'?')?'&':'?').'v='.rawurlencode($v);}return $url;
}
