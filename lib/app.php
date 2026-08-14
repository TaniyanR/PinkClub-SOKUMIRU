<?php

declare(strict_types=1);

require_once __DIR__ . '/sokumiru_api_client.php';
require_once __DIR__ . '/sokumiru_sync_service.php';
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/api_credentials.php';
require_once __DIR__ . '/config.php';


function settings_normalize_token(string $value, string $fallback): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return $fallback;
    }

    if (preg_match_all('/[A-Za-z][A-Za-z0-9_.-]*/', $trimmed, $matches) === 1 && !empty($matches[0])) {
        return (string)$matches[0][count($matches[0]) - 1];
    }

    return $fallback;
}

function settings_normalize_site(string $value): string
{
    return 'SOKUMIRU';
}


function settings_get(): array
{
    $defaults = app_config()['sokumiru'] ?? [];

    $envApiId = trim((string)(getenv('SOKUMIRU_API_KEY') ?: ''));
    $itemCred = api_credential_get('items');
    $dbApiId = trim((string)($itemCred['api_id'] ?? ''));

    return [
        'api_id' => $dbApiId !== '' ? $dbApiId : ($envApiId !== '' ? $envApiId : ''),
        'site' => 'SOKUMIRU',
        'service' => 'sokumiru',
        'floor' => 'av',
        'master_floor_id' => '',
        'item_sync_batch' => settings_allowed_item_sync_batch(settings_int('item_sync_batch', 100)),
        'item_sync_enabled' => settings_bool('item_sync_enabled', false),
        'item_sync_interval_minutes' => settings_int('item_sync_interval_minutes', 60),
        'last_item_sync_at' => site_setting_get('last_item_sync_at', ''),
        'item_sync_offset' => settings_int('item_sync_offset', 1),
        'item_sync_test_offset' => settings_int('item_sync_test_offset', 1),
    ];
}

function settings_int(string $key, int $default): int
{
    $value = site_setting_get($key, (string)$default);
    if (!preg_match('/^-?\d+$/', $value)) {
        return $default;
    }
    return (int)$value;
}

function settings_allowed_item_sync_batch(int $value): int
{
    $allowed = [1, 10, 20, 30, 50, 100, 200, 300, 500];
    if (!in_array($value, $allowed, true)) {
        return 100;
    }
    return $value;
}

function settings_bool(string $key, bool $default): bool
{
    return settings_int($key, $default ? 1 : 0) === 1;
}

function settings_save(string $apiId, int $itemSyncBatch = 100, ?int $masterFloorId = null): void
{
    $allowed = [1, 10, 20, 30, 50, 100, 200, 300, 500];
    if (!in_array($itemSyncBatch, $allowed, true)) {
        $itemSyncBatch = 100;
    }

    $payload = [
        'sokumiru_api_key' => trim($apiId),
        'item_sync_batch' => (string)$itemSyncBatch,
    ];
    if ($masterFloorId !== null) {
        $payload['master_floor_id'] = (string)max(1, $masterFloorId);
    }

    site_setting_set_many($payload);
}

function sokumiru_client_for_type(string $apiType): SokumiruApiClient
{
    $cred = api_credential_get($apiType);
    $endpoint = app_config()['sokumiru']['endpoint'];
    $referer = trim((string)(getenv('SOKUMIRU_REFERER') ?: site_setting_get('site.url', defined('BASE_URL') ? BASE_URL : '')));
    return new SokumiruApiClient(
        (string)($cred['api_id'] ?? ''),
        $endpoint,
        $referer
    );
}

function sokumiru_client_from_settings(): SokumiruApiClient
{
    return sokumiru_client_for_type('items');
}

function sokumiru_sync_service(?string $apiType = null): SokumiruSyncService
{
    return new SokumiruSyncService($apiType === null ? sokumiru_client_from_settings() : sokumiru_client_for_type($apiType), db());
}
