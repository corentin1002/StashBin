// Browser tests: real cryptography and complete interface journeys.
//
// Two levels in a single file:
//   1. the functions of public/assets/stashbin.js called directly in the page,
//      without touching the DOM — this is the heart of the product, and
//      nothing else exercises it;
//   2. the end-to-end user journeys, from the creation form through to the
//      decrypted text shown to the reader.
//
// The report follows the presentation of the PHP tests, and the exit code is 0
// if and only if everything passes.

import { chromium } from 'playwright';

const BASE = process.env.STASHBIN_URL || 'http://127.0.0.1';
const USER = process.env.STASHBIN_USER || 'tester';
const PASS = process.env.STASHBIN_PASS || 'testpassword';

let passed = 0;
const failures = [];
let currentGroup = '';

const c = (code, s) => `[${code}m${s}[0m`;

function group(title) {
  currentGroup = title;
  console.log(`\n  ${c(1, title)}`);
}

async function test(name, fn) {
  try {
    await fn();
    passed++;
    console.log(`    ${c(32, '✔')} ${name}`);
  } catch (err) {
    failures.push([currentGroup, name]);
    console.log(`    ${c(31, '✘')} ${name}`);
    for (const line of String(err.message).split('\n')) {
      console.log(`        ${c(31, line)}`);
    }
  }
}

function assertTrue(cond, what) {
  if (!cond) throw new Error(what);
}

function assertEq(expected, actual, what) {
  if (expected !== actual) {
    const r = (v) => (typeof v === 'string' ? JSON.stringify(v.length > 120 ? v.slice(0, 120) + '…' : v) : String(v));
    throw new Error(`${what}\nattendu : ${r(expected)}\nobtenu  : ${r(actual)}`);
  }
}

async function assertRejects(promise, what) {
  try {
    await promise;
  } catch {
    return;
  }
  throw new Error(`${what} (no error thrown)`);
}

// --- Navigation helpers ------------------------------------------------------

async function login(page) {
  await page.goto(`${BASE}/login.php`);
  await page.fill('input[name="username"]', USER);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([page.waitForURL('**/index.php'), page.click('button[type="submit"]')]);
}

/** Fills the creation form and returns the two links produced. */
async function createSecret(page, { text, password = '', expire = '1h', burn = false }) {
  await page.goto(`${BASE}/index.php`);
  await page.fill('#secret', text);
  if (password) await page.fill('#password', password);
  await page.selectOption('#expire', expire);
  if (burn) await page.check('#burn');
  await page.click('#submit-btn');
  await page.waitForSelector('#result:not(.hidden)', { timeout: 20000 });
  return {
    share: await page.inputValue('#share-link'),
    remove: await page.inputValue('#delete-link'),
  };
}

/** Opens a sharing link and returns the decrypted text displayed. */
async function readSecret(context, link, { password = null, confirmBurn = false } = {}) {
  const page = await context.newPage();
  await page.goto(link);
  if (confirmBurn) {
    await page.waitForSelector('#burn-confirm:not(.hidden)', { timeout: 20000 });
    await page.click('#reveal-btn');
  }
  if (password !== null) {
    await page.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });
    await page.fill('#paste-password', password);
    await page.click('#password-form button[type="submit"]');
  }
  await page.waitForSelector('#content:not(.hidden)', { timeout: 20000 });
  const text = await page.textContent('#secret-text');
  await page.close();
  return text;
}

// --- Execution ---------------------------------------------------------------

// crypto.subtle only exists in a "secure context": HTTPS, or a loopback
// origin. Without one, WebCrypto is absent and the application cannot encrypt
// anything at all — which is what makes HTTPS mandatory in production, rather
// than a mere piece of advice. The runner therefore makes the browser share the
// application container's network namespace, so the address is 127.0.0.1: a
// secure context, with no certificate to make and no bypass flag.
const browser = await chromium.launch();
// Language set explicitly: the interface now follows Accept-Language, and
// Chromium's default language varies from one image to the next. The labels
// expected below are the French ones.
const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'fr-FR' });
const page = await context.newPage();
await login(page);

// A page with neither #create-form nor #status: stashbin.js's DOM handlers do
// not fire, only its functions stay defined.
const lab = await context.newPage();
await lab.goto(`${BASE}/login.php`);
await lab.addScriptTag({ url: 'assets/stashbin.js' });

// ---------------------------------------------------------------------------
group('Execution context');

await test('the page runs in a secure context', async () => {
  // Without it, crypto.subtle is absent and nothing can be encrypted: this is
  // the constraint that makes HTTPS mandatory in production, rather than a mere
  // piece of advice. This test also guards the ones after it: if the
  // application stopped being served from a secure origin, they would all fail
  // without the cause being legible.
  const state = await lab.evaluate(() => ({
    secure: window.isSecureContext,
    subtle: typeof crypto?.subtle?.importKey,
  }));
  assertTrue(state.secure, 'window.isSecureContext is true');
  assertEq('function', state.subtle, 'crypto.subtle.importKey available');
});

await test('stashbin.js\'s functions are loaded', async () => {
  const defined = await lab.evaluate(() => ['b64encode', 'b64decode', 'deriveKey', 'encryptText', 'decryptPayload']
    .filter((n) => typeof globalThis[n] === 'function'));
  assertEq(5, defined.length, `five functions expected, found: ${defined.join(', ')}`);
});

// ---------------------------------------------------------------------------
group('Cryptography — round trip');

for (const [label, text] of [
  ['plain ASCII text', 'server password: hunter2'],
  ['accents and French punctuation', 'Aujourd’hui — château, œuf, ça va ?'],
  ['emoji and symbols', '🔐 key → vault 🗝️ ✔'],
  ['line breaks and tabs', 'line 1\n\tline 2\r\nline 3'],
  ['JSON control characters', 'quote " backslash \\ brace }'],
  ['long text (64 KiB)', 'A'.repeat(65536)],
  ['a single letter', 'x'],
]) {
  await test(`decrypts what was encrypted: ${label}`, async () => {
    const out = await lab.evaluate(async (t) => {
      const { urlKey, payload } = await encryptText(t, '');
      return decryptPayload(payload, urlKey, '');
    }, text);
    assertEq(text, out, 'text returned unchanged');
  });
}

await test('decrypts a password-protected secret', async () => {
  const out = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('guarded secret', 'pass phrase');
    return decryptPayload(payload, urlKey, 'pass phrase');
  });
  assertEq('guarded secret', out, 'correct password accepted');
});

// ---------------------------------------------------------------------------
group('Cryptography — a ciphertext will not be forced');

await test('a wrong password fails', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', 'right phrase');
    try { await decryptPayload(payload, urlKey, 'wrong phrase'); return false; } catch { return true; }
  });
  assertTrue(ok, 'decryption refused');
});

await test('a wrong URL key fails', async () => {
  const ok = await lab.evaluate(async () => {
    const { payload } = await encryptText('secret', '');
    const other = await encryptText('other', '');
    try { await decryptPayload(payload, other.urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'decryption refused');
});

await test('a password supplied when there was none fails', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    try { await decryptPayload(payload, urlKey, 'unexpected'); return false; } catch { return true; }
  });
  assertTrue(ok, 'derivation really does depend on the password');
});

await test('an altered ciphertext is rejected (AES-GCM authentication)', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    const bytes = b64decode(payload.ct);
    bytes[Math.floor(bytes.length / 2)] ^= 0x01; // a single bit flipped
    payload.ct = b64encode(bytes);
    try { await decryptPayload(payload, urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'one changed bit is enough to invalidate the message');
});

await test('an altered initialisation vector is rejected', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    const iv = b64decode(payload.iv);
    iv[0] ^= 0xff;
    payload.iv = b64encode(iv);
    try { await decryptPayload(payload, urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'the vector is part of the authentication');
});

await test('an altered salt is rejected', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    const salt = b64decode(payload.salt);
    salt[0] ^= 0xff;
    payload.salt = b64encode(salt);
    try { await decryptPayload(payload, urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'the salt changes the derived key');
});

// ---------------------------------------------------------------------------
group('Cryptography — payload parameters');

await test('each encryption draws a fresh key, salt and vector', async () => {
  const r = await lab.evaluate(async () => {
    const a = await encryptText('same text', '');
    const b = await encryptText('same text', '');
    return {
      keys: a.urlKey !== b.urlKey,
      salts: a.payload.salt !== b.payload.salt,
      vectors: a.payload.iv !== b.payload.iv,
      ciphertexts: a.payload.ct !== b.payload.ct,
    };
  });
  assertTrue(r.keys, 'distinct URL keys');
  assertTrue(r.salts, 'distinct salts');
  assertTrue(r.vectors, 'distinct vectors');
  assertTrue(r.ciphertexts, 'the same text never produces the same ciphertext twice');
});

await test('the sizes match what the README announces', async () => {
  const r = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('x', '');
    return {
      urlKey: b64decode(urlKey).length,
      salt: b64decode(payload.salt).length,
      iv: b64decode(payload.iv).length,
      iter: payload.iter,
      v: payload.v,
    };
  });
  assertEq(32, r.urlKey, '256-bit URL key');
  assertEq(16, r.salt, '128-bit salt');
  assertEq(12, r.iv, '96-bit vector');
  assertEq(310000, r.iter, '310,000 PBKDF2 iterations');
  assertEq(1, r.v, 'format version');
});

await test('the "pwd" flag reflects whether a password is present', async () => {
  const r = await lab.evaluate(async () => ({
    without: (await encryptText('x', '')).payload.pwd,
    with: (await encryptText('x', 'phrase')).payload.pwd,
  }));
  assertEq(0, r.without, 'no password');
  assertEq(1, r.with, 'password present');
});

await test('the URL key is base64url-encoded, without padding', async () => {
  const key = await lab.evaluate(async () => (await encryptText('x', '')).urlKey);
  assertTrue(/^[A-Za-z0-9_-]+$/.test(key), `URL-safe characters: ${key}`);
  assertTrue(!key.includes('='), 'no padding character');
});

await test('base64url encoding survives a round trip on arbitrary bytes', async () => {
  const ok = await lab.evaluate(() => {
    for (let n = 0; n < 200; n++) {
      const src = crypto.getRandomValues(new Uint8Array(n));
      const back = b64decode(b64encode(src));
      if (back.length !== src.length) return false;
      for (let i = 0; i < n; i++) if (back[i] !== src[i]) return false;
    }
    return true;
  });
  assertTrue(ok, 'every length from 0 to 199 bytes');
});

// ---------------------------------------------------------------------------
group('Full journey in the browser');

await test('creating then reading a secret returns the original text', async () => {
  const text = 'The server must never see this sentence — 🔐';
  const { share } = await createSecret(page, { text });
  assertTrue(share.includes('#'), `the link carries the key in its fragment: ${share}`);
  assertEq(text, await readSecret(context, share), 'decrypted text identical');
});

await test('the decryption key is never transmitted to the server', async () => {
  const { share } = await createSecret(page, { text: 'fragment check' });
  const [before, fragment] = share.split('#');
  assertTrue(Boolean(fragment), 'a fragment really is present');
  assertTrue(!before.includes(fragment), 'the key does not appear in the part sent to the server');
});

await test('a password-protected secret demands that password', async () => {
  const text = 'doubly protected';
  const { share } = await createSecret(page, { text, password: 'secret phrase' });

  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'the content stays hidden while the password is missing');
  await p.close();

  assertEq(text, await readSecret(context, share, { password: 'secret phrase' }), 'correct password');
});

await test('a wrong password shows an error without revealing the secret', async () => {
  const { share } = await createSecret(page, { text: 'unreachable', password: 'the right one' });
  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });
  await p.fill('#paste-password', 'the wrong one');
  await p.click('#password-form button[type="submit"]');
  await p.waitForSelector('#decrypt-error:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'the secret stays hidden');
  assertEq('Mot de passe incorrect.', (await p.textContent('#decrypt-error')).trim(), 'message shown');
  await p.close();
});

await test('a link whose key has been truncated decrypts nothing', async () => {
  const { share } = await createSecret(page, { text: 'truncated key' });
  const [url, key] = share.split('#');
  const p = await context.newPage();
  await p.goto(`${url}#${key.slice(0, -4)}`);
  await p.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'no content displayed');
  await p.close();
});

await test('a link without a fragment reports the missing key', async () => {
  const { share } = await createSecret(page, { text: 'no key' });
  const p = await context.newPage();
  await p.goto(share.split('#')[0]);
  await p.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  const msg = await p.textContent('#status');
  assertTrue(msg.includes('clé de déchiffrement'), `explicit message, got: ${msg}`);
  await p.close();
});

// ---------------------------------------------------------------------------
group('Burn after reading, seen from the browser');

await test('a confirmation screen precedes consuming the secret', async () => {
  const { share } = await createSecret(page, { text: 'single use', burn: true });
  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#burn-confirm:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'the secret is not revealed yet');
  await p.close();

  // The secret must therefore still be readable after merely opening the page.
  assertEq('single use', await readSecret(context, share, { confirmBurn: true }), 'revealed after confirmation');
});

await test('after confirmation, the secret is no longer reachable', async () => {
  const { share } = await createSecret(page, { text: 'volatile', burn: true });
  assertEq('volatile', await readSecret(context, share, { confirmBurn: true }), 'first read');

  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  const msg = await p.textContent('#status');
  assertTrue(msg.includes('n’existe pas ou plus'), `absence message expected, got: ${msg}`);
  await p.close();
});

// ---------------------------------------------------------------------------
group('Deletion link, seen from the browser');

await test('the creator can delete their secret through the link provided', async () => {
  const text = 'to be deleted';
  const { share, remove } = await createSecret(page, { text });
  assertEq(text, await readSecret(context, share), 'readable before deletion');

  const p = await context.newPage();
  await p.goto(remove);
  assertTrue((await p.textContent('body')).includes('deleted'), 'deletion confirmed');
  await p.close();

  const q = await context.newPage();
  await q.goto(share);
  await q.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  assertTrue((await q.textContent('#status')).includes('n’existe pas ou plus'), 'secret now not found');
  await q.close();
});

// ---------------------------------------------------------------------------
group("The creator's inventory");

/** The identifier a sharing link carries, which is what names an entry. */
function idOf(link) {
  return new URL(link).searchParams.get('id');
}

await test('a secret just created heads the list, never read', async () => {
  const { share } = await createSecret(page, { text: 'à inventorier' });
  const id = idOf(share);

  await page.goto(`${BASE}/secrets.php`);
  const first = page.locator('article.secret').first();
  assertEq(id, (await first.locator('h2').textContent()).trim(), 'newest first, named by its identifier');
  assertTrue((await first.locator('.badge.state').textContent()).includes('Jamais consulté'), 'never read yet');
});

await test('reading the secret turns the entry into "read once"', async () => {
  const { share } = await createSecret(page, { text: 'à lire' });
  await readSecret(context, share);

  await page.goto(`${BASE}/secrets.php`);
  const entry = page.locator('article.secret').filter({ hasText: idOf(share) }).first();
  assertTrue((await entry.locator('.badge.state').textContent()).includes('Consulté une fois'), 'one read counted');
});

await test('the access log names the browser that came for the secret', async () => {
  const { share } = await createSecret(page, { text: 'à tracer' });
  await readSecret(context, share);

  await page.goto(`${BASE}/secrets.php`);
  const entry = page.locator('article.secret').filter({ hasText: idOf(share) }).first();
  await entry.locator('summary').click();
  const log = await entry.locator('table').textContent();
  assertTrue(log.includes('Secret remis'), 'the access is listed');
  assertTrue(log.includes('HeadlessChrome') || log.includes('Chrome'), `the browser is named (got: ${log})`);
});

await test('deleting from the list makes the link stop working', async () => {
  const { share } = await createSecret(page, { text: 'à supprimer' });
  const id = idOf(share);

  await page.goto(`${BASE}/secrets.php`);
  await page.locator('article.secret').filter({ hasText: id }).first().locator('button').click();
  await page.waitForURL('**/secrets.php?done=deleted');

  const after = page.locator('article.secret').filter({ hasText: id }).first();
  assertTrue((await after.locator('.badge.state').textContent()).includes('Supprimé'), 'entry reads as deleted');

  const reader = await context.newPage();
  await reader.goto(share);
  await reader.waitForFunction(
    () => !document.getElementById('status').textContent.includes('Chargement'),
    null,
    { timeout: 20000 },
  );
  assertTrue((await reader.textContent('#status')).includes('n’existe pas ou plus'), 'the reader gets nothing');
  await reader.close();
});

await test('a burned secret is reported as destroyed, and the replay is logged', async () => {
  const { share } = await createSecret(page, { text: 'usage unique', burn: true });
  await readSecret(context, share, { confirmBurn: true });

  // Coming back to a spent link is exactly what a chat application's link
  // preview does to a single-use secret.
  const late = await context.newPage();
  await late.goto(share);
  await late.waitForFunction(
    () => !document.getElementById('status').textContent.includes('Chargement'),
    null,
    { timeout: 20000 },
  );
  await late.close();

  await page.goto(`${BASE}/secrets.php`);
  const entry = page.locator('article.secret').filter({ hasText: idOf(share) }).first();
  assertTrue((await entry.locator('.badge.state').textContent()).includes('Détruit après lecture'), 'reported as burned');
  await entry.locator('summary').click();
  const log = await entry.locator('table').textContent();
  assertTrue(log.includes('Lien rejoué'), 'the replay is in the log');
});

await test('an entry can be removed from the history once the secret is gone', async () => {
  const { share } = await createSecret(page, { text: 'éphémère', burn: true });
  const id = idOf(share);
  await readSecret(context, share, { confirmBurn: true });

  await page.goto(`${BASE}/secrets.php`);
  await page.locator('article.secret').filter({ hasText: id }).first().locator('button').click();
  await page.waitForURL('**/secrets.php?done=forgotten');
  assertEq(0, await page.locator('article.secret').filter({ hasText: id }).count(), 'entry gone from the list');
});

// ---------------------------------------------------------------------------
group('Interface language');

// Fresh contexts: the one used by the previous tests carries an open session,
// and login.php would then redirect to the creation page.
const english = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'en-US' });
const french = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'fr-FR' });

await test('an English-speaking browser gets the interface in English', async () => {
  const p = await english.newPage();
  await p.goto(`${BASE}/login.php`);
  assertEq('en', await p.getAttribute('html', 'lang'), 'language declared in the page');
  assertTrue((await p.textContent('button[type="submit"]')).includes('Sign in'), 'button translated');
  await p.close();
});

await test('a French-speaking browser still gets the interface in French', async () => {
  const p = await french.newPage();
  await p.goto(`${BASE}/login.php`);
  assertEq('fr', await p.getAttribute('html', 'lang'), 'language declared in the page');
  assertTrue((await p.textContent('button[type="submit"]')).includes('Se connecter'), 'button in French');
  await p.close();
});

await test('a browser asking for an untranslated language gets English', async () => {
  // The fallback must be a real language: served empty, the interface would
  // show its key identifiers.
  const german = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'de-DE' });
  const p = await german.newPage();
  await p.goto(`${BASE}/login.php`);
  assertEq('en', await p.getAttribute('html', 'lang'), 'English served for want of German');
  assertTrue((await p.textContent('button[type="submit"]')).includes('Sign in'), 'button in English');
  assertTrue(!(await p.content()).includes('login.submit'), 'no raw key displayed');
  await p.close();
  await german.close();
});

await test('the "lang" parameter outranks the browser language', async () => {
  const p = await english.newPage();
  await p.goto(`${BASE}/login.php?lang=fr`);
  assertEq('fr', await p.getAttribute('html', 'lang'), 'explicit choice honoured');
  await p.close();
});

await test('the messages the JavaScript produces are translated too', async () => {
  // The client's dictionary comes from the page: that is the path being
  // checked here, not a second set of strings embedded in the script.
  const p = await english.newPage();
  await p.goto(`${BASE}/view.php?id=${'z'.repeat(16)}#key`);
  await p.waitForFunction(() => !document.getElementById('status').textContent.includes('Loading'), null, { timeout: 20000 });
  const status = await p.textContent('#status');
  assertTrue(status.includes('does not exist any more'), `message in English (got: ${status})`);
  await p.close();
});

await test('the creation journey works identically in English', async () => {
  // Translation must change nothing about the encryption: the text read back
  // must be exactly the one typed in.
  const p = await english.newPage();
  await p.goto(`${BASE}/login.php`);
  await p.fill('input[name="username"]', USER);
  await p.fill('input[name="password"]', PASS);
  await Promise.all([p.waitForURL('**/index.php'), p.click('button[type="submit"]')]);

  const secret = 'English interface, French secret — éàü 🔐';
  await p.fill('#secret', secret);
  await p.click('#submit-btn');
  await p.waitForSelector('#result:not(.hidden)', { timeout: 20000 });
  const link = await p.inputValue('#share-link');
  await p.close();

  assertEq(secret, await readSecret(english, link), 'text returned unchanged');
});

await french.close();
await english.close();

// ---------------------------------------------------------------------------
group('Phone-sized screens');

// The server sends the same HTML to every client: everything that makes the
// interface usable on a phone lives in the stylesheet, and nothing else in the
// test set would notice it breaking. 360x640 is the narrowest screen still
// worth designing for.
const phone = await browser.newContext({
  ignoreHTTPSErrors: true,
  locale: 'fr-FR',
  viewport: { width: 360, height: 640 },
  isMobile: true,
  hasTouch: true,
});

/** How far the page sticks out of the viewport, and what sticks out. */
function sideways(p) {
  return p.evaluate(() => {
    const doc = document.documentElement;
    return {
      extra: doc.scrollWidth - doc.clientWidth,
      culprits: [...document.querySelectorAll('body *')]
        .filter((el) => {
          const r = el.getBoundingClientRect();
          return r.width > 0 && (r.right > doc.clientWidth + 1 || r.left < -1);
        })
        .map((el) => el.tagName.toLowerCase() + (el.id ? `#${el.id}` : '')),
    };
  });
}

/** Visible fields rendered below 16px. */
function tinyFields(p) {
  return p.evaluate(() => [...document.querySelectorAll('input:not([type="checkbox"]), select, textarea')]
    .filter((el) => el.getBoundingClientRect().height > 0 && parseFloat(getComputedStyle(el).fontSize) < 16)
    .map((el) => `${el.id || el.name}=${getComputedStyle(el).fontSize}`));
}

/** Visible tap targets shorter than 44px. */
function tinyTargets(p) {
  return p.evaluate(() => [...document.querySelectorAll('button, a, label.checkbox, summary')]
    .filter((el) => {
      const h = el.getBoundingClientRect().height;
      return h > 0 && h < 44;
    })
    .map((el) => `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}=${Math.round(el.getBoundingClientRect().height)}px`));
}

// Three pages measured together: the sign-in form, the creation page showing
// its result, and a reader facing a single 340-character line — the worst case
// for a narrow screen.
const signin = await phone.newPage();
await signin.goto(`${BASE}/login.php`);

const author = await phone.newPage();
await login(author);
const phoneLinks = await createSecret(author, {
  text: `ssh-rsa AAAAB3NzaC1yc2E${'x'.repeat(300)} user@host`,
  expire: '1h',
});

const reader = await phone.newPage();
await reader.goto(phoneLinks.share);
await reader.waitForSelector('#content:not(.hidden)', { timeout: 20000 });

const guarded = await createSecret(author, { text: 'protégé', password: 'phone', expire: '1h' });
const asked = await phone.newPage();
await asked.goto(guarded.share);
await asked.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });

// The inventory, with its access log unfolded: that table is the widest thing
// the interface has to fit on a phone.
const inventory = await phone.newPage();
await inventory.goto(`${BASE}/secrets.php`);
await inventory.evaluate(() => document.querySelectorAll('details').forEach((d) => (d.open = true)));

const phonePages = [
  ['login.php', signin],
  ['index.php', author],
  ['view.php', reader],
  ['view.php (password)', asked],
  ['secrets.php', inventory],
];

await test('every page fits a 360px screen without touching its edges', async () => {
  for (const [name, p] of phonePages) {
    const { extra, culprits } = await sideways(p);
    assertEq(0, extra, `${name}: ${extra}px too wide (${culprits.join(', ') || 'no element identified'})`);
    // Below its max-width the card fills the screen and its "auto" margin
    // collapses to zero, leaving border and rounded corners flush against the
    // edges.
    const left = await p.evaluate(() => Math.round(document.querySelector('main').getBoundingClientRect().left));
    assertTrue(left >= 8, `${name}: the card starts at ${left}px, flush against the edge`);
  }
});

await test('no field is rendered below 16px, which would make Safari iOS zoom in on focus', async () => {
  // Under that size, iOS zooms the page in when a field takes focus and never
  // zooms back out: the rest of the form ends up off-screen.
  for (const [name, p] of phonePages) {
    assertEq('', (await tinyFields(p)).join(', '), `${name}: fields too small`);
  }
});

await test('buttons, links and checkbox rows can be hit with a fingertip', async () => {
  for (const [name, p] of phonePages) {
    assertEq('', (await tinyTargets(p)).join(', '), `${name}: targets under 44px`);
  }
});

await test('the complete journey goes through with taps alone', async () => {
  const p = await phone.newPage();
  await p.goto(`${BASE}/index.php`);
  const secret = 'écrit au doigt sur un téléphone';
  await p.fill('#secret', secret);
  // Tapping the label, not the 13px box: that widened row is exactly what the
  // stylesheet is there to provide.
  await p.tap('label.checkbox');
  assertTrue(await p.isChecked('#burn'), 'tapping the row ticks the checkbox');
  await p.fill('#password', 'phone');
  await p.tap('#submit-btn');
  await p.waitForSelector('#result:not(.hidden)', { timeout: 20000 });
  const share = await p.inputValue('#share-link');
  await p.close();

  const q = await phone.newPage();
  await q.goto(share);
  await q.waitForSelector('#burn-confirm:not(.hidden)', { timeout: 20000 });
  await q.tap('#reveal-btn');
  await q.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });
  await q.fill('#paste-password', 'phone');
  await q.tap('#password-form button[type="submit"]');
  await q.waitForSelector('#content:not(.hidden)', { timeout: 20000 });
  assertEq(secret, await q.textContent('#secret-text'), 'text read back unchanged');
  await q.close();
});

await test('a phone held sideways still shows more than the text area', async () => {
  // rows="10" is measured for a desktop screen: left alone, the text area
  // alone is taller than a landscape phone and the submit button never comes
  // into view.
  const landscape = await browser.newContext({
    ignoreHTTPSErrors: true,
    locale: 'fr-FR',
    viewport: { width: 667, height: 375 },
    isMobile: true,
    hasTouch: true,
  });
  const p = await landscape.newPage();
  await login(p);
  const height = await p.evaluate(() => Math.round(document.getElementById('secret').getBoundingClientRect().height));
  assertTrue(height <= 188, `the text area takes ${height}px of the 375 available`);
  await landscape.close();
});

await phone.close();

await browser.close();

// ---------------------------------------------------------------------------
const total = passed + failures.length;
console.log('');
if (failures.length === 0) {
  console.log(`  ${c(32, `Browser tests: ${total} tests, all passed`)}`);
  process.exit(0);
}
console.log(`  ${c(31, `Browser tests: ${total} tests, ${failures.length} failed`)}`);
for (const [g, n] of failures) console.log(`    - ${g} / ${n}`);
process.exit(1);
