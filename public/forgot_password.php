<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/rate_limit.php';
require_once __DIR__ . '/partials/_helpers.php';

$message = '';
$messageType = 'success';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!rate_limit_allow('password_reset', 3, 900)) {
        http_response_code(429);
        $message = '短時間に複数回の申請がありました。15分ほど待ってから、もう一度お試しください。';
        $messageType = 'error';
    } elseif (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $message = '画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。';
        $messageType = 'error';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'メールアドレスの形式を確認してください。';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare('SELECT id, username, email FROM admins WHERE email=:email LIMIT 1');
            $stmt->execute([':email' => strtolower($email)]);
            $admin = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

            if (is_array($admin) && db_table_exists('admin_password_resets')) {
                $resetCode = bin2hex(random_bytes(32));
                $tokenStored = false;
                $pdo = db();
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:id AND used_at IS NULL')
                        ->execute([':id' => (int)$admin['id']]);
                    $pdo->prepare('INSERT INTO admin_password_resets(admin_user_id,token_hash,expires_at) VALUES (:admin_user_id,:token_hash,DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                        ->execute([
                            ':admin_user_id' => (int)$admin['id'],
                            ':token_hash' => hash('sha256', $resetCode),
                        ]);
                    $pdo->commit();
                    $tokenStored = true;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[password reset] token creation failed: ' . $e->getMessage());
                }

                if ($tokenStored) {
                    $resetUrl = public_url('reset_password.php');
                    $body = "管理者パスワード再設定の申請を受け付けました。\n\n"
                        . "ユーザー名: " . (string)$admin['username'] . "\n"
                        . "再設定ページ: " . $resetUrl . "\n"
                        . "再設定コード: " . $resetCode . "\n\n"
                        . "再設定ページを開き、このコードを入力してください。\n"
                        . "コードは1時間で期限切れになり、一度使用すると無効になります。";
                    @mail($email, '[' . site_setting_get('site.name', APP_NAME) . '] パスワード再設定', $body);
                }
            }

            // アカウントの存在やメール送信結果を第三者へ知らせない。
            $message = '入力情報を受け付けました。登録情報と一致する場合は再設定案内を送信しました。';
            $messageType = 'success';
        }
    }
}

if (headers_sent() === false) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
}

$faviconPath = trim(site_setting_get('site.favicon_path', ''));
$faviconUrl = $faviconPath !== '' ? public_versioned_url($faviconPath) : '';
$faviconType = strtolower((string)pathinfo($faviconPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/x-icon';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>パスワード再発行 | PinkClub SOKUMIRU</title>
  <?php if ($faviconUrl !== ''): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>" sizes="any" type="<?= e($faviconType) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="login-page">
  <main class="login-wrap">
    <section class="login-card">
      <h1 class="login-title">PinkClub SOKUMIRU</h1>
      <p class="login-subtitle">パスワード再発行</p>

      <?php if ($message !== '') : ?><p class="<?= $messageType === 'error' ? 'alert alert-error' : 'alert alert-success' ?>"><?php echo e($message); ?></p><?php endif; ?>
      <form method="post" class="login-form">
        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
        <label class="login-label">
          登録メールアドレス
          <input class="login-input" name="email" type="email" autocomplete="email" required>
        </label>
        <button class="login-button" type="submit">再設定メールを送る</button>
      </form>

      <p class="login-note">メールには再設定ページのURLと、URLには含めない一度限りの再設定コードを記載します。</p>
      <p class="login-note"><a href="login0718.php">ログインへ戻る</a></p>
    </section>
  </main>
</body>
</html>
