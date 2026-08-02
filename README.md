# 🔐 StashBin

> Partage de secrets chiffrés de bout en bout — la création est réservée aux utilisateurs autorisés, la lecture est ouverte à quiconque possède le lien.

Inspiré de [PrivateBin](https://github.com/PrivateBin/PrivateBin), avec une différence clé : **seuls les comptes authentifiés peuvent créer des secrets**. Aucun framework, aucune dépendance Composer — du PHP, SQLite et le WebCrypto du navigateur.

---

## ✨ Fonctionnalités

- **Chiffrement de bout en bout** — le texte est chiffré dans le navigateur (AES-256-GCM) ; le serveur ne stocke que du chiffré et ne peut jamais lire les secrets.
- **Création authentifiée** — comptes utilisateurs gérés en CLI ; sans compte, impossible de créer un secret.
- **Lecture par lien** — la clé de déchiffrement voyage dans le fragment `#` de l'URL, jamais envoyé au serveur.
- **Mot de passe optionnel** — mélangé à la clé lors de la dérivation (PBKDF2-SHA256, 310 000 itérations) ; sans lui, le lien seul ne suffit pas.
- **Expiration** — de 1 heure à jamais, purge automatique.
- **Destruction après lecture** — avec écran de confirmation avant de consommer le secret.
- **Lien de suppression** — remis au créateur, utilisable uniquement par un utilisateur connecté.

## 🔍 Comment ça marche

```
┌─ Navigateur (créateur) ─────────────┐         ┌─ Serveur ────────────────┐
│ clé aléatoire K (256 bits)          │         │                          │
│ + mot de passe optionnel            │  POST   │ stocke le chiffré,       │
│ → PBKDF2 → AES-256-GCM → chiffré ───┼────────▶│ ne voit jamais K ni le   │
│                                     │         │ mot de passe             │
│ lien = view.php?id=xxx#K            │         └──────────────────────────┘
└─────────────────────────────────────┘
                                                ┌─ Navigateur (lecteur) ───┐
   Le fragment #K n'est jamais transmis         │ lit K dans l'URL,        │
   au serveur : il reste dans le navigateur.    │ déchiffre localement     │
                                                └──────────────────────────┘
```

| Étape | Détail |
|---|---|
| Chiffrement | AES-256-GCM, IV 96 bits aléatoire |
| Dérivation de clé | PBKDF2-SHA256, 310 000 itérations, sel 128 bits |
| Clé d'URL | 256 bits aléatoires, encodés base64url dans le fragment `#` |
| Stockage | SQLite : payload chiffré + métadonnées (expiration, burn) |

## 🚀 Démarrage rapide

### Avec Podman (recommandé pour tester)

```bash
podman build -t stashbin .
podman run -d --name stashbin -p 8080:80 \
    -v .:/var/www/stashbin:ro,Z \
    -v stashbin-data:/var/lib/stashbin \
    stashbin

# créer un compte (en tant que www-data pour les droits sur la base)
podman exec -it -u www-data stashbin php /var/www/stashbin/bin/user.php add alice
```

→ **http://localhost:8080**

Le code est monté depuis le projet : toute modification est visible immédiatement, sans rebuild. La base SQLite vit dans le volume `stashbin-data`, hors du code.

```bash
podman rm -f stashbin           # arrêter
podman volume rm stashbin-data  # repartir de zéro
```

### Avec PHP seul

Prérequis : PHP ≥ 8.1 avec `pdo_sqlite` (`php-cli` + `php-pdo` sur Fedora, `php-cli` + `php-sqlite3` sur Debian/Ubuntu).

```bash
php bin/user.php add alice          # créer un compte autorisé
php -S localhost:8080 -t public     # serveur de développement
```

## 📦 Déploiement en production

> **⚠️ Deux règles impératives**
> 1. Le document root doit pointer sur **`public/`**, jamais sur la racine du projet (sinon `config.php` et la base SQLite seraient exposés).
> 2. Servez en **HTTPS** : la clé de déchiffrement transite dans l'URL côté client.

Le dossier `data/` doit être accessible en écriture par l'utilisateur PHP (`www-data`, `apache`…).

<details>
<summary><strong>Exemple Apache</strong></summary>

```apache
<VirtualHost *:443>
    ServerName stash.example.com
    DocumentRoot /var/www/StashBin/public
    <Directory /var/www/StashBin/public>
        Require all granted
    </Directory>
    # + configuration SSL
</VirtualHost>
```
</details>

<details>
<summary><strong>Exemple nginx</strong></summary>

```nginx
server {
    listen 443 ssl;
    server_name stash.example.com;
    root /var/www/StashBin/public;
    index index.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```
</details>

## 👥 Gestion des utilisateurs

```bash
php bin/user.php add <nom>      # créer un compte
php bin/user.php passwd <nom>   # changer le mot de passe
php bin/user.php del <nom>      # révoquer
php bin/user.php list           # lister
```

(En conteneur : préfixer par `podman exec -it -u www-data stashbin` et utiliser le chemin `/var/www/stashbin/bin/user.php`.)

## ⚙️ Configuration

Tout se passe dans `config.php` : durées d'expiration proposées, taille maximale des secrets (2 Mo par défaut), chemin de la base. En conteneur, la variable d'environnement `STASHBIN_DB` surcharge le chemin de la base.

## 🗂 Structure

```
config.php          réglages (expirations, taille max, chemin de la base)
Containerfile       image de test Apache + mod_php
public/             document root : pages, API, assets
├── index.php       création de secret (authentifié)
├── view.php        lecture publique
├── api.php         API JSON (création, lecture, suppression)
├── login.php       connexion
└── assets/         chiffrement WebCrypto + styles
src/bootstrap.php   base de données, sessions, CSRF, helpers
bin/user.php        gestion des comptes en CLI
data/               base SQLite (créée automatiquement)
```

## 🛡 Modèle de sécurité

- Le serveur ne voit **jamais** le contenu en clair, la clé, ni le mot de passe optionnel.
- Créer un secret exige un compte ; lire n'exige que le lien (+ mot de passe éventuel).
- Supprimer exige d'être connecté **et** de posséder le jeton remis au créateur.
- Sessions `HttpOnly`/`SameSite`, jetons CSRF, CSP stricte, mots de passe hachés (`password_hash`).
- Ce que le serveur peut faire s'il est compromis : supprimer des secrets, servir du JavaScript malveillant aux futurs visiteurs. C'est la même limite que PrivateBin — l'intégrité du serveur reste importante.
