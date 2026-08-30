<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/_helpers.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
}

$token = trim((string)($_GET['token'] ?? ''));
$reset = false;
if (preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1 && db_table_exists('admin_password_resets')) {
    $stmt = db()->prepare('SELECT * FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([':h' => hash('sha256', $token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!is_array($reset)) {
    http_response_code(403);
    $pageTitle = '403 Forbidden';
    include __DIR__ . '/partials/login_header.php';
    echo '<div class="login-page"><section class="admin-card login-card"><h1>403 Forbidden</h1><p>リセットトークンが無効または期限切れです。</p><p><a href="' . e(public_url('forgot_password.php')) . '">再発行へ戻る</a></p></section></div>';
    include __DIR__ . '/partials/login_footer.php';
    exit;
}
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $error = '不正なリクエストです。';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        if (strlen($password) < 12) {
            $error = '新しいパスワードは12文字以上で入力してください。';
        } elseif ($password !== $passwordConfirm) {
            $error = '確認用パスワードが一致しません。';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $locked = $pdo->prepare('SELECT r.id,r.admin_user_id,a.username FROM admin_password_resets r JOIN admins a ON a.id=r.admin_user_id WHERE r.id=:id AND r.used_at IS NULL AND r.expires_at>=NOW() FOR UPDATE');
                $locked->execute([':id' => (int)$reset['id']]);
                $row = $locked->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) { throw new RuntimeException('invalid reset token'); }
                if (strcasecmp($password, (string)$row['username']) === 0 || in_array(strtolower($password), ['admin', 'password'], true)) {
                    $pdo->rollBack();
                    $error = 'ログインID、admin、password と同じパスワードは使用できません。';
                } else {
                    $pdo->prepare('UPDATE admins SET password_hash=:h,session_version=session_version+1,updated_at=NOW() WHERE id=:id')
                        ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int)$row['admin_user_id']]);
                    $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:id AND used_at IS NULL')
                        ->execute([':id' => (int)$row['admin_user_id']]);
                    $pdo->commit();
                    $_SESSION['forgot_password_success'] = 'パスワードを再設定しました。新しいパスワードでログインしてください。';
                    app_redirect(login_url());
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($error === '') { $error = 'この再設定URLは無効または使用済みです。再発行してください。'; }
            }
        }
    }
}

$pageTitle = 'パスワード再設定';
include __DIR__ . '/partials/login_header.php';
?>
<div class="login-page">
    <div class="login-headline"><span class="login-headline__item">PinkClub-SOKUMIRU</span><span class="login-headline__item">パスワード再設定</span></div>
    <?php if ($error !== '') : ?><div class="admin-card login-alert"><p><?php echo e($error); ?></p></div><?php endif; ?>
    <form class="admin-card login-card" method="post" action="<?php echo e(public_url('reset_password.php') . '?token=' . rawurlencode($token)); ?>">
        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
        <label for="new-password">新しいパスワード</label><input id="new-password" type="password" name="password" minlength="12" autocomplete="new-password" required>
        <label for="password-confirm">新しいパスワード（確認）</label><input id="password-confirm" type="password" name="password_confirm" minlength="12" autocomplete="new-password" required>
        <button type="submit">再設定する</button>
    </form>
</div>
<?php include __DIR__ . '/partials/login_footer.php';
