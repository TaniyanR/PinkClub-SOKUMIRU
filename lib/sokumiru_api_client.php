<?php

declare(strict_types=1);

final class SokumiruApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $affiliateId,
        private readonly string $endpoint
    ) {
    }

    public function fetchItems(string $site = '', string $service = '', string $floor = '', array $params = []): array
    {
        unset($site, $service, $floor);
        $params['category'] = 'av';
        $params['sort'] = $this->normalizeItemSort((string)($params['sort'] ?? 'date'));
        return $this->request('Item', $params);
    }

    public function searchActresses(array $params = []): array
    {
        $params['category'] = 'av';
        $params['gender'] = 'f';
        unset($params['actress_id'], $params['floor_id'], $params['sort']);
        return $this->request('Actor', $params);
    }

    private function request(string $operation, array $params): array
    {
        if (trim($this->apiKey) === '' || trim($this->affiliateId) === '') {
            throw new RuntimeException('API KEY / アフィリエイトIDを設定してください。');
        }

        $query = array_filter(array_merge($params, [
            'api_key' => $this->apiKey,
            'affiliate_id' => $this->affiliateId,
            'output' => 'json',
        ]), static fn(mixed $value): bool => $value !== null && $value !== '');
        $query['hits'] = min(100, max(1, (int)($query['hits'] ?? 20)));
        $query['offset'] = min(50000, max(1, (int)($query['offset'] ?? 1)));

        $url = rtrim($this->endpoint, '/') . '/' . $operation . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $safeQuery = $query;
        $safeQuery['api_key'] = '***';
        $safeQuery['affiliate_id'] = '***';
        $safeUrl = rtrim($this->endpoint, '/') . '/' . $operation . '?' . http_build_query($safeQuery, '', '&', PHP_QUERY_RFC3986);
        $requestHash = hash('sha256', $url);

        $cached = $this->fetchCachedResponse($requestHash);
        if ($cached !== null) {
            $this->insertApiLog($operation, $safeUrl, $requestHash, 200, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', true);
            return $cached;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURLを初期化できませんでした。');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PinkClub-SOKUMIRU/1.0',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->insertApiLog($operation, $safeUrl, $requestHash, 0, json_encode(['error' => $error], JSON_UNESCAPED_UNICODE) ?: '{}', false);
            throw new RuntimeException('SOKUMIRU API通信エラー: ' . $error);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $safeResponse = $this->redactResponseBody($response);
        $this->insertApiLog($operation, $safeUrl, $requestHash, $httpCode, $safeResponse, false);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('SOKUMIRU API HTTPエラー: ' . $httpCode);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SOKUMIRU APIのJSONを解析できませんでした。');
        }
        $status = (int)($decoded['result']['status'] ?? 200);
        if ($status !== 200) {
            throw new RuntimeException('SOKUMIRU APIエラー: ' . $status);
        }
        return $decoded;
    }

    private function normalizeItemSort(string $sort): string
    {
        return match ($sort) {
            'price', '-price', 'date' => $sort,
            default => 'date',
        };
    }

    private function redactResponseBody(string $response): string
    {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return str_replace($this->apiKey, '***', $response);
        }
        if (isset($decoded['request']['parameters']) && is_array($decoded['request']['parameters'])) {
            foreach (['api_key', 'affiliate_id'] as $key) {
                if (array_key_exists($key, $decoded['request']['parameters'])) {
                    $decoded['request']['parameters'][$key] = '***';
                }
            }
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function fetchCachedResponse(string $requestHash): ?array
    {
        if (!function_exists('db')) {
            return null;
        }
        try {
            $stmt = db()->prepare('SELECT response_body FROM api_logs WHERE request_hash = :hash AND response_status = 200 AND cache_hit = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1');
            $stmt->execute([':hash' => $requestHash]);
            $body = $stmt->fetchColumn();
            $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function insertApiLog(string $apiName, string $requestUrl, string $requestHash, int $status, string $responseBody, bool $cacheHit): void
    {
        if (!function_exists('db')) {
            return;
        }
        try {
            $stmt = db()->prepare('INSERT INTO api_logs (api_name, request_url, request_hash, response_status, response_body, cache_hit, created_at) VALUES (:name,:url,:hash,:status,:body,:cache,NOW())');
            $stmt->execute([
                ':name' => $apiName,
                ':url' => $requestUrl,
                ':hash' => $requestHash,
                ':status' => $status,
                ':body' => mb_substr($responseBody, 0, 65535),
                ':cache' => $cacheHit ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('SOKUMIRU API log insert failed: ' . $e->getMessage());
        }
    }
}
