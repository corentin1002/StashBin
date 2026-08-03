# Changelog

Toutes les évolutions notables de StashBin sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [Non publié]

### Ajouté

- Licence MIT.
- Chiffrement de bout en bout dans le navigateur : AES-256-GCM avec clé dérivée par PBKDF2-SHA256 (310 000 itérations) ; la clé voyage dans le fragment `#` de l'URL et n'atteint jamais le serveur.
- Création de secrets réservée aux utilisateurs authentifiés (sessions PHP, protection CSRF).
- Authentification désactivable par le réglage `auth` de `config.php`, pour une instance ouverte dont l'accès est déjà restreint autrement. Elle reste exigée par défaut : c'est la raison d'être du projet. Désactivée, la création et la suppression sont accessibles à tout visiteur, `login.php` renvoie vers la page de création qui annonce le mode ouvert, et le lien de suppression n'exige plus que son jeton. Rien d'autre ne bouge : chiffrement dans le navigateur, jeton de suppression stocké haché, protection CSRF et en-têtes de durcissement sont maintenus, et une suite de test dédiée (`tests/noauth.test.php`, 19 tests) le vérifie sur une instance réellement ouverte.
- `config.php` ne contient plus que des valeurs littérales, directement modifiables. Les surcharges par variable d'environnement — `STASHBIN_DB` pour le chemin de la base, `STASHBIN_AUTH` pour l'authentification — sont rassemblées dans `env_overrides()` de `src/bootstrap.php`, qui n'applique une variable que si elle est définie et non vide.
- `AUTH=0 ./containers/stashbin.sh up` démarre une instance de développement sans authentification.
- Lecture publique par lien, avec mot de passe optionnel mélangé à la dérivation de clé.
- Expiration configurable (1 h, 1 jour, 1 semaine, 1 mois, jamais) avec purge automatique des secrets expirés.
- Destruction après première lecture, précédée d'un écran de confirmation pour éviter de consommer le secret par accident.
- Lien de suppression remis au créateur.
- Gestion des comptes en ligne de commande (`bin/user.php` : `add`, `passwd`, `del`, `list`).
- Stockage SQLite sans dépendance externe, schéma créé automatiquement.
- Interface en français, thème clair/sombre automatique.
- Banc d'essai `containers/` : images Apache + mod_php et nginx + PHP-FPM, paramétrées par version de PHP (8.3 à 8.6), avec le code monté en lecture seule et la base SQLite dans un volume dédié (chemin surchargeable via `STASHBIN_DB`).
- `containers/stashbin.sh` : pilote unique du banc d'essai (`up`, `user`, `logs`, `down`, `reset`, `clean`, `list`, `test`). `clean` retire conteneurs, volumes et images produits par le banc — et rien d'autre ; `clean --all` y ajoute les images de base `php:*` téléchargées. `user` relaie les sous-commandes de `bin/user.php` (`add`, `passwd`, `del`, `list`) dans le conteneur, sous l'identité `www-data`. `test` rejoue le parcours applicatif complet sur les huit combinaisons version × serveur et échoue si l'une d'elles régresse.
- Compatibilité vérifiée de PHP 8.1 à 8.6 (8.6 en release candidate), sous Apache comme sous nginx : aucune dépréciation ni avertissement.
- Jeu de test complet (`tests/run.sh`) : 140 tests répartis en cinq suites — unitaire (fonctions de `src/bootstrap.php`), API (règles métier), sécurité (garanties annoncées par le README), instance ouverte (comportement sans authentification, jouée contre un second conteneur) et navigateur (cryptographie de bout en bout et parcours d'interface dans Chromium). Le lanceur démarre une instance neuve, joue les suites et détruit tout ; son code de sortie vaut 0 si et seulement si tout passe. Aucune dépendance Composer : les assertions et le client HTTP tiennent dans `tests/lib.php`.
- La cryptographie de `public/assets/stashbin.js` est désormais couverte : aller-retour chiffrement/déchiffrement sur des textes variés, rejet d'un mot de passe, d'une clé, d'un vecteur ou d'un chiffré altérés, et contrôle des tailles annoncées (clé de 256 bits, sel de 128, vecteur de 96, 310 000 itérations).
- En-têtes de sécurité : CSP stricte, `X-Content-Type-Options`, `Referrer-Policy`, cookies `HttpOnly`/`SameSite`.

### Corrigé

- L'exemple de configuration nginx pour la production ne définissait pas `client_max_body_size` : nginx plafonnant les corps de requête à 1 Mio par défaut, tout secret compris entre 1 et 2 Mio était rejeté par un `413` avant d'atteindre PHP, alors que `config.php` en autorise 2 Mio.

### Sécurité

- Le lien de suppression exige désormais une session authentifiée en plus du jeton : un lien qui fuite est inutilisable par un tiers non connecté.
- L'exemple nginx pour la production place désormais `try_files $uri =404;` avant `fastcgi_pass`, pour qu'une URL de la forme `/inexistant/x.php` ne puisse pas faire exécuter un autre fichier.
