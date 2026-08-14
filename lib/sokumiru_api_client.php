<?php

declare(strict_types=1);

final class SokumiruApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $affiliateId,
        private readonly string $endpoint,
        private readonly string $referer = ''
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
            return $cached;
        }

        try {
            [$response, $httpCode] = $this->sendRequest($url);
        } catch (RuntimeException $e) {
            $this->insertApiLog($operation, $safeUrl, $requestHash, 0, json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) ?: '{}', false);
            throw $e;
        }
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

    /** @return array{0:string,1:int} */
    private function sendRequest(string $url): array
    {
        $currentUrl = $url;
        $maxRedirects = 3;

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $this->waitForRequestSlot();
            $location = '';
            $ch = curl_init($currentUrl);
            if ($ch === false) {
                throw new RuntimeException('cURLを初期化できませんでした。');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FAILONERROR => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'PinkClub-SOKUMIRU/1.0',
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, strlen('Location:')));
                    }
                    return strlen($header);
                },
            ];
            $referer = $this->normalizedReferer();
            if ($referer !== '') {
                $options[CURLOPT_REFERER] = $referer;
            }
            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('SOKUMIRU API通信エラー: ' . $error);
            }
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 300 || $httpCode >= 400 || $location === '') {
                return [(string)$response, $httpCode];
            }
            if ($redirects >= $maxRedirects) {
                throw new RuntimeException('SOKUMIRU APIの転送回数が上限を超えました。');
            }

            $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
            $this->assertTrustedApiUrl($nextUrl);
            $currentUrl = $nextUrl;
        }

        throw new RuntimeException('SOKUMIRU APIの転送処理に失敗しました。');
    }

    private function normalizedReferer(): string
    {
        $referer = trim($this->referer);
        $parts = $referer !== '' ? parse_url($referer) : false;
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return rtrim($referer, '/') . '/';
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new RuntimeException('SOKUMIRU APIの転送先が空です。');
        }
        if (preg_match('#^https://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($currentUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new RuntimeException('SOKUMIRU APIの転送元URLを解析できませんでした。');
        }
        $origin = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $origin .= ':' . (int)$base['port'];
        }
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = (string)($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $origin . ($directory === '' ? '' : $directory) . '/' . $location;
    }

    private function assertTrustedApiUrl(string $url): void
    {
        $target = parse_url($url);
        $endpoint = parse_url($this->endpoint);
        $targetHost = strtolower((string)($target['host'] ?? ''));
        $endpointHost = strtolower((string)($endpoint['host'] ?? ''));
        $scheme = strtolower((string)($target['scheme'] ?? ''));
        $trustedHost = $targetHost !== '' && $endpointHost !== ''
            && ($targetHost === $endpointHost || str_ends_with($targetHost, '.' . $endpointHost));
        if ($scheme !== 'https' || !$trustedHost || isset($target['user']) || isset($target['pass'])) {
            throw new RuntimeException('SOKUMIRU APIの安全でない転送を拒否しました。');
        }
    }

    private function waitForRequestSlot(): void
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pinkclub_sokumiru_api_request.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            usleep(1000000);
            return;
        }
        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            usleep(1000000);
            return;
        }

        $raw = stream_get_contents($handle);
        $lastRequestAt = is_string($raw) && is_numeric(trim($raw)) ? (float)trim($raw) : 0.0;
        $waitSeconds = 1.0 - (microtime(true) - $lastRequestAt);
        if ($waitSeconds > 0) {
            usleep((int)ceil($waitSeconds * 1000000));
        }
        $now = microtime(true);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf('%.6F', $now));
        fflush($handle);
        @flock($handle, LOCK_UN);
        @fclose($handle);
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
                ':body' => mb_substr($responseBody, 0, ($status >= 200 && $status < 400) ? 65535 : 4096),
                ':cache' => $cacheHit ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('SOKUMIRU API log insert failed: ' . $e->getMessage());
        }
    }
}
