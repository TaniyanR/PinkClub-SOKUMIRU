<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'サイト設定';
$message = null;
$error = null;
$recommendedTagline = 'SOKUMIRUの新着・人気アダルト動画を、サンプル動画・画像を見ながら出演者やジャンルから手軽に探せる作品情報サイトです。';
$recommendedKeywords = 'PinkClub SOKUMIRU,SOKUMIRU,新着動画,人気動画,アダルト動画,サンプル動画,サンプル画像,出演者,ジャンル,メーカー,シリーズ';
$normalizePinkClubName = static function (string $value): string {
    $value = trim($value);
    $normalized = preg_replace('/^PinkClub\s*[-‐‑‒–—]\s*/u', 'PinkClub ', $value);
    return is_string($normalized) ? trim($normalized) : $value;
};

$uploadDir = __DIR__ . '/../public/uploads/site_settings';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$saveImage = static function (array $file, string $prefix, int $minW, int $maxW, int $minH, int $maxH, bool $squareOnly, array $allowedMimes) use ($uploadDir): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok'=>false,'message'=>'アップロードに失敗しました。'];
    $size=(int)($file['size']??0); if($size<1||$size>5*1024*1024)return ['ok'=>false,'message'=>'画像ファイルは5MB以内で指定してください。'];
    $tmp=(string)($file['tmp_name']??''); if($tmp===''||!is_uploaded_file($tmp))return ['ok'=>false,'message'=>'不正なファイルです。'];
    $info=@getimagesize($tmp); if(!is_array($info))return ['ok'=>false,'message'=>'画像ファイルを指定してください。'];
    [$w,$h]=$info; $mime=strtolower((string)($info['mime']??''));
    if(!in_array($mime,$allowedMimes,true))return ['ok'=>false,'message'=>'対応していない画像形式です。'];
    if($w<$minW||$w>$maxW||$h<$minH||$h>$maxH){
        if($squareOnly)return ['ok'=>false,'message'=>sprintf('画像サイズは正方形で %d〜%dpx の範囲で指定してください。',$minW,$maxW)];
        return ['ok'=>false,'message'=>sprintf('画像サイズは %d-%dpx x %d-%dpx の範囲で指定してください。',$minW,$maxW,$minH,$maxH)];
    }
    if($squareOnly&&$w!==$h)return ['ok'=>false,'message'=>'ファビコンは正方形のみ対応です。'];
    if(!is_dir($uploadDir)||!is_writable($uploadDir))return ['ok'=>false,'message'=>'保存先ディレクトリを作成できませんでした。'];
    $extMap=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'];$ext=$extMap[$mime]??'';if($ext==='')return ['ok'=>false,'message'=>'画像形式を判定できませんでした。'];
    try{$suffix=bin2hex(random_bytes(10));}catch(Throwable){$suffix=str_replace('.','',uniqid('',true));}
    $safePrefix=preg_replace('/[^a-z0-9_-]/i','',$prefix)?:'image';$name=$safePrefix.'-'.$suffix.'.'.$ext;$dest=$uploadDir.'/'.$name;
    if(!move_uploaded_file($tmp,$dest))return ['ok'=>false,'message'=>'画像の保存に失敗しました。'];
    @chmod($dest,0644);$bytes=@file_get_contents($dest);if(!is_string($bytes)||$bytes===''){@unlink($dest);return ['ok'=>false,'message'=>'画像の保存内容を確認できませんでした。'];}
    return ['ok'=>true,'path'=>'uploads/site_settings/'.$name,'name'=>$name,'mime'=>$mime,'width'=>(int)$w,'height'=>(int)$h,'bytes'=>$bytes];
};

$persistMedia = static function (string $key, array $result): void {
    site_media_put($key,(string)$result['name'],(string)$result['mime'],(int)$result['width'],(int)$result['height'],(string)$result['bytes']);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $startYear=filter_var(post('site_start_year',(string)site_start_year()),FILTER_VALIDATE_INT,['options'=>['min_range'=>1900,'max_range'=>(int)date('Y')]]);
    $siteName=$normalizePinkClubName((string)post('site_name',''));$siteUrl=rtrim(trim((string)post('site_url','')),'/');$tagline=trim((string)post('site_tagline',''));$keywords=trim((string)post('site_keywords',''));$adminEmail=trim((string)post('site_admin_email',''));
    $siteUrlParts=$siteUrl!==''?parse_url($siteUrl):false;
    if($startYear===false)$error='開設年は1900年から今年までの西暦で入力してください。';
    if($error===null&&$siteName==='')$error='サイト名を入力してください。';
    if($error===null&&($siteUrl===''||filter_var($siteUrl,FILTER_VALIDATE_URL)===false||!is_array($siteUrlParts)||strtolower((string)($siteUrlParts['scheme']??''))!=='https'||trim((string)($siteUrlParts['host']??''))===''||isset($siteUrlParts['user'])||isset($siteUrlParts['pass'])))$error='URLは https:// から始まる正しいURLを入力してください。';
    if($error===null&&($adminEmail===''||filter_var($adminEmail,FILTER_VALIDATE_EMAIL)===false))$error='お問い合わせ受信メールアドレスを正しく入力してください。';

    $updates=['site.start_year'=>(string)$startYear,'site.title'=>$siteName,'site.name'=>$siteName,'site.url'=>$siteUrl,'site.tagline'=>$tagline,'site.keywords'=>$keywords,'site.admin_email'=>$adminEmail];

    $uploadSpecs=[
        'site_logo'=>['media'=>'logo','prefix'=>'logo','minW'=>250,'maxW'=>400,'minH'=>50,'maxH'=>100,'square'=>false,'mimes'=>['image/png','image/jpeg','image/webp','image/gif'],'setting'=>'site.logo_path'],
        'site_favicon'=>['media'=>'favicon','prefix'=>'favicon','minW'=>48,'maxW'=>512,'minH'=>48,'maxH'=>512,'square'=>true,'mimes'=>['image/png','image/x-icon','image/vnd.microsoft.icon'],'setting'=>'site.favicon_path'],
        'site_ogp'=>['media'=>'ogp','prefix'=>'ogp','minW'=>300,'maxW'=>2400,'minH'=>200,'maxH'=>1350,'square'=>false,'mimes'=>['image/png','image/jpeg','image/webp'],'setting'=>'site.ogp_path'],
    ];
    if($error===null){
        $pdo=db();
        try{
            // CREATE TABLE may implicitly commit in MySQL. Prepare it before the transaction.
            if (!site_media_ensure_table()) throw new RuntimeException('画像保存テーブルを準備できませんでした。');
            $pdo->beginTransaction();
            foreach($uploadSpecs as $field=>$spec){
                if(!isset($_FILES[$field])||(int)($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                $r=$saveImage((array)$_FILES[$field],(string)$spec['prefix'],(int)$spec['minW'],(int)$spec['maxW'],(int)$spec['minH'],(int)$spec['maxH'],(bool)$spec['square'],(array)$spec['mimes']);
                if(($r['ok']??false)!==true)throw new RuntimeException((string)($r['message']??'画像の保存に失敗しました。'));
                $persistMedia((string)$spec['media'],$r);$updates[(string)$spec['setting']]=(string)$r['path'];
            }
            site_setting_set_many($updates);
            $pdo->commit();
            $message='サイト設定を保存しました。';
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}

$logoPath=trim(site_setting_get('site.logo_path',''));$faviconPath=trim(site_setting_get('site.favicon_path',''));$ogpPath=trim(site_setting_get('site.ogp_path',''));
$taglineValue=trim(site_setting_get('site.tagline',''));$taglineNeedsSave=$taglineValue==='';if($taglineNeedsSave)$taglineValue=$recommendedTagline;
$keywordsValue=trim(site_setting_get('site.keywords',''));$keywordsNeedSave=$keywordsValue==='';if($keywordsNeedSave)$keywordsValue=$recommendedKeywords;
$adminEmailValue=function_exists('setting_admin_email')?setting_admin_email(''):site_setting_get('site.admin_email','');
$logoDb=site_media_meta_get('logo');$faviconDb=site_media_meta_get('favicon');$ogpDb=site_media_meta_get('ogp');

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>サイト設定</h1>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>サイト名<input type="text" name="site_name" value="<?= e(site_setting_get('site.title',site_setting_get('site.name',APP_NAME))) ?>"></label>
    <label>開設年（西暦）<input type="number" name="site_start_year" min="1900" max="<?= (int)date('Y') ?>" value="<?= site_start_year() ?>" required></label>
    <p class="admin-form-note">フッターの開始年に使います。未設定時は初期管理者の作成年を表示するため、実際の開設年を入力してください。</p>
    <label>URL<input type="url" name="site_url" value="<?= e(site_setting_get('site.url',app_url())) ?>"></label>
    <label>お問い合わせ受信メールアドレス<input type="email" name="site_admin_email" value="<?= e($adminEmailValue) ?>" required autocomplete="email"><small>一般のお問い合わせ・掲載削除依頼・パスワード再設定メールの受信先として使用します。</small></label>
    <label>総合RSS（10分間隔）<input type="url" value="<?= e(public_url('feed-10.php')) ?>" readonly></label>
    <label>総合RSS（1時間間隔）<input type="url" value="<?= e(public_url('feed-60.php')) ?>" readonly></label>
    <label>ランダムRSS（10分間隔）<input type="url" value="<?= e(public_url('feed-free-10.php')) ?>" readonly></label>
    <label>ランダムRSS（1時間間隔）<input type="url" value="<?= e(public_url('feed-free-60.php')) ?>" readonly></label>
    <label>サイトマップ URL<input type="url" value="<?= e(public_url('sitemap.php')) ?>" readonly></label>
    <label>キャッチフレーズ（検索結果説明用）<input type="text" name="site_tagline" value="<?= e($taglineValue) ?>"><?php if($taglineNeedsSave):?><small>SOKUMIRU向けの初期値を入力しています。内容を確認して保存してください。</small><?php endif;?></label>
    <label>キーワード（meta keywords）<input type="text" name="site_keywords" value="<?= e($keywordsValue) ?>"><?php if($keywordsNeedSave):?><small>SOKUMIRU向けの初期値を入力しています。保存ボタンを押すと反映されます。</small><?php endif;?></label>

    <label>タイトルロゴ（横250〜400px / 高さ50〜100px）<input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif"><small>現在: <?= e(is_array($logoDb)?(string)$logoDb['file_name']:($logoPath!==''?$logoPath:'未設定')) ?></small></label>
    <label>ファビコン（正方形 48〜512px、PNG/ICO）<input type="file" name="site_favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico"><small>現在: <?= e(is_array($faviconDb)?(string)$faviconDb['file_name']:($faviconPath!==''?$faviconPath:'未設定')) ?></small></label>
    <label>共通OGP画像（300〜2400px × 200〜1350px、PNG/JPEG/WebP）<input type="file" name="site_ogp" accept="image/png,image/jpeg,image/webp"><small>商品詳細は商品画像を優先し、それ以外のページで使用します。現在: <?= e(is_array($ogpDb)?(string)$ogpDb['file_name']:($ogpPath!==''?$ogpPath:'未設定')) ?></small></label>

    <div class="admin-actions"><button type="submit">保存</button></div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
