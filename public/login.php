<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
vary_language();

// Sans authentification, il n'y a rien à quoi se connecter : pas de formulaire,
// et pas même de session ouverte au passage.
if (!auth_enabled()) {
    header('Location: index.php' . lang_param());
    exit;
}

start_session();

if (current_user() !== null) {
    header('Location: index.php' . lang_param());
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) {
        $error = t('login.expired');
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php' . lang_param());
            exit;
        }
        // Freine les tentatives de force brute.
        sleep(1);
        $error = t('login.bad_credentials');
    }
}
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('login.page_title')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="narrow">
  <h1>🔐 StashBin</h1>
  <p class="muted"><?= e(t('login.intro')) ?></p>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="login.php<?= e(lang_param()) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label><?= e(t('login.username')) ?>
      <input type="text" name="username" required autofocus autocomplete="username">
    </label>
    <label><?= e(t('login.password')) ?>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit"><?= e(t('login.submit')) ?></button>
  </form>
</main>
</body>
</html>
