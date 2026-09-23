<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Legacy series directory URL. Keep it working for old bookmarks and external
// links, but consolidate indexing and crawl signals on the current directory.
header('Location: ' . public_url('series_list.php'), true, 301);
exit;
