<?php

declare(strict_types=1);

/** Portrait cover supplied by SOKUMIRU; never substitute the landscape package/OGP. */
function item_front_cover_url(array $item): string
{
    $raw = json_decode((string)($item['raw_json'] ?? ''), true);
    $candidates = [$item['image_small'] ?? '', $raw['imageURL']['small'] ?? '', $raw['packageImage']['small'] ?? ''];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        $url = trim($candidate);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }
    }
    return '';
}

function image_fallback_url(): string
{
    return asset_url('img/no-image.png');
}

function item_sample_image_urls(array $item): array
{
    $raw = $item['raw_json'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $sample = $decoded['sampleImageURL']['sample_s']['image'] ?? [];
    if (!is_array($sample)) {
        return [];
    }
    return array_values(array_filter($sample, static fn($v): bool => is_string($v) && $v !== ''));
}

function item_sample_movie_url(array $item): ?string
{
    $raw = $item['raw_json'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $movie = $decoded['sampleMovieURL']['size_720_480'] ?? $decoded['sampleMovieURL']['size_644_414'] ?? null;
    return is_string($movie) && $movie !== '' ? $movie : null;
}
