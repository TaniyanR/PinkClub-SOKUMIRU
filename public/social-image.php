<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/social_image_endpoint.php';

pcf_social_image_run([
    'service_key' => 'pinkclub-sokumiru',
    'allowed_hosts' => ['sokmil.com', 'sokmil-ad.com'],
    'candidate_fields' => ['image_large', 'image_small', 'image_list'],
    'raw_candidate_fields' => ['imageURL', 'packageImage', 'sampleImageURL'],
    'referer' => 'https://www.sokmil.com/',
    'user_agent' => 'Mozilla/5.0 (compatible; PinkClub-SOKUMIRU-SocialCard/2.0)',
    'item_sql' => 'SELECT * FROM items WHERE id = :id AND ' . items_product_source_where() . ' LIMIT 1',
]);
