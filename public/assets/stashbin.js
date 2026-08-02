'use strict';

// --- Utilitaires ------------------------------------------------------------

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

// Dérive la clé AES à partir de la clé d'URL et du mot de passe optionnel.
// Le serveur ne voit ni l'un ni l'autre.
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
    btn.textContent = 'Copié ✔';
    setTimeout(() => { btn.textContent = old; }, 1500);
  });
});

// --- Page de création -------------------------------------------------------

const createForm = document.getElementById('create-form');
if (createForm) {
  createForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    hide('create-error');
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Chiffrement…';
    try {
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
          expire: document.getElementById('expire').value,
          burn: document.getElementById('burn').checked,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'erreur serveur');

      const base = new URL('view.php', window.location.href);
      document.getElementById('share-link').value =
        base.href + '?id=' + data.id + '#' + urlKey;
      document.getElementById('delete-link').value =
        new URL('api.php', window.location.href).href +
        '?id=' + data.id + '&delete=' + data.delete_token;

      createForm.classList.add('hidden');
      show('result');
    } catch (err) {
      setText('create-error', 'Échec de la création : ' + err.message);
      show('create-error');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Chiffrer et créer le lien';
    }
  });

  document.getElementById('new-paste').addEventListener('click', () => {
    document.getElementById('secret').value = '';
    document.getElementById('password').value = '';
    document.getElementById('burn').checked = false;
    hide('result');
    createForm.classList.remove('hidden');
  });
}

// --- Page de lecture --------------------------------------------------------

const statusEl = document.getElementById('status');
if (statusEl && !createForm) {
  const params = new URLSearchParams(window.location.search);
  const id = params.get('id');
  const urlKey = window.location.hash.slice(1);

  async function fetchAndDecrypt() {
    setText('status', 'Déchiffrement…');
    show('status');
    const res = await fetch('api.php?id=' + encodeURIComponent(id));
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'erreur serveur');
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
          setText('decrypt-error', 'Mot de passe incorrect.');
          show('decrypt-error');
        }
      });
      if (data.burn) {
        setText('status', '⚠️ Secret à lecture unique déjà récupéré : cette page est votre seule chance de le déchiffrer.');
        show('status');
      }
    } else {
      await tryDecrypt('');
    }
  }

  (async () => {
    if (!id || !urlKey) {
      setText('status', 'Lien invalide : il manque l’identifiant ou la clé de déchiffrement (après le #).');
      return;
    }
    try {
      const metaRes = await fetch('api.php?id=' + encodeURIComponent(id) + '&meta=1');
      const meta = await metaRes.json();
      if (!metaRes.ok) throw new Error(meta.error || 'erreur serveur');

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
      err.message === 'introuvable'
        ? 'Ce secret n’existe pas ou plus (expiré, supprimé, ou déjà lu).'
        : 'Erreur : ' + err.message);
    show('status');
  }
}
