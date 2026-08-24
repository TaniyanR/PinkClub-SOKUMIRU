<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = '個人設定';
$message = null;
$error = null;
$adminId = (int)(auth_user()['id'] ?? 0);
$stmt = db()->prepare('SELECT username, email, password_hash, initial_setup_completed FROM admins WHERE id=:id LIMIT 1');
$stmt->execute([':id' => $adminId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($account)) { auth_logout(); app_redirect(LOGIN_PATH); }
$initial = !(bool)$account['initial_setup_completed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $username = trim((string)post('username', ''));
    $email = strtolower(trim((string)post('email', '')));
    $currentPassword = (string)post('current_password', '');
    $password = (string)post('password', '');
    $confirm = (string)post('password_confirm', '');

    if (!password_verify($currentPassword, (string)$account['password_hash'])) {
        $error = '現在のパスワードが正しくありません。';
    } elseif ($username === '' || ($initial && strcasecmp($username, 'admin') === 0)) {
        $error = 'ログインIDを admin 以外に変更してください。';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '有効な再設定用メールアドレスを入力してください。';
    } elseif (($initial || $password !== '') && strlen($password) < 12) {
        $error = '新しいパスワードは12文字以上で入力してください。';
    } elseif ($password !== $confirm) {
        $error = '新しいパスワードの確認が一致しません。';
    } elseif ($password !== '' && (strcasecmp($password, $username) === 0 || in_array(strtolower($password), ['admin', 'password'], true))) {
        $error = 'ログインID、admin、password と同じパスワードは使用できません。';
    } else {
        $duplicate = db()->prepare('SELECT 1 FROM admins WHERE username=:username AND id<>:id LIMIT 1');
        $duplicate->execute([':username' => $username, ':id' => $adminId]);
        if ($duplicate->fetchColumn() !== false) {
            $error = 'このログインIDは使用されています。';
        } else {
            $sql = 'UPDATE admins SET username=:username,email=:email,initial_setup_completed=1,session_version=session_version+1';
            $params = [':username' => $username, ':email' => $email, ':id' => $adminId];
            if ($password !== '') { $sql .= ',password_hash=:hash'; $params[':hash'] = password_hash($password, PASSWORD_DEFAULT); }
            $sql .= ',updated_at=NOW() WHERE id=:id';
            db()->prepare($sql)->execute($params);
            site_setting_set('site.admin_email', $email);
            session_regenerate_id(true);
            $_SESSION['admin']['username'] = $username;
            $_SESSION['admin']['initial_setup_completed'] = true;
            $_SESSION['admin']['session_version'] = (int)($_SESSION['admin']['session_version'] ?? 1) + 1;
            $account['username'] = $username; $account['email'] = $email; $account['initial_setup_completed'] = 1;
            $initial = false;
            $message = '個人設定を保存しました。';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>個人設定</h1>
  <?php if ($initial): ?><p class="flash error" role="alert">安全のため、初回ログイン設定を完了してください。</p><?php endif; ?>
  <?php if ($message): ?><p class="flash success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="flash error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>ログインID<input name="username" value="<?= e((string)$account['username']) ?>" required autocomplete="username"></label>
    <label>パスワード再設定用メールアドレス<input type="email" name="email" value="<?= e((string)($account['email'] ?? '')) ?>" required autocomplete="email"></label>
    <label>現在のパスワード<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>新しいパスワード<input type="password" name="password" minlength="12" <?= $initial ? 'required' : '' ?> autocomplete="new-password"></label>
    <label>新しいパスワード（確認）<input type="password" name="password_confirm" minlength="12" <?= $initial ? 'required' : '' ?> autocomplete="new-password"></label>
    <div class="admin-actions"><button type="submit">保存</button></div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
