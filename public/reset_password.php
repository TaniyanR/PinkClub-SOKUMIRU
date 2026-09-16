<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/_helpers.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
}

$error = '';
$resetCode = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $resetCode = strtolower(trim((string)($_POST['reset_code'] ?? '')));

    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } elseif (preg_match('/\A[a-f0-9]{64}\z/', $resetCode) !== 1 || !db_table_exists('admin_password_resets')) {
        $error = '再設定コードが正しくないか、有効期限が切れています。';
    } else {
        $resetStmt = db()->prepare('SELECT * FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
        $resetStmt->execute([':h' => hash('sha256', $resetCode)]);
        $reset = $resetStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($reset)) {
            $error = '再設定コードが正しくないか、すでに使用されたか、有効期限が切れています。';
        } else {
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            $adminUserId = (int)($reset['admin_user_id'] ?? 0);
            $adminStmt = db()->prepare('SELECT username FROM admins WHERE id=:id LIMIT 1');
            $adminStmt->execute([':id' => $adminUserId]);
            $loginId = (string)($adminStmt->fetchColumn() ?: '');

            if (strlen($password) < 12) {
                $error = '新しいパスワードは12文字以上で入力してください。';
            } elseif ($password !== $passwordConfirm) {
                $error = '確認用パスワードが一致しません。';
            } elseif ($loginId !== '' && strcasecmp($password, $loginId) === 0) {
                $error = 'ログインIDと同じパスワードは使用できません。';
            } elseif (in_array(strtolower($password), ['admin', 'password'], true)) {
                $error = '推測されやすいパスワードは使用できません。';
            } else {
                $pdo = db();
                try {
                    $pdo->beginTransaction();
                    $lockedStmt = $pdo->prepare('SELECT id,admin_user_id FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() LIMIT 1 FOR UPDATE');
                    $lockedStmt->execute([':h' => hash('sha256', $resetCode)]);
                    $lockedReset = $lockedStmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($lockedReset) || (int)$lockedReset['admin_user_id'] !== $adminUserId) {
                        throw new RuntimeException('reset code already used or expired');
                    }

                    $pdo->prepare('UPDATE admins SET password_hash=:h,session_version=session_version+1,updated_at=NOW() WHERE id=:id')
                        ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $adminUserId]);
                    $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:id AND used_at IS NULL')
                        ->execute([':id' => $adminUserId]);
                    $pdo->commit();

                    $_SESSION['forgot_password_success'] = 'パスワードを再設定しました。新しいパスワードでログインしてください。';
                    app_redirect(login_url());
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'この再設定コードはすでに使用されたか、期限が切れました。新しい再設定メールを発行してください。';
                }
            }
        }
    }
}

$pageTitle = 'パスワード再設定';
include __DIR__ . '/partials/login_header.php';
?>
<div class="login-page">
    <div class="login-headline"><span class="login-headline__item">PinkClub SOKUMIRU</span><span class="login-headline__item">パスワード再設定</span></div>
    <?php if ($error !== '') : ?><div class="admin-card login-alert"><p><?php echo e($error); ?></p></div><?php endif; ?>
    <form class="admin-card login-card" method="post" action="<?php echo e(public_url('reset_password.php')); ?>">
        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
        <label for="reset-code">再設定コード</label><input id="reset-code" type="text" name="reset_code" value="<?php echo e($resetCode); ?>" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="one-time-code" spellcheck="false" required>
        <label for="new-password">新しいパスワード</label><input id="new-password" type="password" name="password" minlength="12" autocomplete="new-password" required>
        <label for="password-confirm">新しいパスワード（確認）</label><input id="password-confirm" type="password" name="password_confirm" minlength="12" autocomplete="new-password" required>
        <button type="submit">再設定する</button>
    </form>
    <p class="login-note">メールに記載された64文字の再設定コードを入力してください。保存後、コードは直ちに無効になります。</p>
    <p class="login-note"><a href="<?= e(public_url('forgot_password.php')) ?>">新しい再設定メールを送る</a></p>
</div>
<?php include __DIR__ . '/partials/login_footer.php';
