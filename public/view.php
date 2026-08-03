<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
vary_language();
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('view.page_title')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body data-i18n="<?= e(client_strings()) ?>">
<main>
  <header><h1>🔐 StashBin</h1></header>

  <p id="status"><?= e(t('view.loading')) ?></p>

  <section id="burn-confirm" class="hidden">
    <p class="warn"><?= t_html('view.burn_warning', [
        '{emphasis}' => '<strong>' . e(t('view.burn_emphasis')) . '</strong>',
    ]) ?></p>
    <button type="button" id="reveal-btn"><?= e(t('view.reveal')) ?></button>
  </section>

  <section id="password-prompt" class="hidden">
    <p><?= e(t('view.password_required')) ?></p>
    <form id="password-form">
      <label><?= e(t('view.password_label')) ?>
        <input type="password" id="paste-password" required autofocus>
      </label>
      <button type="submit"><?= e(t('view.decrypt')) ?></button>
      <p class="error hidden" id="decrypt-error"></p>
    </form>
  </section>

  <section id="content" class="hidden">
    <p class="warn hidden" id="burned-notice"><?= e(t('view.burned_notice')) ?></p>
    <div class="copy-row">
      <button type="button" class="copy" data-copy="secret-text"><?= e(t('view.copy_content')) ?></button>
    </div>
    <pre id="secret-text"></pre>
  </section>
</main>
<script src="assets/stashbin.js"></script>
</body>
</html>
