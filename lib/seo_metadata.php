<?php
declare(strict_types=1);

function pcf_meta_description(string $description, string $title, string $script, string $site): string
{
    $clean = static fn(string $s): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    $text = $clean($description);
    if (mb_strlen($text, 'UTF-8') < 70) {
        $subject = $clean($title);
        if ($text === '') $text = $subject . '。';
        if ($script === 'item.php') {
            $text .= '作品情報や出演者、ジャンル、提供されているサンプルを確認できます。購入・視聴の詳細はリンク先のSOKUMIRUでご確認ください。';
        } elseif (in_array($script, ['index.php','items.php','actresses.php','actress.php','genres.php','genre.php','makers.php','maker.php','labels.php','label.php','series_list.php','series_detail.php','authors.php','author.php','search.php'], true)) {
            $text .= $subject . 'の掲載情報を確認し、作品名や出演者、ジャンルから気になる作品を探せます。各作品ページでは作品情報と提供されているサンプルをご案内しています。';
        }
        if ($script !== 'page.php') $text .= $site . 'は18歳以上向けのSOKUMIRU作品紹介サイトです。';
    }
    return mb_strlen($text, 'UTF-8') > 160 ? mb_substr($text, 0, 159, 'UTF-8') . '…' : $text;
}


function pcf_video_object(string $title, string $description, string $thumbnail, string $embed, string $uploadedAt): ?array
{
    // Product release dates and today's date are not sample-video upload dates.
    if ($uploadedAt === '' || !filter_var($thumbnail, FILTER_VALIDATE_URL) || !filter_var($embed, FILTER_VALIDATE_URL)) return null;
    if (!in_array(parse_url($thumbnail, PHP_URL_SCHEME), ['http','https'], true) || !in_array(parse_url($embed, PHP_URL_SCHEME), ['http','https'], true)) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D', $uploadedAt)) return null;
    try {
        $date = new DateTimeImmutable($uploadedAt);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) return null;
    } catch (Throwable) { return null; }
    return ['@type'=>'VideoObject', 'name'=>$title . ' サンプル動画', 'description'=>$description,
        'thumbnailUrl'=>[$thumbnail], 'uploadDate'=>$date->format(DATE_ATOM), 'embedUrl'=>$embed];
}
