<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
$user = require_login();
$expirations = config()['expirations'];
$labels = ['1h' => '1 heure', '1d' => '1 jour', '1w' => '1 semaine', '1m' => '1 mois', 'never' => 'Jamais'];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>StashBin — Nouveau secret</title>
<link rel="stylesheet" href="assets/style.css">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body>
<main>
  <header>
    <h1>🔐 StashBin</h1>
    <p class="muted">Connecté en tant que <strong><?= e($user['username']) ?></strong> — <a href="logout.php">déconnexion</a></p>
  </header>

  <form id="create-form">
    <label>Secret à partager
      <textarea id="secret" rows="10" required placeholder="Collez ici le texte à chiffrer…"></textarea>
    </label>

    <div class="options">
      <label>Expiration
        <select id="expire">
          <?php foreach ($expirations as $key => $seconds): ?>
          <option value="<?= e($key) ?>" <?= $key === config()['default_expiration'] ? 'selected' : '' ?>><?= e($labels[$key] ?? $key) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="checkbox">
        <input type="checkbox" id="burn"> Détruire après la première lecture
      </label>
      <label>Mot de passe (optionnel)
        <input type="password" id="password" autocomplete="new-password" placeholder="Protection supplémentaire">
      </label>
    </div>

    <button type="submit" id="submit-btn">Chiffrer et créer le lien</button>
    <p class="error hidden" id="create-error"></p>
  </form>

  <section id="result" class="hidden">
    <h2>Secret créé ✔</h2>
    <p>Le contenu a été chiffré dans votre navigateur : le serveur ne peut pas le lire.</p>
    <label>Lien de partage
      <div class="copy-row">
        <input type="text" id="share-link" readonly>
        <button type="button" class="copy" data-copy="share-link">Copier</button>
      </div>
    </label>
    <label>Lien de suppression (nécessite d'être connecté)
      <div class="copy-row">
        <input type="text" id="delete-link" readonly>
        <button type="button" class="copy" data-copy="delete-link">Copier</button>
      </div>
    </label>
    <button type="button" id="new-paste">Créer un autre secret</button>
  </section>
</main>
<script src="assets/stashbin.js"></script>
</body>
</html>
