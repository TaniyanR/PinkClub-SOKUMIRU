<?php

declare(strict_types=1);

function image_fallback_url(): string
{
    return asset_url('img/no-image.png');
}

/**
 * Convert SOKUMIRU's capture-small URL (cs) to its capture-large URL (cl).
 * Callers keep the original URL as an on-error fallback.
 */
function sokumiru_large_sample_image_url(string $url): string
{
    $value = trim($url);
    $parts = parse_url($value);
    if ($value === '' || !is_array($parts)) {
        return $value;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    $largePath = '';

    if ($host === 'img.sokmil.com' && preg_match('#^/image/capture/cs_(.+)$#i', $path, $matches) === 1) {
        $largePath = '/image/capture/cl_' . $matches[1];
    } elseif ($host === 'imgcap.sokmil.com' && preg_match('#^(/pict/capture/[^/]+/[^/]+)/cs/cs_(.+)$#i', $path, $matches) === 1) {
        $largePath = $matches[1] . '/cl/cl_' . $matches[2];
    }

    if ($largePath === '') {
        return $value;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    if ($scheme !== 'http' && $scheme !== 'https') {
        $scheme = 'https';
    }
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $port . $largePath . $query;
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
    $sample = $decoded['sampleImageURL']['sample_l']['image']
        ?? $decoded['sampleImageURL']['image']
        ?? $decoded['sampleImageURL']['sample_s']['image']
        ?? [];
    if (!is_array($sample)) {
        return [];
    }
    return array_values(array_filter(array_map(
        static fn($v): string => is_string($v) ? sokumiru_large_sample_image_url($v) : '',
        $sample
    ), static fn(string $v): bool => $v !== ''));
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
