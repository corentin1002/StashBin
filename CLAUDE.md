# StashBin

Partage de secrets chiffrés de bout en bout. PHP + SQLite côté serveur, WebCrypto côté navigateur. **Aucun framework, aucune dépendance Composer** — c'est un parti pris, pas un manque : n'en introduisez pas.

## Invariants de sécurité

Ce sont les promesses du produit. Une modification qui en casse une est un bug, quelle qu'en soit la justification.

- **Le serveur ne voit jamais le texte en clair, la clé, ni le mot de passe.** Il stocke un payload opaque et le restitue à l'identique. Aucun déchiffrement côté serveur, jamais.
- **La clé de déchiffrement vit dans le fragment `#` de l'URL**, qui n'est pas transmis au serveur. Ne la déplacez pas dans la partie interrogeable, ni dans un cookie, ni dans un en-tête.
- **Créer exige un compte ; lire n'exige que le lien.** Supprimer exige une session ouverte **et**, soit le jeton remis au créateur, soit d'être le propriétaire du secret. Les deux titres sont distincts : l'un ne vaut jamais l'autre, et un inconnu connecté ne supprime rien qui ne soit à lui. Unique dérogation, et elle est explicite : `'auth' => false` dans `config.php` (ou `STASHBIN_AUTH=0`) ouvre création et suppression à tout visiteur. Le défaut livré est `true` et doit le rester ; tous les autres invariants tiennent quelle que soit la valeur.
- **Le jeton de suppression est stocké haché** (SHA-256), jamais en clair.
- **Rien ne décrit le contenu d'un secret, pas même une étiquette.** L'inventaire ne connaît que l'identifiant, les dates et les accès. Un titre en clair a été écrit puis retiré : il rendait la liste plus lisible au prix de la seule métadonnée parlante que le serveur aurait pu lire. N'en réintroduisez pas sans que ce soit un choix assumé et écrit.
- **L'inventaire est cloisonné par compte.** La propriété se vérifie dans la requête SQL, pas avant : un identifiant qui n'est pas à vous ne correspond simplement à rien.
- **Le document root est `public/`.** Le reste du dépôt ne doit jamais être servi.
- **HTTPS est une condition de fonctionnement, pas un durcissement.** `crypto.subtle` n'existe que dans un contexte sécurisé (HTTPS ou origine loopback) : servi en HTTP simple sur un nom d'hôte ordinaire, StashBin ne peut rien chiffrer du tout.

## Commandes

```bash
./tests/run.sh                    # 237 tests, quelques minutes — à lancer avant de valider
./tests/run.sh --no-browser       # sans Chromium, plus rapide pendant l'itération
./tests/run.sh --matrix           # suites PHP sur les huit combinaisons version × serveur

./containers/stashbin.sh up       # instance de développement (PHP 8.4 + Apache)
./containers/stashbin.sh up 8.6 nginx
AUTH=0 ./containers/stashbin.sh up  # instance ouverte, sans authentification
./containers/stashbin.sh user add alice
./containers/stashbin.sh down
./containers/stashbin.sh clean    # tout retirer une fois terminé
```

`tests/run.sh` renvoie `0` si et seulement si tout passe. Lancez-le avant de proposer un commit ; s'il échoue, corrigez plutôt que d'ajuster le test, sauf si le test lui-même est faux (ça arrive, dites-le alors explicitement).

## Conventions

- **Tout ce qui est publié est en anglais** : commentaires du code, descriptions et messages d'assertion des tests, sorties des scripts, messages de commit, titres et corps de pull request, noms de branche, et tous les `.md` — à l'exception de `README.fr.md` et de ce fichier. Les identifiants du code (fonctions, variables, colonnes) l'étaient déjà. **Ce fichier et nos échanges restent en français.**
- L'historique d'avant l'internationalisation est en français. On ne le réécrit pas : la rupture est datée et se lit très bien.
- **`README.md` est en anglais, `README.fr.md` en français, et l'anglais fait foi.** Toute modification de l'un vient avec sa traduction dans l'autre, dans le même commit — un test de `tests/unit.test.php` compare la structure des sections et le nombre de tests annoncé, et échoue si un seul des deux a bougé. Les autres `.md` restent monolingues : les dupliquer, c'est garantir qu'ils divergent.
- **Aucun texte d'interface en dur.** Toute chaîne vue par un visiteur passe par `t()` et vit dans `src/lang/`. `src/lang/fr.php` fait référence : une clé qui n'y figure pas n'a pas de repli. Ne le confondez pas avec `default_locale`, qui vaut `en` et désigne la langue *servie* quand la négociation ne trouve rien.
- Un commentaire explique une contrainte que le code ne peut pas montrer. Pas de commentaire qui paraphrase la ligne suivante, ni qui s'adresse au relecteur d'une pull request.
- `declare(strict_types=1);` en tête de tout fichier PHP contenant du code exécutable. `config.php`, qui ne fait que retourner un tableau, en est exempt.
- Requêtes préparées systématiques. `db()->quote()` est toléré dans les tests, jamais dans `public/` ni `src/`.
- Le CHANGELOG suit Keep a Changelog : rubriques `Added`, `Fixed`, `Security`.

## Pièges vérifiés

Chacun a coûté du temps une fois ; ils sont documentés pour ne pas le refaire.

- **En conteneur, la CLI doit s'exécuter en `www-data`.** Un compte créé par `root` rend la base inaccessible en écriture au serveur web — et la panne ne se manifeste qu'à la création d'un secret, pas à la connexion. Utilisez `./containers/stashbin.sh user`, qui s'en charge.
- **Apache exige `AllowOverride None`** sur un montage en lecture seule, sinon il cherche un `.htaccess`, échoue, et renvoie `403` sur *toutes* les pages.
- **nginx plafonne les corps de requête à 1 Mio par défaut**, alors que `config.php` autorise 2 Mio. Toute configuration nginx doit fixer `client_max_body_size` au-dessus de `max_size`, sinon les gros secrets sont rejetés par un `413` avant d'atteindre PHP.
- **PDO_SQLite renvoie des entiers natifs depuis PHP 8.1.** `api.php` compare `expires` à `time()` sans transtypage et en dépend.
- **Depuis PHP 8.4, le coût bcrypt par défaut passe de 10 à 12.** Les comptes existants restent valides mais conservent l'ancien coût ; le code ne les re-hache pas.
- **Rien ne compare jamais le message d'une erreur d'API**, seulement son champ `code`. Les messages sont traduits ; le code, lui, est le contrat. Deux endroits s'y sont laissé prendre : `showError()` dans `stashbin.js`, puis le parcours de fumée de `containers/stashbin.sh` — celui-là est resté cassé une PR entière, parce qu'il n'est pas joué par `tests/run.sh`.
- **La CSP interdit le script inline**, donc les chaînes destinées au JavaScript ne peuvent pas être injectées dans un `<script>`. Elles voyagent en attribut `data-i18n` de `<body>`, posé par `client_strings()`. N'assouplissez pas la CSP pour contourner ça.
- **Les contextes Chromium des tests fixent leur langue explicitement.** L'interface suit `Accept-Language` et la langue par défaut du navigateur varie d'une image à l'autre : sans `locale:` explicite, les libellés attendus deviennent imprévisibles. Un test de traduction ouvre en plus son propre contexte, sans session, sinon `login.php` redirige vers la page de création.
- **Les tests navigateur partagent l'espace réseau du conteneur applicatif** pour obtenir une origine `127.0.0.1`, donc un contexte sécurisé. Ne remplacez pas ce montage par un drapeau Chromium de contournement : il ne fonctionne pas de façon fiable.
- **`view.php` sonde `?meta` avant tout le reste.** Un lecteur qui arrive après la disparition du secret n'atteint donc jamais la requête du payload : c'est pourquoi le journal enregistre la sonde *quand le secret est mort*, et elle seule. La sonde sur un secret vivant, elle, n'est pas une lecture — la compter afficherait « consulté » pour un secret que personne n'a vu.
- **`created` est en secondes.** Deux secrets créés dans la même seconde ne s'ordonnent pas d'eux-mêmes : l'inventaire trie `created DESC, rowid DESC`. Sans le second critère, l'ordre est indéterminé et un test navigateur le voit passer une fois sur deux.
- **La validation HTML5 native empêche l'événement `submit` d'être émis.** Le champ de date porte un `min` : Chromium refuse le formulaire lui-même, le gestionnaire `submit` n'est jamais appelé, et un test qui attend le message de l'application patiente jusqu'au délai d'attente. Le filet applicatif reste indispensable — Safari a longtemps rendu `datetime-local` en champ texte sans rien valider — et pour l'éprouver, un test pose `form.noValidate = true`.
- **Ne restaurez jamais un fichier par `git checkout` pendant une mutation de test** tant que le travail n'est pas commité : les fichiers nouveaux ne sont pas connus de git, et les fichiers modifiés reviennent à `HEAD`, pas à l'état d'avant la mutation. Commitez d'abord, mutez ensuite.

## Organisation

```
config.php          valeurs littérales : authentification, expirations, taille
                    max, chemin de la base, langue de repli ; surcharges
                    d'environnement dans env_overrides() de src/bootstrap.php
public/             document root — pages, api.php, assets/
  secrets.php         inventaire du créateur : états, journal des accès
  assets/stashbin.js  toute la cryptographie navigateur
src/bootstrap.php   base, sessions, CSRF, langue, helpers ; tout le code partagé
src/lang/           un fichier par langue offerte ; fr.php fait référence
bin/user.php        gestion des comptes en CLI
containers/         banc d'essai PHP 8.3→8.6 × Apache/nginx
tests/              jeu de test — voir tests/README.md
data/               base SQLite (ignorée par git)
```

`src/bootstrap.php` est volontairement le seul fichier partagé : pas d'autoloader, pas de hiérarchie de classes. Ajoutez-y une fonction plutôt que de créer un fichier pour trois lignes.

## Écrire des tests

Toute modification du comportement doit venir avec un test. Le socle est `tests/lib.php` — assertions et client HTTP en une centaine de lignes, sans PHPUnit ni Composer.

- Une règle métier ou un cas d'erreur → `tests/api.test.php`
- Une garantie de sécurité, ou le cloisonnement d'un inventaire → `tests/security.test.php`
- Un comportement propre à l'instance ouverte → `tests/noauth.test.php`, jouée contre un second conteneur démarré avec `STASHBIN_AUTH=0` (le réglage est lu au démarrage, il n'y a pas de bascule à chaud)
- Une fonction de `src/bootstrap.php` → `tests/unit.test.php`
- Une traduction ou la négociation de langue → `tests/unit.test.php` pour les fonctions, `tests/browser.test.mjs` pour ce que voit le visiteur

Les suites affirment parfois des libellés d'interface **en français** : `tests/lib.php` pose `Accept-Language: fr` sur chaque requête et le contexte Chromium fixe `locale: 'fr-FR'`, pour que ces libellés ne dépendent pas du repli du serveur. `new Http($base, language: null)` sert à éprouver le repli lui-même.
- De la cryptographie ou un parcours d'interface → `tests/browser.test.mjs`

Les suites PHP tournent **dans le conteneur applicatif** : elles peuvent donc confronter la réponse HTTP au contenu réel de la base, ce qui est le seul moyen de vérifier qu'un jeton est bien stocké haché ou qu'un payload n'est jamais déchiffré.

Le libellé d'un test se lit comme une phrase et décrit le comportement attendu, pas la mécanique : « un visiteur anonyme ne peut pas créer de secret (401) », pas « test création 401 ».

Après avoir ajouté une fonctionnalité, cassez-la volontairement et vérifiez qu'au moins un test s'en aperçoit. Le jeu de test actuel a été validé ainsi : vingt-quatre régressions introduites, vingt-quatre détectées.

## Git

- Ne committez pas sur `main` : créez une branche, puis proposez une pull request.
- Ne committez et ne poussez que si on vous le demande.
- Messages de commit, titres et corps de pull request en anglais, à l'impératif ou au substantif, avec un corps qui explique le *pourquoi* quand il n'est pas évident.
