<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>StashBin — Secret partagé</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
  <header><h1>🔐 StashBin</h1></header>

  <p id="status">Chargement…</p>

  <section id="burn-confirm" class="hidden">
    <p class="warn">⚠️ Ce secret sera <strong>détruit dès sa lecture</strong>. Ne continuez que si vous êtes prêt à le lire maintenant.</p>
    <button type="button" id="reveal-btn">Afficher le secret</button>
  </section>

  <section id="password-prompt" class="hidden">
    <p>Ce secret est protégé par un mot de passe.</p>
    <form id="password-form">
      <label>Mot de passe
        <input type="password" id="paste-password" required autofocus>
      </label>
      <button type="submit">Déchiffrer</button>
      <p class="error hidden" id="decrypt-error"></p>
    </form>
  </section>

  <section id="content" class="hidden">
    <p class="warn hidden" id="burned-notice">⚠️ Ce secret vient d'être détruit : cette page est la seule copie. Il ne sera plus accessible.</p>
    <div class="copy-row">
      <button type="button" class="copy" data-copy="secret-text">Copier le contenu</button>
    </div>
    <pre id="secret-text"></pre>
  </section>
</main>
<script src="assets/stashbin.js"></script>
</body>
</html>
