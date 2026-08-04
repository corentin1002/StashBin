<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
vary_language();
$user = require_login();

// Before any output at all: csrf_token() opens the session, and session_start()
// can no longer set its cookie once the HTML header has been written. Without
// authentication, nothing else opens the session beforehand.
$csrf = csrf_token();

$expirations = config()['expirations'];
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('create.page_title')) ?></title>
<link rel="stylesheet" href="assets/style.css">
<meta name="csrf-token" content="<?= e($csrf) ?>">
</head>
<body data-i18n="<?= e(client_strings()) ?>">
<main>
  <header>
    <h1>🔐 StashBin</h1>
    <?php if ($user !== null): ?>
    <p class="muted"><?= t_html('create.logged_in', [
        '{user}' => '<strong>' . e($user['username']) . '</strong>',
    ]) ?></p>
    <!-- Links, not buttons: these navigate, and a middle click or a new tab
         must keep working. Only their appearance is that of a button. -->
    <nav class="actions">
      <a class="btn" href="secrets.php<?= e(lang_param()) ?>"><?= e(t('create.my_secrets')) ?></a>
      <a class="btn" href="logout.php<?= e(lang_param()) ?>"><?= e(t('create.logout')) ?></a>
    </nav>
    <?php else: ?>
    <p class="muted"><?= e(t('create.no_auth_notice')) ?></p>
    <?php endif; ?>
  </header>

  <form id="create-form">
    <label><?= e(t('create.secret_label')) ?>
      <textarea id="secret" rows="10" required placeholder="<?= e(t('create.secret_placeholder')) ?>"></textarea>
    </label>

    <div class="options">
      <label><?= e(t('create.expire_label')) ?>
        <select id="expire">
          <?php foreach ($expirations as $key => $seconds): ?>
          <option value="<?= e($key) ?>" <?= $key === config()['default_expiration'] ? 'selected' : '' ?>><?= e(t('expire.' . $key)) ?></option>
          <?php endforeach; ?>
          <option value="custom"><?= e(t('expire.custom')) ?></option>
        </select>
      </label>
      <label class="checkbox">
        <input type="checkbox" id="burn"> <?= e(t('create.burn_label')) ?>
      </label>
      <label><?= e(t('create.password_label')) ?>
        <input type="password" id="password" autocomplete="new-password" placeholder="<?= e(t('create.password_placeholder')) ?>">
      </label>
    </div>

    <!-- Revealed by the script when "custom" is picked. Hidden rather than
         absent, so that the field needs no round trip to appear. -->
    <label id="expire-at-field" class="hidden"><?= e(t('create.expire_at_label')) ?>
      <input type="datetime-local" id="expire-at">
    </label>

    <button type="submit" id="submit-btn"><?= e(t('create.submit')) ?></button>
    <p class="error hidden" id="create-error"></p>
  </form>

  <section id="result" class="hidden">
    <h2><?= e(t('create.done_title')) ?></h2>
    <p><?= e(t('create.done_note')) ?></p>
    <label><?= e(t('create.share_link')) ?>
      <div class="copy-row">
        <input type="text" id="share-link" readonly>
        <button type="button" class="copy" data-copy="share-link"><?= e(t('create.copy')) ?></button>
      </div>
    </label>
    <label><?= e(t(auth_enabled() ? 'create.delete_link_auth' : 'create.delete_link')) ?>
      <div class="copy-row">
        <input type="text" id="delete-link" readonly>
        <button type="button" class="copy" data-copy="delete-link"><?= e(t('create.copy')) ?></button>
      </div>
    </label>
    <button type="button" id="new-paste"><?= e(t('create.another')) ?></button>
  </section>
</main>
<script src="assets/stashbin.js"></script>
</body>
</html>
