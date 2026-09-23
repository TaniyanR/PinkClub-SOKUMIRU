<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/actress_directory_cache.php';

function verify_actress_group(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

verify_actress_group(
    pcf_actress_directory_group_key(['name' => '検証出演者', 'ruby' => 'けんしょうしゅつえんしゃ']) === 'kana:か',
    'Reading-based kana grouping failed.'
);
verify_actress_group(
    pcf_actress_directory_group_key(['name' => '漢字名出演者', 'ruby' => null]) === 'other',
    'Kanji performer without reading was dropped.'
);
verify_actress_group(
    pcf_actress_directory_group_key(['name' => 'Alice', 'ruby' => '']) === 'alpha:A',
    'Alphabetic grouping failed.'
);

echo "PASS: actress directory keeps SOKUMIRU performers without readings\n";
