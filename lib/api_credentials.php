<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_settings.php';

function api_credential_types(): array
{
    return [
        'items' => '商品情報',
        'actresses' => '女優',
    ];
}

function api_credential_normalize_type(string $apiType): string
{
    $normalized = strtolower(trim($apiType));
    if (!array_key_exists($normalized, api_credential_types())) {
        throw new InvalidArgumentException('unsupported api_type: ' . $apiType);
    }
    return $normalized;
}

function api_credential_get(string $apiType): array
{
    $apiType = api_credential_normalize_type($apiType);
    if ($apiType !== 'items') {
        $apiType = 'items';
    }

    try {
        $stmt = db()->prepare('SELECT api_id, affiliate_id FROM api_credentials WHERE api_type = :api_type LIMIT 1');
        $stmt->execute([':api_type' => $apiType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $storedAffiliateId = trim((string)($row['affiliate_id'] ?? ''));
            return [
                'api_id' => trim((string)($row['api_id'] ?? '')),
                'affiliate_id' => $storedAffiliateId !== ''
                    ? $storedAffiliateId
                    : trim(site_setting_get('sokumiru_affiliate_id', '')),
            ];
        }
    } catch (Throwable) {
        // テーブル未作成時は既存設定にフォールバック
    }

    $fallbackApiId = trim(site_setting_get('sokumiru_api_key', ''));
    $fallbackAffiliateId = trim(site_setting_get('sokumiru_affiliate_id', ''));
    return ['api_id' => $fallbackApiId, 'affiliate_id' => $fallbackAffiliateId];
}

function api_credential_set(string $apiType, string $apiId, string $affiliateId = ''): void
{
    $apiType = api_credential_normalize_type($apiType);
    if ($apiType !== 'items') {
        $apiType = 'items';
    }

    $apiId = trim($apiId);
    $affiliateId = trim($affiliateId);
    if ($apiId === '' || $affiliateId === '') {
        throw new InvalidArgumentException('API KEYとアフィリエイトIDは必須です。');
    }
    try {
        db()->prepare('INSERT INTO api_credentials (api_type, api_id, affiliate_id, created_at, updated_at) VALUES (:api_type, :api_id, :affiliate_id, NOW(), NOW()) ON DUPLICATE KEY UPDATE api_id = VALUES(api_id), affiliate_id = VALUES(affiliate_id), updated_at = NOW()')
            ->execute([
                ':api_type' => $apiType,
                ':api_id' => $apiId,
                ':affiliate_id' => $affiliateId,
            ]);
    } catch (Throwable) {
        // 020 migration適用前の既存環境でもAPI IDの保存を止めない。
        // アフィリエイトIDは下のsettingsへ保存し、migration適用後に列へも保存される。
        db()->prepare('INSERT INTO api_credentials (api_type, api_id, created_at, updated_at) VALUES (:api_type, :api_id, NOW(), NOW()) ON DUPLICATE KEY UPDATE api_id = VALUES(api_id), updated_at = NOW()')
            ->execute([
                ':api_type' => $apiType,
                ':api_id' => $apiId,
            ]);
    }
    db()->prepare("DELETE FROM api_credentials WHERE api_type IN ('genres','series')")->execute();

    site_setting_set_many([
        'sokumiru_api_key' => $apiId,
        'sokumiru_affiliate_id' => $affiliateId,
    ]);
}
