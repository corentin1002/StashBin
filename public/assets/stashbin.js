'use strict';

// --- Translations -----------------------------------------------------------

// The strings come from the page, where PHP put them: the CSP forbids inline
// script, and a client-owned dictionary would end up diverging from the
// server's. The empty object covers pages that publish none.
const STRINGS = JSON.parse(document.body.dataset.i18n || '{}');

function t(key, vars) {
  let s = STRINGS[key] !== undefined ? STRINGS[key] : key;
  for (const name in vars) s = s.split('{' + name + '}').join(vars[name]);
  return s;
}

// An API error. The code is the stable contract; the message is translated and
// serves display only.
function apiError(data) {
  const err = new Error(data.error || t('server_error'));
  err.code = data.code;
  return err;
}

// --- Utilities --------------------------------------------------------------

const PBKDF2_ITERATIONS = 310000;

function b64encode(buf) {
  const bytes = new Uint8Array(buf);
  let binary = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64decode(str) {
  const binary = atob(str.replace(/-/g, '+').replace(/_/g, '/'));
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}

// Derives the AES key from the URL key and the optional password. The server
// sees neither.
async function deriveKey(urlKeyBytes, password, salt, iterations) {
  const passwordBytes = new TextEncoder().encode(password || '');
  const material = new Uint8Array(urlKeyBytes.length + passwordBytes.length);
  material.set(urlKeyBytes);
  material.set(passwordBytes, urlKeyBytes.length);
  const base = await crypto.subtle.importKey('raw', material, 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations, hash: 'SHA-256' },
    base,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt']
  );
}

async function encryptText(text, password) {
  const urlKey = crypto.getRandomValues(new Uint8Array(32));
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await deriveKey(urlKey, password, salt, PBKDF2_ITERATIONS);
  const ct = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    key,
    new TextEncoder().encode(text)
  );
  return {
    urlKey: b64encode(urlKey),
    payload: {
      v: 1,
      iv: b64encode(iv),
      salt: b64encode(salt),
      iter: PBKDF2_ITERATIONS,
      pwd: password ? 1 : 0,
      ct: b64encode(ct),
    },
  };
}

async function decryptPayload(payload, urlKeyStr, password) {
  const key = await deriveKey(
    b64decode(urlKeyStr),
    password,
    b64decode(payload.salt),
    payload.iter
  );
  const plain = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: b64decode(payload.iv) },
    key,
    b64decode(payload.ct)
  );
  return new TextDecoder().decode(plain);
}

function show(id) { document.getElementById(id).classList.remove('hidden'); }
function hide(id) { document.getElementById(id).classList.add('hidden'); }
function setText(id, text) { document.getElementById(id).textContent = text; }

document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('button.copy');
  if (!btn) return;
  const el = document.getElementById(btn.dataset.copy);
  const text = el.tagName === 'INPUT' ? el.value : el.textContent;
  navigator.clipboard.writeText(text).then(() => {
    const old = btn.textContent;
    btn.textContent = t('copied');
    setTimeout(() => { btn.textContent = old; }, 1500);
  });
});

// --- Creation page ----------------------------------------------------------

const createForm = document.getElementById('create-form');
if (createForm) {
  const expire = document.getElementById('expire');
  const expireAtField = document.getElementById('expire-at-field');
  const expireAt = document.getElementById('expire-at');

  /** "YYYY-MM-DDTHH:mm" in the visitor's own timezone, as the input expects. */
  function localValue(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
      + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function syncExpiry() {
    const custom = expire.value === 'custom';
    expireAtField.classList.toggle('hidden', !custom);
    expireAt.required = custom;
    // A minute from now: the bound has to move with the clock, or a form left
    // open would offer instants already past.
    if (custom) expireAt.min = localValue(new Date(Date.now() + 60000));
  }

  expire.addEventListener('change', syncExpiry);
  syncExpiry();

  createForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    hide('create-error');
    const btn = document.getElementById('submit-btn');
    const label = btn.textContent;
    btn.disabled = true;
    btn.textContent = t('encrypting');
    try {
      // The instant is read before anything is encrypted: a date already past
      // is the visitor's mistake to fix, not work to throw away afterwards.
      let expiresAt = null;
      if (expire.value === 'custom') {
        expiresAt = Math.floor(new Date(expireAt.value).getTime() / 1000);
        if (!expireAt.value || !Number.isFinite(expiresAt) || expiresAt * 1000 <= Date.now()) {
          throw new Error(t('bad_expiry'));
        }
      }

      const text = document.getElementById('secret').value;
      const password = document.getElementById('password').value;
      const { urlKey, payload } = await encryptText(text, password);

      const res = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
          payload,
          expire: expire.value,
          expires_at: expiresAt,
          burn: document.getElementById('burn').checked,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw apiError(data);

      const base = new URL('view.php', window.location.href);
      document.getElementById('share-link').value =
        base.href + '?id=' + data.id + '#' + urlKey;
      document.getElementById('delete-link').value =
        new URL('api.php', window.location.href).href +
        '?id=' + data.id + '&delete=' + data.delete_token;

      createForm.classList.add('hidden');
      show('result');
    } catch (err) {
      setText('create-error', t('create_failed', { error: err.message }));
      show('create-error');
    } finally {
      btn.disabled = false;
      btn.textContent = label;
    }
  });

  document.getElementById('new-paste').addEventListener('click', () => {
    document.getElementById('secret').value = '';
    document.getElementById('password').value = '';
    document.getElementById('burn').checked = false;
    expireAt.value = '';
    hide('result');
    createForm.classList.remove('hidden');
  });
}

// --- Reading page -----------------------------------------------------------

const statusEl = document.getElementById('status');
if (statusEl && !createForm) {
  const params = new URLSearchParams(window.location.search);
  const id = params.get('id');
  const urlKey = window.location.hash.slice(1);

  async function fetchAndDecrypt() {
    setText('status', t('decrypting'));
    show('status');
    const res = await fetch('api.php?id=' + encodeURIComponent(id));
    const data = await res.json();
    if (!res.ok) throw apiError(data);
    const payload = data.payload;

    const tryDecrypt = async (password) => {
      const text = await decryptPayload(payload, urlKey, password);
      hide('status');
      hide('password-prompt');
      setText('secret-text', text);
      if (data.burn) show('burned-notice');
      show('content');
    };

    if (payload.pwd) {
      hide('status');
      show('password-prompt');
      document.getElementById('password-form').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        hide('decrypt-error');
        try {
          await tryDecrypt(document.getElementById('paste-password').value);
        } catch {
          setText('decrypt-error', t('bad_password'));
          show('decrypt-error');
        }
      });
      if (data.burn) {
        setText('status', t('burn_already_fetched'));
        show('status');
      }
    } else {
      await tryDecrypt('');
    }
  }

  (async () => {
    if (!id || !urlKey) {
      setText('status', t('missing_key'));
      return;
    }
    try {
      const metaRes = await fetch('api.php?id=' + encodeURIComponent(id) + '&meta=1');
      const meta = await metaRes.json();
      if (!metaRes.ok) throw apiError(meta);

      if (meta.burn) {
        hide('status');
        show('burn-confirm');
        document.getElementById('reveal-btn').addEventListener('click', () => {
          hide('burn-confirm');
          fetchAndDecrypt().catch(showError);
        }, { once: true });
      } else {
        await fetchAndDecrypt();
      }
    } catch (err) {
      showError(err);
    }
  })();

  function showError(err) {
    hide('password-prompt');
    setText('status',
      err.code === 'not_found'
        ? t('not_found')
        : t('error', { error: err.message }));
    show('status');
  }
}
