<?php
declare(strict_types=1);

require_once __DIR__ . '/access_analytics.php';

function analytics_secure_normalize_path(string $rawPath): string
{
    $path = (string)(parse_url($rawPath, PHP_URL_PATH) ?: '/');
    if ($path === '' || $path[0] !== '/') $path = '/';
    $query = [];
    parse_str((string)(parse_url($rawPath, PHP_URL_QUERY) ?? ''), $query);
    unset($query['rank_period'], $query['pcf_nocache']);
    ksort($query);
    $qs = http_build_query($query);
    return mb_substr($path . ($qs !== '' ? '?' . $qs : ''), 0, 255);
}

function analytics_secure_secret(): string
{
    $secret = trim((string)config_get('security.ip_hash_salt', ''));
    if ($secret !== '') return $secret;
    $dbName = (string)config_get('db.name', config_get('db.dbname', 'pinkclub'));
    return hash('sha256', __DIR__ . '|' . $dbName . '|pinkclub-beacon-token');
}

function analytics_secure_token(string $path, ?int $issuedAt = null): string
{
    $issuedAt ??= time();
    $normalized = analytics_secure_normalize_path($path);
    $visitor = analytics_visitor_hash((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $signature = hash_hmac('sha256', $issuedAt . "\n" . $normalized . "\n" . $visitor, analytics_secure_secret());
    return $issuedAt . '.' . $signature;
}

function analytics_secure_token_valid(string $token, string $path): bool
{
    if (preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m) !== 1) return false;
    $issuedAt = (int)$m[1];
    if ($issuedAt > time() - 2 || $issuedAt < time() - 1800) return false;
    return hash_equals(analytics_secure_token($path, $issuedAt), $token);
}

function analytics_secure_same_origin_get(): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return false;
    if (function_exists('auth_user') && auth_user()) return false;
    if ((string)($_SERVER['HTTP_DNT'] ?? '') === '1' || strtolower((string)($_SERVER['HTTP_SEC_GPC'] ?? '')) === '1') return false;
    if (analytics_request_is_automated()) return false;
    $siteHost = analytics_normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($siteHost === '') return false;
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin','none'], true)) return false;
    $originHost = analytics_normalize_host((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $refererHost = analytics_normalize_host((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($originHost !== '' && !hash_equals($siteHost, $originHost)) return false;
    if ($refererHost !== '' && !hash_equals($siteHost, $refererHost)) return false;
    return $fetchSite === 'same-origin' || $originHost !== '' || $refererHost !== '';
}
