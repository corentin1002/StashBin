# Changelog

Toutes les évolutions notables de StashBin sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [Non publié]

### Ajouté

- Licence MIT.
- Chiffrement de bout en bout dans le navigateur : AES-256-GCM avec clé dérivée par PBKDF2-SHA256 (310 000 itérations) ; la clé voyage dans le fragment `#` de l'URL et n'atteint jamais le serveur.
- Création de secrets réservée aux utilisateurs authentifiés (sessions PHP, protection CSRF).
- Lecture publique par lien, avec mot de passe optionnel mélangé à la dérivation de clé.
- Expiration configurable (1 h, 1 jour, 1 semaine, 1 mois, jamais) avec purge automatique des secrets expirés.
- Destruction après première lecture, précédée d'un écran de confirmation pour éviter de consommer le secret par accident.
- Lien de suppression remis au créateur.
- Gestion des comptes en ligne de commande (`bin/user.php` : `add`, `passwd`, `del`, `list`).
- Stockage SQLite sans dépendance externe, schéma créé automatiquement.
- Interface en français, thème clair/sombre automatique.
- Banc d'essai `containers/` : images Apache + mod_php et nginx + PHP-FPM, paramétrées par version de PHP (8.3 à 8.6), avec le code monté en lecture seule et la base SQLite dans un volume dédié (chemin surchargeable via `STASHBIN_DB`).
- `containers/stashbin.sh` : pilote unique du banc d'essai (`up`, `user`, `logs`, `down`, `reset`, `list`, `test`). `user` relaie les sous-commandes de `bin/user.php` (`add`, `passwd`, `del`, `list`) dans le conteneur, sous l'identité `www-data`. `test` rejoue le parcours applicatif complet sur les huit combinaisons version × serveur et échoue si l'une d'elles régresse.
- Compatibilité vérifiée de PHP 8.1 à 8.6 (8.6 en release candidate), sous Apache comme sous nginx : aucune dépréciation ni avertissement.
- En-têtes de sécurité : CSP stricte, `X-Content-Type-Options`, `Referrer-Policy`, cookies `HttpOnly`/`SameSite`.

### Corrigé

- L'exemple de configuration nginx pour la production ne définissait pas `client_max_body_size` : nginx plafonnant les corps de requête à 1 Mio par défaut, tout secret compris entre 1 et 2 Mio était rejeté par un `413` avant d'atteindre PHP, alors que `config.php` en autorise 2 Mio.

### Sécurité

- Le lien de suppression exige désormais une session authentifiée en plus du jeton : un lien qui fuite est inutilisable par un tiers non connecté.
- L'exemple nginx pour la production place désormais `try_files $uri =404;` avant `fastcgi_pass`, pour qu'une URL de la forme `/inexistant/x.php` ne puisse pas faire exécuter un autre fichier.
