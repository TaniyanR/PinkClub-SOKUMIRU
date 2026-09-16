<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';

function init_db(): array
{
    $result = installer_run();
    if (($result['success'] ?? false) !== true) {
        throw new RuntimeException((string)($result['error'] ?? 'セットアップに失敗しました。'));
    }
    return $result;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = init_db();
        fwrite(STDOUT, sprintf("DB初期化が完了しました。（完了ステップ: %d）\n", count($result['steps'])));
        if (is_string($result['initial_password'] ?? null)) {
            fwrite(STDOUT, "初期管理者: admin\n初期パスワード: " . $result['initial_password'] . "\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "DB初期化に失敗しました: " . $e->getMessage() . "\n");
        exit(1);
    }
}
