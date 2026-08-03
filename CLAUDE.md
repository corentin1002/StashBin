# StashBin

Partage de secrets chiffrés de bout en bout. PHP + SQLite côté serveur, WebCrypto côté navigateur. **Aucun framework, aucune dépendance Composer** — c'est un parti pris, pas un manque : n'en introduisez pas.

## Invariants de sécurité

Ce sont les promesses du produit. Une modification qui en casse une est un bug, quelle qu'en soit la justification.

- **Le serveur ne voit jamais le texte en clair, la clé, ni le mot de passe.** Il stocke un payload opaque et le restitue à l'identique. Aucun déchiffrement côté serveur, jamais.
- **La clé de déchiffrement vit dans le fragment `#` de l'URL**, qui n'est pas transmis au serveur. Ne la déplacez pas dans la partie interrogeable, ni dans un cookie, ni dans un en-tête.
- **Créer exige un compte ; lire n'exige que le lien.** Supprimer exige une session ouverte **et** le jeton remis au créateur.
- **Le jeton de suppression est stocké haché** (SHA-256), jamais en clair.
- **Le document root est `public/`.** Le reste du dépôt ne doit jamais être servi.
- **HTTPS est une condition de fonctionnement, pas un durcissement.** `crypto.subtle` n'existe que dans un contexte sécurisé (HTTPS ou origine loopback) : servi en HTTP simple sur un nom d'hôte ordinaire, StashBin ne peut rien chiffrer du tout.

## Commandes

```bash
./tests/run.sh                    # 114 tests, quelques minutes — à lancer avant de valider
./tests/run.sh --no-browser       # sans Chromium, plus rapide pendant l'itération
./tests/run.sh --matrix           # suites PHP sur les huit combinaisons version × serveur

./containers/stashbin.sh up       # instance de développement (PHP 8.4 + Apache)
./containers/stashbin.sh up 8.6 nginx
./containers/stashbin.sh user add alice
./containers/stashbin.sh down
./containers/stashbin.sh clean    # tout retirer une fois terminé
```

`tests/run.sh` renvoie `0` si et seulement si tout passe. Lancez-le avant de proposer un commit ; s'il échoue, corrigez plutôt que d'ajuster le test, sauf si le test lui-même est faux (ça arrive, dites-le alors explicitement).

## Conventions

- **Commentaires, textes d'interface, descriptions de test, messages de commit et CHANGELOG : en français.** Identifiants du code (fonctions, variables, colonnes) : en anglais. Cette séparation est constante dans tout le dépôt.
- Un commentaire explique une contrainte que le code ne peut pas montrer. Pas de commentaire qui paraphrase la ligne suivante, ni qui s'adresse au relecteur d'une pull request.
- `declare(strict_types=1);` en tête de tout fichier PHP contenant du code exécutable. `config.php`, qui ne fait que retourner un tableau, en est exempt.
- Requêtes préparées systématiques. `db()->quote()` est toléré dans les tests, jamais dans `public/` ni `src/`.
- Le CHANGELOG suit Keep a Changelog : rubriques `Ajouté`, `Corrigé`, `Sécurité`.

## Pièges vérifiés

Chacun a coûté du temps une fois ; ils sont documentés pour ne pas le refaire.

- **En conteneur, la CLI doit s'exécuter en `www-data`.** Un compte créé par `root` rend la base inaccessible en écriture au serveur web — et la panne ne se manifeste qu'à la création d'un secret, pas à la connexion. Utilisez `./containers/stashbin.sh user`, qui s'en charge.
- **Apache exige `AllowOverride None`** sur un montage en lecture seule, sinon il cherche un `.htaccess`, échoue, et renvoie `403` sur *toutes* les pages.
- **nginx plafonne les corps de requête à 1 Mio par défaut**, alors que `config.php` autorise 2 Mio. Toute configuration nginx doit fixer `client_max_body_size` au-dessus de `max_size`, sinon les gros secrets sont rejetés par un `413` avant d'atteindre PHP.
- **PDO_SQLite renvoie des entiers natifs depuis PHP 8.1.** `api.php` compare `expires` à `time()` sans transtypage et en dépend.
- **Depuis PHP 8.4, le coût bcrypt par défaut passe de 10 à 12.** Les comptes existants restent valides mais conservent l'ancien coût ; le code ne les re-hache pas.
- **Les tests navigateur partagent l'espace réseau du conteneur applicatif** pour obtenir une origine `127.0.0.1`, donc un contexte sécurisé. Ne remplacez pas ce montage par un drapeau Chromium de contournement : il ne fonctionne pas de façon fiable.

## Organisation

```
config.php          expirations, taille max, chemin de la base
public/             document root — pages, api.php, assets/
  assets/stashbin.js  toute la cryptographie navigateur
src/bootstrap.php   base, sessions, CSRF, helpers ; tout le code partagé
bin/user.php        gestion des comptes en CLI
containers/         banc d'essai PHP 8.3→8.6 × Apache/nginx
tests/              jeu de test — voir tests/README.md
data/               base SQLite (ignorée par git)
```

`src/bootstrap.php` est volontairement le seul fichier partagé : pas d'autoloader, pas de hiérarchie de classes. Ajoutez-y une fonction plutôt que de créer un fichier pour trois lignes.

## Écrire des tests

Toute modification du comportement doit venir avec un test. Le socle est `tests/lib.php` — assertions et client HTTP en une centaine de lignes, sans PHPUnit ni Composer.

- Une règle métier ou un cas d'erreur → `tests/api.test.php`
- Une garantie de sécurité → `tests/security.test.php`
- Une fonction de `src/bootstrap.php` → `tests/unit.test.php`
- De la cryptographie ou un parcours d'interface → `tests/browser.test.mjs`

Les suites PHP tournent **dans le conteneur applicatif** : elles peuvent donc confronter la réponse HTTP au contenu réel de la base, ce qui est le seul moyen de vérifier qu'un jeton est bien stocké haché ou qu'un payload n'est jamais déchiffré.

Le libellé d'un test se lit comme une phrase et décrit le comportement attendu, pas la mécanique : « un visiteur anonyme ne peut pas créer de secret (401) », pas « test création 401 ».

Après avoir ajouté une fonctionnalité, cassez-la volontairement et vérifiez qu'au moins un test s'en aperçoit. Le jeu de test actuel a été validé ainsi : douze régressions introduites, douze détectées.

## Git

- Ne committez pas sur `main` : créez une branche, puis proposez une pull request.
- Ne committez et ne poussez que si on vous le demande.
- Messages de commit en français, à l'impératif ou au substantif, avec un corps qui explique le *pourquoi* quand il n'est pas évident.
