// Tests navigateur : cryptographie réelle et parcours d'interface complets.
//
// Deux niveaux dans un seul fichier :
//   1. les fonctions de public/assets/stashbin.js appelées directement dans la
//      page, sans toucher au DOM — c'est le cœur du produit, que rien d'autre
//      n'exerce ;
//   2. les parcours utilisateur de bout en bout, depuis le formulaire de
//      création jusqu'au texte déchiffré affiché au lecteur.
//
// Le compte rendu reprend la présentation des tests PHP, et le code de sortie
// vaut 0 si et seulement si tout passe.

import { chromium } from 'playwright';

const BASE = process.env.STASHBIN_URL || 'http://127.0.0.1';
const USER = process.env.STASHBIN_USER || 'testeur';
const PASS = process.env.STASHBIN_PASS || 'motdepassetest';

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
  throw new Error(`${what} (aucune erreur levée)`);
}

// --- Aides de navigation -----------------------------------------------------

async function login(page) {
  await page.goto(`${BASE}/login.php`);
  await page.fill('input[name="username"]', USER);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([page.waitForURL('**/index.php'), page.click('button[type="submit"]')]);
}

/** Remplit le formulaire de création et renvoie les deux liens produits. */
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

/** Ouvre un lien de partage et renvoie le texte déchiffré affiché. */
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

// --- Exécution ---------------------------------------------------------------

// crypto.subtle n'existe que dans un « contexte sécurisé » : HTTPS, ou une
// origine loopback. Sans cela WebCrypto est absent et l'application ne peut
// rien chiffrer du tout — c'est ce qui rend HTTPS obligatoire en production, et
// non un simple conseil. Le lanceur fait donc partager au navigateur l'espace
// réseau du conteneur applicatif, si bien que l'adresse est 127.0.0.1 : un
// contexte sûr, sans certificat à fabriquer ni drapeau de contournement.
const browser = await chromium.launch();
// Langue fixée explicitement : l'interface suit désormais Accept-Language, et
// la langue par défaut de Chromium varie selon l'image. Les libellés attendus
// plus bas sont ceux du français.
const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'fr-FR' });
const page = await context.newPage();
await login(page);

// Page dépourvue de #create-form et de #status : les gestionnaires DOM de
// stashbin.js ne s'activent pas, seules les fonctions restent définies.
const lab = await context.newPage();
await lab.goto(`${BASE}/login.php`);
await lab.addScriptTag({ url: 'assets/stashbin.js' });

// ---------------------------------------------------------------------------
group('Contexte d\'exécution');

await test('la page s\'exécute dans un contexte sécurisé', async () => {
  // Sans cela, crypto.subtle est absent et rien ne peut être chiffré : c'est
  // la contrainte qui rend HTTPS obligatoire en production, et non un simple
  // conseil. Ce test garde aussi les suivants : si l'application cessait d'être
  // servie depuis une origine sûre, ils échoueraient tous sans que la cause
  // soit lisible.
  const state = await lab.evaluate(() => ({
    secure: window.isSecureContext,
    subtle: typeof crypto?.subtle?.importKey,
  }));
  assertTrue(state.secure, 'window.isSecureContext vaut vrai');
  assertEq('function', state.subtle, 'crypto.subtle.importKey disponible');
});

await test('les fonctions de stashbin.js sont chargées', async () => {
  const defined = await lab.evaluate(() => ['b64encode', 'b64decode', 'deriveKey', 'encryptText', 'decryptPayload']
    .filter((n) => typeof globalThis[n] === 'function'));
  assertEq(5, defined.length, `cinq fonctions attendues, trouvées : ${defined.join(', ')}`);
});

// ---------------------------------------------------------------------------
group('Cryptographie — aller-retour');

for (const [label, text] of [
  ['texte ASCII simple', 'mot de passe du serveur : hunter2'],
  ['accents et ponctuation française', 'Aujourd’hui — château, œuf, ça va ?'],
  ['emoji et symboles', '🔐 clé → coffre 🗝️ ✔'],
  ['sauts de ligne et tabulations', 'ligne 1\n\tligne 2\r\nligne 3'],
  ['caractères de contrôle JSON', 'guillemet " antislash \\ accolade }'],
  ['texte long (64 Kio)', 'A'.repeat(65536)],
  ['une seule lettre', 'x'],
]) {
  await test(`déchiffre ce qui a été chiffré : ${label}`, async () => {
    const out = await lab.evaluate(async (t) => {
      const { urlKey, payload } = await encryptText(t, '');
      return decryptPayload(payload, urlKey, '');
    }, text);
    assertEq(text, out, 'texte restitué à l\'identique');
  });
}

await test('déchiffre un secret protégé par mot de passe', async () => {
  const out = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret gardé', 'phrase de passe');
    return decryptPayload(payload, urlKey, 'phrase de passe');
  });
  assertEq('secret gardé', out, 'mot de passe correct accepté');
});

// ---------------------------------------------------------------------------
group('Cryptographie — un chiffré ne se laisse pas forcer');

await test('un mot de passe erroné échoue', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', 'bonne phrase');
    try { await decryptPayload(payload, urlKey, 'mauvaise phrase'); return false; } catch { return true; }
  });
  assertTrue(ok, 'déchiffrement refusé');
});

await test('une clé d\'URL erronée échoue', async () => {
  const ok = await lab.evaluate(async () => {
    const { payload } = await encryptText('secret', '');
    const autre = await encryptText('autre', '');
    try { await decryptPayload(payload, autre.urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'déchiffrement refusé');
});

await test('un mot de passe fourni alors qu\'il n\'y en avait pas échoue', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    try { await decryptPayload(payload, urlKey, 'inattendu'); return false; } catch { return true; }
  });
  assertTrue(ok, 'la dérivation dépend bien du mot de passe');
});

await test('un chiffré altéré est rejeté (authentification AES-GCM)', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    const bytes = b64decode(payload.ct);
    bytes[Math.floor(bytes.length / 2)] ^= 0x01; // un seul bit retourné
    payload.ct = b64encode(bytes);
    try { await decryptPayload(payload, urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'un bit modifié suffit à invalider le message');
});

await test('un vecteur d\'initialisation altéré est rejeté', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    const iv = b64decode(payload.iv);
    iv[0] ^= 0xff;
    payload.iv = b64encode(iv);
    try { await decryptPayload(payload, urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'le vecteur fait partie de l\'authentification');
});

await test('un sel altéré est rejeté', async () => {
  const ok = await lab.evaluate(async () => {
    const { urlKey, payload } = await encryptText('secret', '');
    const salt = b64decode(payload.salt);
    salt[0] ^= 0xff;
    payload.salt = b64encode(salt);
    try { await decryptPayload(payload, urlKey, ''); return false; } catch { return true; }
  });
  assertTrue(ok, 'le sel change la clé dérivée');
});

// ---------------------------------------------------------------------------
group('Cryptographie — paramètres du payload');

await test('chaque chiffrement tire une clé, un sel et un vecteur neufs', async () => {
  const r = await lab.evaluate(async () => {
    const a = await encryptText('même texte', '');
    const b = await encryptText('même texte', '');
    return {
      cles: a.urlKey !== b.urlKey,
      sels: a.payload.salt !== b.payload.salt,
      vecteurs: a.payload.iv !== b.payload.iv,
      chiffres: a.payload.ct !== b.payload.ct,
    };
  });
  assertTrue(r.cles, 'clés d\'URL distinctes');
  assertTrue(r.sels, 'sels distincts');
  assertTrue(r.vecteurs, 'vecteurs distincts');
  assertTrue(r.chiffres, 'un même texte ne produit jamais deux fois le même chiffré');
});

await test('les tailles respectent les annonces du README', async () => {
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
  assertEq(32, r.urlKey, 'clé d\'URL de 256 bits');
  assertEq(16, r.salt, 'sel de 128 bits');
  assertEq(12, r.iv, 'vecteur de 96 bits');
  assertEq(310000, r.iter, '310 000 itérations PBKDF2');
  assertEq(1, r.v, 'version de format');
});

await test('l\'indicateur « pwd » reflète la présence d\'un mot de passe', async () => {
  const r = await lab.evaluate(async () => ({
    sans: (await encryptText('x', '')).payload.pwd,
    avec: (await encryptText('x', 'phrase')).payload.pwd,
  }));
  assertEq(0, r.sans, 'aucun mot de passe');
  assertEq(1, r.avec, 'mot de passe présent');
});

await test('la clé d\'URL est encodée en base64url, sans remplissage', async () => {
  const key = await lab.evaluate(async () => (await encryptText('x', '')).urlKey);
  assertTrue(/^[A-Za-z0-9_-]+$/.test(key), `caractères sûrs pour une URL : ${key}`);
  assertTrue(!key.includes('='), 'aucun caractère de remplissage');
});

await test('l\'encodage base64url survit à un aller-retour sur des octets quelconques', async () => {
  const ok = await lab.evaluate(() => {
    for (let n = 0; n < 200; n++) {
      const src = crypto.getRandomValues(new Uint8Array(n));
      const back = b64decode(b64encode(src));
      if (back.length !== src.length) return false;
      for (let i = 0; i < n; i++) if (back[i] !== src[i]) return false;
    }
    return true;
  });
  assertTrue(ok, 'toutes les longueurs de 0 à 199 octets');
});

// ---------------------------------------------------------------------------
group('Parcours complet dans le navigateur');

await test('créer puis relire un secret restitue le texte d\'origine', async () => {
  const texte = 'Le serveur ne doit jamais voir cette phrase — 🔐';
  const { share } = await createSecret(page, { text: texte });
  assertTrue(share.includes('#'), `le lien porte la clé dans son fragment : ${share}`);
  assertEq(texte, await readSecret(context, share), 'texte déchiffré identique');
});

await test('la clé de déchiffrement n\'est jamais transmise au serveur', async () => {
  const { share } = await createSecret(page, { text: 'contrôle du fragment' });
  const [avant, fragment] = share.split('#');
  assertTrue(Boolean(fragment), 'un fragment est bien présent');
  assertTrue(!avant.includes(fragment), 'la clé n\'apparaît pas dans la partie envoyée au serveur');
});

await test('un secret protégé par mot de passe exige ce mot de passe', async () => {
  const texte = 'doublement protégé';
  const { share } = await createSecret(page, { text: texte, password: 'phrase secrète' });

  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'le contenu reste masqué tant que le mot de passe manque');
  await p.close();

  assertEq(texte, await readSecret(context, share, { password: 'phrase secrète' }), 'mot de passe correct');
});

await test('un mot de passe erroné affiche une erreur sans révéler le secret', async () => {
  const { share } = await createSecret(page, { text: 'inaccessible', password: 'la bonne' });
  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#password-prompt:not(.hidden)', { timeout: 20000 });
  await p.fill('#paste-password', 'la mauvaise');
  await p.click('#password-form button[type="submit"]');
  await p.waitForSelector('#decrypt-error:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'le secret reste masqué');
  assertEq('Mot de passe incorrect.', (await p.textContent('#decrypt-error')).trim(), 'message affiché');
  await p.close();
});

await test('un lien dont la clé a été tronquée ne déchiffre rien', async () => {
  const { share } = await createSecret(page, { text: 'clé tronquée' });
  const [url, key] = share.split('#');
  const p = await context.newPage();
  await p.goto(`${url}#${key.slice(0, -4)}`);
  await p.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'aucun contenu affiché');
  await p.close();
});

await test('un lien sans fragment signale l\'absence de clé', async () => {
  const { share } = await createSecret(page, { text: 'sans clé' });
  const p = await context.newPage();
  await p.goto(share.split('#')[0]);
  await p.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  const msg = await p.textContent('#status');
  assertTrue(msg.includes('clé de déchiffrement'), `message explicite, obtenu : ${msg}`);
  await p.close();
});

// ---------------------------------------------------------------------------
group('Destruction après lecture, vue du navigateur');

await test('un écran de confirmation précède la consommation du secret', async () => {
  const { share } = await createSecret(page, { text: 'à usage unique', burn: true });
  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#burn-confirm:not(.hidden)', { timeout: 20000 });
  assertTrue(await p.isHidden('#content'), 'le secret n\'est pas encore révélé');
  await p.close();

  // Le secret doit donc être toujours lisible après avoir seulement ouvert la page.
  assertEq('à usage unique', await readSecret(context, share, { confirmBurn: true }), 'révélé après confirmation');
});

await test('après confirmation, le secret n\'est plus accessible', async () => {
  const { share } = await createSecret(page, { text: 'volatil', burn: true });
  assertEq('volatil', await readSecret(context, share, { confirmBurn: true }), 'première lecture');

  const p = await context.newPage();
  await p.goto(share);
  await p.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  const msg = await p.textContent('#status');
  assertTrue(msg.includes('n’existe pas ou plus'), `message d'absence attendu, obtenu : ${msg}`);
  await p.close();
});

// ---------------------------------------------------------------------------
group('Lien de suppression, vu du navigateur');

await test('le créateur peut supprimer son secret par le lien fourni', async () => {
  const texte = 'à supprimer';
  const { share, remove } = await createSecret(page, { text: texte });
  assertEq(texte, await readSecret(context, share), 'lisible avant suppression');

  const p = await context.newPage();
  await p.goto(remove);
  assertTrue((await p.textContent('body')).includes('deleted'), 'suppression confirmée');
  await p.close();

  const q = await context.newPage();
  await q.goto(share);
  await q.waitForSelector('#status:not(.hidden)', { timeout: 20000 });
  assertTrue((await q.textContent('#status')).includes('n’existe pas ou plus'), 'secret devenu introuvable');
  await q.close();
});

// ---------------------------------------------------------------------------
group('Langue de l\'interface');

// Contextes neufs : celui des tests précédents porte une session ouverte, et
// login.php redirigerait alors vers la page de création.
const english = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'en-US' });
const french = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'fr-FR' });

await test('un navigateur anglophone reçoit l\'interface en anglais', async () => {
  const p = await english.newPage();
  await p.goto(`${BASE}/login.php`);
  assertEq('en', await p.getAttribute('html', 'lang'), 'langue déclarée dans la page');
  assertTrue((await p.textContent('button[type="submit"]')).includes('Sign in'), 'bouton traduit');
  await p.close();
});

await test('un navigateur francophone reçoit toujours l\'interface en français', async () => {
  const p = await french.newPage();
  await p.goto(`${BASE}/login.php`);
  assertEq('fr', await p.getAttribute('html', 'lang'), 'langue déclarée dans la page');
  assertTrue((await p.textContent('button[type="submit"]')).includes('Se connecter'), 'bouton en français');
  await p.close();
});

await test('le paramètre « lang » l\'emporte sur la langue du navigateur', async () => {
  const p = await english.newPage();
  await p.goto(`${BASE}/login.php?lang=fr`);
  assertEq('fr', await p.getAttribute('html', 'lang'), 'choix explicite retenu');
  await p.close();
});

await test('les messages produits par le JavaScript sont traduits eux aussi', async () => {
  // Le dictionnaire du client vient de la page : c'est ce chemin-là qu'on
  // vérifie, et non un second jeu de chaînes embarqué dans le script.
  const p = await english.newPage();
  await p.goto(`${BASE}/view.php?id=${'z'.repeat(16)}#cle`);
  await p.waitForFunction(() => !document.getElementById('status').textContent.includes('Loading'), null, { timeout: 20000 });
  const status = await p.textContent('#status');
  assertTrue(status.includes('does not exist any more'), `message en anglais (obtenu : ${status})`);
  await p.close();
});

await test('le parcours de création fonctionne à l\'identique en anglais', async () => {
  // La traduction ne doit rien changer au chiffrement : le texte relu doit
  // être exactement celui saisi.
  const p = await english.newPage();
  await p.goto(`${BASE}/login.php`);
  await p.fill('input[name="username"]', USER);
  await p.fill('input[name="password"]', PASS);
  await Promise.all([p.waitForURL('**/index.php'), p.click('button[type="submit"]')]);

  const secret = 'Interface en anglais, secret en français — éàü 🔐';
  await p.fill('#secret', secret);
  await p.click('#submit-btn');
  await p.waitForSelector('#result:not(.hidden)', { timeout: 20000 });
  const link = await p.inputValue('#share-link');
  await p.close();

  assertEq(secret, await readSecret(english, link), 'texte restitué à l\'identique');
});

await french.close();
await english.close();

await browser.close();

// ---------------------------------------------------------------------------
const total = passed + failures.length;
console.log('');
if (failures.length === 0) {
  console.log(`  ${c(32, `Tests navigateur : ${total} tests, tous réussis`)}`);
  process.exit(0);
}
console.log(`  ${c(31, `Tests navigateur : ${total} tests, ${failures.length} en échec`)}`);
for (const [g, n] of failures) console.log(`    - ${g} / ${n}`);
process.exit(1);
