<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
vary_language();

// Without authentication no secret has an owner, so there is no list to draw.
if (!auth_enabled()) {
    header('Location: index.php' . lang_param());
    exit;
}

$user = require_login();

// Deleting from the list is the owner's right, whereas the deletion link is the
// token holder's: neither grants the other. Ownership is checked inside the
// statement rather than before it, so an identifier belonging to somebody else
// simply matches nothing.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) {
        header('Location: secrets.php' . lang_param());
        exit;
    }
    $id = (string) ($_POST['id'] ?? '');
    $done = null;
    if (($_POST['action'] ?? '') === 'delete') {
        $stmt = db()->prepare(
            "UPDATE pastes SET payload = '', gone = ?, gone_cause = 'deleted'
             WHERE id = ? AND owner_id = ? AND gone IS NULL"
        );
        $stmt->execute([time(), $id, $user['id']]);
        $done = $stmt->rowCount() > 0 ? 'deleted' : null;
    } elseif (($_POST['action'] ?? '') === 'forget') {
        // Only an entry already at rest: forgetting a live secret would leave
        // its link working with nobody left to see what became of it.
        $stmt = db()->prepare('DELETE FROM pastes WHERE id = ? AND owner_id = ? AND gone IS NOT NULL');
        $stmt->execute([$id, $user['id']]);
        $done = $stmt->rowCount() > 0 ? 'forgotten' : null;
    }
    // Redirect after the write, so that reloading the page does not replay it.
    $query = array_filter(['lang' => isset($_GET['lang']) ? locale() : null, 'done' => $done]);
    header('Location: secrets.php' . ($query === [] ? '' : '?' . http_build_query($query)));
    exit;
}

$notices = ['deleted' => 'secrets.deleted_notice', 'forgotten' => 'secrets.forgotten_notice'];
$notice = $notices[$_GET['done'] ?? ''] ?? null;

// Expiry is settled before the list is drawn, otherwise a secret whose time has
// passed would still be shown as live until somebody created another one.
purge_expired();

// Only served reads count towards "read n times": a replayed link that found
// nothing is an event of its own, and it belongs in the log, not in the tally.
$stmt = db()->prepare(
    'SELECT p.*, COUNT(r.id) AS reads
       FROM pastes p LEFT JOIN paste_reads r ON r.paste_id = p.id AND r.outcome = ?
      WHERE p.owner_id = ?
      GROUP BY p.id
      ORDER BY p.created DESC, p.rowid DESC'
);
$stmt->execute(['served', $user['id']]);
$secrets = $stmt->fetchAll();

$logs = db()->prepare('SELECT at, outcome, ip, agent FROM paste_reads WHERE paste_id = ? ORDER BY at DESC');

$csrf = csrf_token();
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('secrets.page_title')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
  <header>
    <h1>🔐 <?= e(t('secrets.heading')) ?></h1>
    <p class="muted"><a href="index.php<?= e(lang_param()) ?>"><?= e(t('secrets.back')) ?></a></p>
    <p class="muted"><?= e(t('secrets.intro')) ?></p>
  </header>

  <?php if ($notice !== null): ?>
  <p class="warn"><?= e(t($notice)) ?></p>
  <?php endif; ?>

  <?php if ($secrets === []): ?>
  <p><?= e(t('secrets.empty')) ?></p>
  <?php endif; ?>

  <?php foreach ($secrets as $secret): ?>
  <?php
    $reads = (int) $secret['reads'];
    if ($secret['gone'] !== null) {
        $state = t('secrets.state_' . ($secret['gone_cause'] ?? 'deleted'));
    } elseif ($reads === 0) {
        $state = t('secrets.state_unread');
    } elseif ($reads === 1) {
        $state = t('secrets.state_read_one');
    } else {
        $state = t('secrets.state_read_many', ['count' => $reads]);
    }
    $logs->execute([$secret['id']]);
    $accesses = $logs->fetchAll();
  ?>
  <article class="secret<?= $secret['gone'] !== null ? ' gone' : '' ?>">
    <h2><?= e($secret['title'] ?? t('secrets.untitled')) ?></h2>

    <p class="badges">
      <span class="badge state"><?= e($state) ?></span>
      <?php if ((int) $secret['burn'] === 1): ?>
      <span class="badge"><?= e(t('secrets.burn_badge')) ?></span>
      <?php endif; ?>
    </p>

    <ul class="facts muted">
      <li><?= e(t('secrets.created', ['date' => utc_stamp((int) $secret['created'])])) ?></li>
      <li>
        <?= $secret['expires'] === null
            ? e(t('secrets.expires_never'))
            : e(t('secrets.expires', ['date' => utc_stamp((int) $secret['expires'])])) ?>
      </li>
      <?php if ($secret['gone'] !== null): ?>
      <li><?= e(t('secrets.gone_on', ['date' => utc_stamp((int) $secret['gone'])])) ?></li>
      <?php endif; ?>
      <li><?= e(t('secrets.identifier')) ?> <code><?= e($secret['id']) ?></code></li>
    </ul>

    <details>
      <summary><?= e(t('secrets.access_log', ['count' => count($accesses)])) ?></summary>
      <?php if ($accesses === []): ?>
      <p class="muted"><?= e(t('secrets.access_none')) ?></p>
      <?php else: ?>
      <div class="scroller">
        <table>
          <thead>
            <tr>
              <th><?= e(t('secrets.access_when')) ?></th>
              <th><?= e(t('secrets.access_outcome')) ?></th>
              <th><?= e(t('secrets.access_ip')) ?></th>
              <th><?= e(t('secrets.access_agent')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accesses as $access): ?>
            <tr>
              <td class="stamp"><?= e(utc_stamp((int) $access['at'])) ?></td>
              <td><?= e(t('secrets.outcome_' . $access['outcome'])) ?></td>
              <td class="stamp"><?= e($access['ip'] ?? t('secrets.unknown')) ?></td>
              <td class="agent"><?= e($access['agent'] ?? t('secrets.unknown')) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </details>

    <form method="post" action="secrets.php<?= e(lang_param()) ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="id" value="<?= e($secret['id']) ?>">
      <?php if ($secret['gone'] === null): ?>
      <button type="submit" name="action" value="delete"><?= e(t('secrets.delete')) ?></button>
      <?php else: ?>
      <button type="submit" name="action" value="forget" class="quiet"><?= e(t('secrets.forget')) ?></button>
      <?php endif; ?>
    </form>
  </article>
  <?php endforeach; ?>
</main>
</body>
</html>
