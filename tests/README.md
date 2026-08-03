# Jeu de test

Une commande, un code de sortie : `0` si tout passe, `1` si quoi que ce soit échoue.

```bash
./tests/run.sh
```

Le lanceur construit l'image, démarre une instance neuve, crée un compte de test, joue les cinq suites, affiche un bilan et détruit tout. Il n'y a rien à préparer et rien à nettoyer après.

## Options

| Option | Effet |
|---|---|
| *(aucune)* | Tout, sur PHP 8.4 + Apache |
| `--version 8.6` | Sur une autre version de PHP (8.3, 8.4, 8.5, 8.6) |
| `--server nginx` | Derrière nginx + PHP-FPM plutôt qu'Apache |
| `--no-browser` | Sans les tests navigateur — plus rapide, mais laisse la cryptographie sans filet |
| `--matrix` | Suites PHP sur les huit combinaisons version × serveur |
| `--keep` | Laisse l'instance en vie pour inspection après coup |

## Ce qui est couvert

**175 tests** répartis en cinq suites.

| Suite | Tests | Portée |
|---|--:|---|
| `unit.test.php` | 51 | Fonctions de `src/bootstrap.php` sans passer par HTTP : échappement, configuration et surcharges d'environnement, schéma de base, purge des expirés, jetons CSRF, négociation de la langue et dictionnaires |
| `api.test.php` | 43 | Règles métier via HTTP : authentification, CSRF, validation du payload, durées de vie, destruction après lecture, liens de suppression, méthodes refusées, codes d'erreur et langue de la réponse |
| `security.test.php` | 27 | Garanties annoncées par le README : rien hors de `public/`, en-têtes de durcissement, session et fixation, stockage haché, injections, et le fait que le choix de la langue n'ouvre rien |
| `noauth.test.php` | 19 | Instance ouverte (`auth` à false) : création sans compte, CSRF toujours exigée, suppression par le seul jeton, et tout ce qui ne bouge pas |
| `browser.test.mjs` | 35 | Chromium réel : cryptographie de bout en bout, parcours d'interface complets, et interface servie dans la langue du navigateur |

Les tests PHP s'exécutent **dans le conteneur applicatif**, sous l'identité `www-data`. Ils peuvent donc confronter la réponse HTTP à ce qui est réellement écrit en base — c'est ainsi qu'on vérifie qu'un jeton de suppression est bien stocké haché, ou qu'un payload n'est jamais déchiffré côté serveur.

`noauth.test.php` a besoin d'un serveur démarré avec `STASHBIN_AUTH=0` : le réglage est lu au démarrage, il n'y a pas de bascule à chaud. Le lanceur remplace donc l'instance le temps de cette suite, puis en redémarre une ordinaire pour les tests navigateur. En mode `--matrix`, la suite est rejouée sur les huit combinaisons : la surcharge par variable d'environnement passe par mod_php d'un côté et PHP-FPM de l'autre, ce qui n'est pas le même chemin.

Les tests navigateur pilotent Chromium sur l'application réelle. Ils couvrent la partie que rien d'autre n'exerce : `deriveKey`, `encryptText` et `decryptPayload` de `public/assets/stashbin.js`, appelées directement dans la page, puis les parcours complets — création, relecture, mot de passe, destruction après lecture, lien de suppression, et le parcours entier rejoué dans un navigateur anglophone.

La langue du contexte Chromium est **fixée explicitement** (`locale: 'fr-FR'`) : l'interface suit désormais `Accept-Language`, et la langue par défaut du navigateur varie d'une image à l'autre. Les tests qui vérifient une traduction ouvrent leur propre contexte, sans session, sans quoi `login.php` redirigerait vers la page de création.

## Deux points de conception

**Le navigateur partage l'espace réseau du conteneur applicatif.** `crypto.subtle` n'existe que dans un « contexte sécurisé » : HTTPS, ou une origine loopback. En servant l'application sur `127.0.0.1` du point de vue du navigateur, on obtient un contexte sûr sans fabriquer de certificat ni passer de drapeau de contournement à Chromium. C'est aussi la démonstration concrète que **HTTPS n'est pas un conseil mais une condition de fonctionnement** : sans contexte sécurisé, StashBin ne peut rien chiffrer du tout.

**Le paquet npm de Playwright est installé dans une image dérivée.** L'image officielle fournit les navigateurs mais pas le module qui les pilote ; `tests/Containerfile.browser` l'ajoute une fois pour toutes, pour qu'aucune exécution ne dépende du réseau.

## Écrire un test

Les fichiers PHP utilisent `tests/lib.php`, un socle d'une centaine de lignes — ni Composer, ni PHPUnit, conformément au parti pris du projet.

```php
group('Ce que je vérifie');

test('la description se lit comme une phrase', function () use ($http, $token) {
    $res = $http->createSecret(['expire' => '1h'], $token);
    assert_eq(201, $res->status, 'création acceptée');
});
```

Assertions disponibles : `assert_true`, `assert_eq`, `assert_contains`, `assert_not_contains`, `assert_matches`, `assert_throws`. Chacune prend en dernier argument une description de ce qui est attendu, reprise telle quelle dans le message d'échec.

`Http` gère les cookies de session : `login()`, `csrfToken()`, `createSecret()`, `get()`, `post()`, et `request()` avec l'option `rawPath: true` pour que le serveur voie les `..` sans que curl les résolve.

## Vérifier que le jeu de test sert à quelque chose

Un jeu de test qui ne tombe jamais ne prouve rien. Celui-ci a été éprouvé par mutation : dix-huit régressions ont été introduites une à une dans le code, et **les dix-huit ont été détectées** — contrôle CSRF retiré, création ouverte aux anonymes, secret à lecture unique non détruit, jeton de suppression stocké en clair, limite de taille supprimée, purge désactivée, échappement HTML neutralisé, CSP affaiblie, cookie sans `HttpOnly`, session non régénérée à la connexion, itérations PBKDF2 abaissées à 1 000, clé d'URL réduite à 64 bits, `load_lang()` privée de son garde-fou de chemin, `?lang=` ignoré par la négociation, `Vary: Accept-Language` retiré, clé absente de la traduction anglaise, erreur d'API privée de son code stable, et JavaScript comparant le message d'erreur plutôt que le code.

La dernière mérite un mot : c'est exactement la régression qu'a produite la première traduction. `showError()` reposait sur `err.message === 'introuvable'`, ce qui a cessé de fonctionner dès que le message a pu être en anglais. Le contrat est désormais le champ `code`, et un test navigateur le vérifie.

Il vaut la peine de refaire l'exercice après avoir ajouté une fonctionnalité : cassez-la volontairement et vérifiez qu'au moins un test s'en aperçoit.
