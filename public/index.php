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

  <noscript>
    <p class="warn"><?= e(t('create.noscript')) ?></p>
  </noscript>

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
        <!-- A password typed once and never shown locks a secret nobody can
             open any more: the reader is told to enter one, not which one.
             An icon rather than a word, because the button shares the line
             with the field on a screen that has little room for either. Both
             its names live under `js.`, because the script swaps them; the
             page renders the first from that same key rather than keep a
             second copy that would drift. The two glyphs are both here and
             the stylesheet picks one from `aria-pressed`: nothing is drawn
             that the accessible name does not already say. -->
        <div class="field-row">
          <input type="password" id="password" autocomplete="new-password" placeholder="<?= e(t('create.password_placeholder')) ?>">
          <button type="button" class="quiet reveal" data-reveal="password" aria-pressed="false"
                  aria-label="<?= e(t('js.show_password')) ?>" title="<?= e(t('js.show_password')) ?>">
            <svg class="icon-show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="icon-hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
              <line x1="1" y1="1" x2="23" y2="23"></line>
            </svg>
          </button>
        </div>
      </label>
    </div>

    <!-- Revealed by the script when "custom" is picked; hidden rather than
         absent, so that it needs no round trip to appear. Two native fields
         rather than one datetime-local: browsers give a calendar to the first
         and a clock to the second, where the combined control leaves the time
         to be typed. -->
    <div id="expire-at-field" class="options hidden">
      <label><?= e(t('create.expire_date_label')) ?>
        <input type="date" id="expire-date">
      </label>
      <label><?= e(t('create.expire_time_label')) ?>
        <input type="time" id="expire-time">
      </label>
    </div>

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
