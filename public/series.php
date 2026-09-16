<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// 旧シリーズ一覧URLは既存リンク用に残し、現行一覧へ恒久転送する。
header('Location: ' . public_url('series_list.php'), true, 301);
exit;
