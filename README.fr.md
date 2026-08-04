# 🔐 StashBin

*[English](README.md) · **Français***

> Partage de secrets chiffrés de bout en bout — la création est réservée aux utilisateurs autorisés (ou ouverte à tous, au choix), la lecture est ouverte à quiconque possède le lien.

Inspiré de [PrivateBin](https://github.com/PrivateBin/PrivateBin), avec une différence clé : **seuls les comptes authentifiés peuvent créer des secrets**. Il ne s'agit pas d'un fork : le code est une réécriture complète et indépendante, sans aucune ligne reprise du projet d'origine. Aucun framework, aucune dépendance Composer — du PHP, SQLite et le WebCrypto du navigateur.

---

## ✨ Fonctionnalités

- **Chiffrement de bout en bout** — le texte est chiffré dans le navigateur (AES-256-GCM) ; le serveur ne stocke que du chiffré et ne peut jamais lire les secrets.
- **Création authentifiée** — comptes utilisateurs gérés en CLI ; sans compte, impossible de créer un secret. Désactivable d'une ligne (`'auth' => false`) pour une instance ouverte, quand l'accès est déjà restreint autrement.
- **Lecture par lien** — la clé de déchiffrement voyage dans le fragment `#` de l'URL, jamais envoyé au serveur.
- **Mot de passe optionnel** — mélangé à la clé lors de la dérivation (PBKDF2-SHA256, 310 000 itérations) ; sans lui, le lien seul ne suffit pas.
- **Expiration** — de 1 heure à jamais, ou une date et une heure précises, purge automatique.
- **Destruction après lecture** — avec écran de confirmation avant de consommer le secret.
- **Lien de suppression** — remis au créateur, utilisable uniquement par un utilisateur connecté.
- **Interface multilingue** — français et anglais, choisis d'après la langue du navigateur ; ajouter une langue, c'est déposer un fichier dans `src/lang/`.
- **Utilisable sur téléphone** — une feuille de style, aucun framework : la mise en page se replie jusqu'à un écran de 360 px, les champs restent à 16 px pour que Safari iOS ne zoome pas à la mise au point, et les cibles tactiles atteignent 44 px.
- **Un inventaire pour chaque créateur** — chaque secret créé, désigné par l'identifiant que porte son lien, avec son état (jamais consulté, consulté *n* fois, détruit après lecture, supprimé, expiré), le journal des accès reçus, un bouton de suppression tant qu'il vit, et un bouton unique pour vider d'un coup toutes les entrées terminées. Les secrets ne sont jamais consultables depuis cette page : leur clé n'a pas quitté le navigateur qui les a créés.

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
./containers/stashbin.sh clean         # tout retirer une fois terminé
```

`./containers/stashbin.sh test` rejoue le parcours complet — connexion, création, relecture, destruction après lecture — sur les huit combinaisons de version et de serveur. Voir [`containers/README.md`](containers/README.md).

## 🧪 Tests

```bash
./tests/run.sh          # 238 tests, quelques minutes
./tests/run.sh --help   # options : version, serveur, matrice complète…
```

Le lanceur démarre une instance neuve, joue les cinq suites et détruit tout : rien à préparer, rien à nettoyer. Le code de sortie vaut `0` si et seulement si tout passe.

| Suite | Tests | Portée |
|---|--:|---|
| Unitaire | 56 | Fonctions de `src/bootstrap.php` : échappement, configuration, surcharges d'environnement, schéma, purge, CSRF, langue |
| API | 44 | Règles métier via HTTP : authentification, validation, durées de vie, destruction après lecture, suppression, codes d'erreur |
| Sécurité | 28 | Rien hors de `public/`, en-têtes, fixation de session, stockage haché, injections, choix de la langue |
| Instance ouverte | 19 | Second conteneur sans authentification : création libre, CSRF toujours exigée, garanties inchangées |
| Navigateur | 36 | Chromium réel : cryptographie de bout en bout, parcours d'interface, langue servie |

Les tests navigateur exercent la partie que rien d'autre ne couvre — `deriveKey`, `encryptText`, `decryptPayload` — et vérifient qu'un chiffré altéré d'un seul bit est rejeté. L'ensemble a été éprouvé par mutation : vingt-quatre régressions introduites volontairement dans le code, vingt-quatre détectées. Voir [`tests/README.md`](tests/README.md).

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

Tout se passe dans `config.php`, qui ne contient que des valeurs littérales : écrivez celle que vous voulez, il n'y a rien d'autre à faire.

| Réglage | Défaut | Effet |
|---|---|---|
| `db` | `data/stashbin.sqlite` | Chemin de la base SQLite |
| `auth` | `true` | Authentification exigée pour créer et supprimer |
| `max_size` | 2 Mio | Taille maximale du payload chiffré |
| `expirations` / `default_expiration` | 1 h → jamais, `1w` | Durées de vie proposées, à côté de la date et l'heure que le créateur peut choisir |
| `default_locale` | `en` | Langue servie quand celle du navigateur n'est pas traduite |
| `session_name` | `stashbin` | Nom du cookie de session |

Trois d'entre eux acceptent en plus une variable d'environnement, indispensable en conteneur où le fichier est monté en lecture seule : `STASHBIN_DB` pour le chemin de la base, `STASHBIN_AUTH` pour l'authentification, `STASHBIN_LOCALE` pour la langue de repli. La variable l'emporte sur le fichier quand elle est définie et non vide ; absente, elle ne change rien.

### Langue de l'interface

L'interface est servie dans la langue du visiteur, déduite de l'en-tête `Accept-Language` qu'envoie son navigateur. Une étiquette régionale retombe sur sa langue — `fr-CA` est servi en `fr`.

**Quand aucune des langues demandées n'est traduite, c'est l'anglais qui est servi** (`default_locale`), comme plus largement lu que le français. Un visiteur germanophone ou hispanophone obtient donc l'interface anglaise, jamais une page à moitié traduite.

Le paramètre `?lang=` force la langue d'une page, quel que soit le navigateur :

```
https://exemple.org/index.php?lang=en
```

Ce choix est sans état : ni cookie ni session ne le mémorisent, il est simplement reconduit sur les liens que l'application fabrique elle-même.

**Ajouter une langue** — déposez un fichier dans `src/lang/`, nommé d'après son étiquette (`de.php`, `pt-br.php`), qui retourne le même tableau de clés que `src/lang/fr.php`. Rien d'autre : le code découvre les langues offertes en listant ce dossier. Une traduction incomplète est acceptée, les clés manquantes étant empruntées à une autre langue plutôt qu'affichées sous forme d'identifiants.

Deux rôles à ne pas confondre :

- **`default_locale` (`en`) est la langue *servie*** quand la négociation ne trouve rien.
- **`src/lang/fr.php` est la langue de *référence*** : c'est de lui qu'une traduction partielle emprunte ses chaînes manquantes. StashBin est écrit en français, ses chaînes y naissent, et les autres langues les traduisent. Toute clé employée dans le code doit donc y figurer.

Un test unitaire vérifie que chaque langue offerte traduit intégralement la référence et que ses marqueurs `{ainsi}` correspondent.

### Instance ouverte, sans authentification

```php
'auth' => false,        // dans config.php
```

```bash
STASHBIN_AUTH=0 …                     # ou par l'environnement
AUTH=0 ./containers/stashbin.sh up    # ou sur le banc d'essai
```

La création et la suppression deviennent alors accessibles à tout visiteur : plus de comptes, plus de page de connexion, `login.php` renvoie vers la page de création et le lien de suppression n'exige plus que son jeton.

> **⚠️ Ne le faites que si l'accès à l'instance est déjà restreint autrement** — réseau interne, VPN, proxy authentifiant. Sur l'Internet public, c'est un dépôt de secrets ouvert à l'écriture par n'importe qui.

Tout le reste est inchangé : le chiffrement se fait toujours dans le navigateur, le serveur ne lit toujours rien, le jeton de suppression reste stocké haché, la protection CSRF et les en-têtes de durcissement restent en place. Ces garanties sont vérifiées séparément sur une instance ouverte par la suite `tests/noauth.test.php`.

## 🗂 Structure

```
config.php          réglages (authentification, expirations, taille max,
                    chemin de la base, langue de repli)
containers/         banc d'essai multi-versions (voir containers/README.md)
├── stashbin.sh     pilote unique : up, user, logs, down, reset, clean,
│                   list, test
├── Containerfile.apache    Apache + mod_php
├── Containerfile.nginx     nginx + PHP-FPM
├── nginx.conf              configuration du serveur nginx
└── *-entrypoint.sh         démarrage des deux piles
public/             document root : pages, API, assets
├── index.php       création de secret (authentifié)
├── view.php        lecture publique
├── api.php         API JSON (création, lecture, suppression)
├── secrets.php     inventaire du créateur : états et journal des accès
├── login.php       connexion
└── assets/         chiffrement WebCrypto + styles
tests/              jeu de test complet (voir son README)
├── run.sh          lanceur unique : construit, joue, nettoie
├── unit.test.php   fonctions de src/bootstrap.php
├── api.test.php    règles métier de l'API
├── security.test.php  garanties de sécurité
├── noauth.test.php    comportement de l'instance ouverte
├── browser.test.mjs   cryptographie et parcours, dans Chromium
└── lib.php         assertions et client HTTP, sans dépendance
src/bootstrap.php   base de données, sessions, CSRF, langue, helpers
src/lang/           un fichier par langue offerte (fr.php fait référence)
bin/user.php        gestion des comptes en CLI
data/               base SQLite (créée automatiquement)
└── .htaccess       « Require all denied » : garde-fou si le document root
                    est mal configuré et pointe sur la racine du projet
```

## 🛡 Modèle de sécurité

- Le serveur ne voit **jamais** le contenu en clair, la clé, ni le mot de passe optionnel.
- Créer un secret exige un compte ; lire n'exige que le lien (+ mot de passe éventuel).
- Supprimer exige d'être connecté **et**, soit de posséder le jeton remis au créateur, soit d'être le propriétaire du secret. L'un ne vaut jamais l'autre : un lien de suppression qui fuit est inutilisable par un inconnu, et un inconnu connecté ne peut pas supprimer ce qui ne lui appartient pas.
- Ces deux dernières règles tombent — et seulement elles — si l'exploitant met `'auth' => false` : c'est un choix explicite, jamais le défaut livré.
- **Ce que le serveur sait**, et il vaut mieux le savoir : quel compte a créé quel secret, et pour chaque accès la date, l'adresse IP du lecteur telle que vue par le serveur web, et le navigateur qu'il déclare. Rien ne décrit le contenu — pas même une étiquette — et ce journal existe pour répondre à « est-ce qu'il l'a lu ? ». Il vit tant que l'entrée reste dans la liste de son créateur. Une instance sans authentification n'enregistre rien de tout ça : ni propriétaire, ni journal.
- Sessions `HttpOnly`/`SameSite`, jetons CSRF, CSP stricte, mots de passe hachés (`password_hash`).
- Ce que le serveur peut faire s'il est compromis : supprimer des secrets, servir du JavaScript malveillant aux futurs visiteurs. C'est la même limite que PrivateBin — l'intégrité du serveur reste importante.

## 📄 Licence

Distribué sous licence [MIT](LICENSE).

StashBin est une implémentation indépendante, écrite à partir de zéro. Le projet
[PrivateBin](https://github.com/PrivateBin/PrivateBin) (licence zlib/libpng) en a
inspiré le concept et le modèle de sécurité, mais aucun code n'en est issu : la
mention ci-dessus est un remerciement, pas une obligation de licence.
