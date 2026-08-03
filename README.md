# 🔐 StashBin

> Partage de secrets chiffrés de bout en bout — la création est réservée aux utilisateurs autorisés, la lecture est ouverte à quiconque possède le lien.

Inspiré de [PrivateBin](https://github.com/PrivateBin/PrivateBin), avec une différence clé : **seuls les comptes authentifiés peuvent créer des secrets**. Il ne s'agit pas d'un fork : le code est une réécriture complète et indépendante, sans aucune ligne reprise du projet d'origine. Aucun framework, aucune dépendance Composer — du PHP, SQLite et le WebCrypto du navigateur.

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
./containers/stashbin.sh up          # PHP 8.4 + Apache
./containers/stashbin.sh user alice  # créer un compte
```

→ **http://127.0.0.1:8081**

Le code est monté depuis le projet : toute modification est visible immédiatement, sans rebuild. La base SQLite vit dans un volume, hors du code.

```bash
./containers/stashbin.sh up 8.5 nginx  # autre version, autre serveur
./containers/stashbin.sh down          # arrêter
./containers/stashbin.sh reset         # repartir de zéro
```

`./containers/stashbin.sh test` rejoue le parcours complet — connexion, création, relecture, destruction après lecture — sur les huit combinaisons de version et de serveur. Voir [`containers/README.md`](containers/README.md).

### Avec PHP seul

Prérequis : PHP ≥ 8.1 avec `pdo_sqlite` (`php-cli` + `php-pdo` sur Fedora, `php-cli` + `php-sqlite3` sur Debian/Ubuntu). Aucune dépendance Composer.

```bash
php bin/user.php add alice          # créer un compte autorisé
php -S localhost:8080 -t public     # serveur de développement
```

## ✅ Compatibilité

Vérifiée par exécution du parcours applicatif complet, sans aucune dépréciation ni avertissement :

| PHP | 8.1 | 8.2 | 8.3 | 8.4 | 8.5 | 8.6 |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Compatible | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Sous **Apache + mod_php** comme sous **nginx + PHP-FPM** (testé de 8.3 à 8.6 pour les deux serveurs). PHP 8.6 est testé en *release candidate*, sa version finale étant attendue le 19 novembre 2026.

PHP 8.3 n'est plus en support actif depuis le 31 décembre 2025 (correctifs de sécurité jusqu'au 31 décembre 2027) : **8.4 est le choix recommandé**, avec un support sécurité jusqu'au 31 décembre 2028.

Un détail à connaître en montant depuis PHP 8.3 : à partir de 8.4, le coût bcrypt par défaut de `password_hash()` passe de 10 à 12. Les comptes existants continuent de fonctionner sans intervention, mais conservent l'ancien coût tant que leur mot de passe n'est pas changé.

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

    # Impératif : nginx limite les corps de requête à 1 Mio par défaut et
    # renvoie un 413 au-delà. Sans cette ligne, tout secret dépassant 1 Mio
    # serait rejeté avant d'atteindre PHP, alors que config.php en autorise
    # 2 Mio. Gardez cette valeur au-dessus de `max_size`.
    client_max_body_size 8m;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        # try_files avant fastcgi_pass : sans lui, une URL de la forme
        # /inexistant/x.php peut faire exécuter un autre fichier.
        try_files $uri =404;
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

En conteneur, les mêmes sous-commandes passent par le script du banc d'essai, qui les exécute sous l'identité `www-data` :

```bash
./containers/stashbin.sh user add alice
./containers/stashbin.sh user passwd alice
./containers/stashbin.sh user del alice
./containers/stashbin.sh user list
```

> L'identité compte : un compte créé en `root` rend la base inaccessible en écriture au serveur web, et la panne n'apparaît qu'au moment de créer un secret — pas à la connexion.

## ⚙️ Configuration

Tout se passe dans `config.php` : durées d'expiration proposées, taille maximale des secrets (2 Mo par défaut), chemin de la base. En conteneur, la variable d'environnement `STASHBIN_DB` surcharge le chemin de la base.

## 🗂 Structure

```
config.php          réglages (expirations, taille max, chemin de la base)
containers/         banc d'essai multi-versions (voir containers/README.md)
├── stashbin.sh     pilote unique : up, user, logs, down, reset, list, test
├── Containerfile.apache    Apache + mod_php
├── Containerfile.nginx     nginx + PHP-FPM
├── nginx.conf              configuration du serveur nginx
└── *-entrypoint.sh         démarrage des deux piles
public/             document root : pages, API, assets
├── index.php       création de secret (authentifié)
├── view.php        lecture publique
├── api.php         API JSON (création, lecture, suppression)
├── login.php       connexion
└── assets/         chiffrement WebCrypto + styles
src/bootstrap.php   base de données, sessions, CSRF, helpers
bin/user.php        gestion des comptes en CLI
data/               base SQLite (créée automatiquement)
└── .htaccess       « Require all denied » : garde-fou si le document root
                    est mal configuré et pointe sur la racine du projet
```

## 🛡 Modèle de sécurité

- Le serveur ne voit **jamais** le contenu en clair, la clé, ni le mot de passe optionnel.
- Créer un secret exige un compte ; lire n'exige que le lien (+ mot de passe éventuel).
- Supprimer exige d'être connecté **et** de posséder le jeton remis au créateur.
- Sessions `HttpOnly`/`SameSite`, jetons CSRF, CSP stricte, mots de passe hachés (`password_hash`).
- Ce que le serveur peut faire s'il est compromis : supprimer des secrets, servir du JavaScript malveillant aux futurs visiteurs. C'est la même limite que PrivateBin — l'intégrité du serveur reste importante.

## 📄 Licence

Distribué sous licence [MIT](LICENSE).

StashBin est une implémentation indépendante, écrite à partir de zéro. Le projet
[PrivateBin](https://github.com/PrivateBin/PrivateBin) (licence zlib/libpng) en a
inspiré le concept et le modèle de sécurité, mais aucun code n'en est issu : la
mention ci-dessus est un remerciement, pas une obligation de licence.
