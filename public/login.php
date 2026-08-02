<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
start_session();

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) {
        $error = 'Session expirée, réessayez.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php');
            exit;
        }
        // Freine les tentatives de force brute.
        sleep(1);
        $error = 'Identifiants incorrects.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>StashBin — Connexion</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="narrow">
  <h1>🔐 StashBin</h1>
  <p class="muted">Connectez-vous pour créer un secret.</p>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Utilisateur
      <input type="text" name="username" required autofocus autocomplete="username">
    </label>
    <label>Mot de passe
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit">Se connecter</button>
  </form>
</main>
</body>
</html>
