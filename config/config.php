<?php

declare(strict_types=1);

$configuredBaseUrl = trim((string) getenv('BASE_URL'));

function normalize_configured_base_url(string $value): string
{
    $normalized = rtrim(trim($value), '/');
    if ($normalized === '') return '';
    $normalized = preg_replace('#/(index\.php|login\.php|login0718\.php|admin/login\.php|admin(?:/index\.php)?|public(?:/index\.php)?)/*$#i', '', $normalized);
    if (!is_string($normalized)) return '';
    return rtrim($normalized, '/');
}

function detect_base_path(string $scriptName): string
{
    $normalized = str_replace('\\', '/', $scriptName);
    if ($normalized === '' || $normalized === '/') return '';
    foreach (['#/(?:public|admin)(?:/.*)?$#i','#/index\.php(?:/.*)?$#i','#/[^/]+\.php(?:/.*)?$#i'] as $pattern) {
        $candidate = preg_replace($pattern, '', $normalized);
        if (is_string($candidate) && $candidate !== $normalized) { $normalized = $candidate; break; }
    }
    $normalized = rtrim($normalized, '/');
    return ($normalized === '' || $normalized === '.') ? '' : $normalized;
}

function detect_base_path_from_request_uri(string $requestUri): string
{
    $path = (string) parse_url($requestUri, PHP_URL_PATH);
    if ($path === '' || $path === '/') return '';
    $normalized = str_replace('\\', '/', $path);
    foreach (['#/(?:public|admin)(?:/.*)?$#i','#/index\.php(?:/.*)?$#i','#/[^/]+\.php(?:/.*)?$#i'] as $pattern) {
        $candidate = preg_replace($pattern, '', $normalized);
        if (is_string($candidate) && $candidate !== $normalized) { $normalized = $candidate; break; }
    }
    $normalized = rtrim($normalized, '/');
    return ($normalized === '' || $normalized === '.') ? '' : $normalized;
}

function apply_detected_path_to_base_url(string $configuredUrl, string $detectedPath): string
{
    $trimmed = rtrim($configuredUrl, '/');
    if ($trimmed === '' || $detectedPath === '') return $trimmed;
    $parts = parse_url($trimmed);
    if (!is_array($parts)) return $trimmed;
    $configuredPath = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
    if ($configuredPath !== '' && $configuredPath !== '/') return $trimmed;
    return $trimmed . $detectedPath;
}

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = detect_base_path($scriptName);
if ($basePath === '') $basePath = detect_base_path_from_request_uri((string) ($_SERVER['REQUEST_URI'] ?? ''));

if ($configuredBaseUrl !== '') {
    $baseUrl = apply_detected_path_to_base_url(normalize_configured_base_url($configuredBaseUrl), $basePath);
} else {
    $requestScheme = trim((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
    $scheme = $requestScheme !== '' ? $requestScheme : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('/\A(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z/i', $host) !== 1) $host = 'localhost';
    $baseUrl = rtrim("{$scheme}://{$host}{$basePath}", '/');
}

if (!defined('APP_NAME')) define('APP_NAME', 'PinkClub SOKUMIRU');
if (!defined('BASE_URL')) define('BASE_URL', $baseUrl);
if (!defined('LOGIN_PATH')) define('LOGIN_PATH', '/public/login0718.php');
if (!defined('ADMIN_HOME_PATH')) define('ADMIN_HOME_PATH', '/admin/index.php');

$dbConfig = ['host'=>'localhost','port'=>3306,'dbname'=>'','user'=>'','pass'=>'','charset'=>'utf8mb4'];
$localConfigPath = __DIR__ . '/../config.local.php';
if (is_file($localConfigPath)) {
    try {
        $localConfig = require $localConfigPath;
        if (is_array($localConfig) && isset($localConfig['db']) && is_array($localConfig['db'])) {
            $localDbConfig = $localConfig['db'];
            if (!isset($localDbConfig['dbname']) && isset($localDbConfig['name'])) $localDbConfig['dbname'] = $localDbConfig['name'];
            if (!isset($localDbConfig['pass']) && isset($localDbConfig['password'])) $localDbConfig['pass'] = $localDbConfig['password'];
            $dbConfig = array_replace($dbConfig, array_intersect_key($localDbConfig, $dbConfig));
        }
    } catch (Throwable $e) { $GLOBALS['config_local_error'] = $e->getMessage(); }
}

return [
    'db' => $dbConfig,
    'security' => ['session_name' => 'pinkclub_sokumiru_session'],
    'sokumiru' => ['endpoint' => 'https://sokmil-ad.com/api/v1', 'site' => 'SOKUMIRU'],
    'pagination' => ['per_page' => 32],
];
