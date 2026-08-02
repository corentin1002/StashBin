# Changelog

Toutes les évolutions notables de StashBin sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [Non publié]

### Ajouté

- Chiffrement de bout en bout dans le navigateur : AES-256-GCM avec clé dérivée par PBKDF2-SHA256 (310 000 itérations) ; la clé voyage dans le fragment `#` de l'URL et n'atteint jamais le serveur.
- Création de secrets réservée aux utilisateurs authentifiés (sessions PHP, protection CSRF).
- Lecture publique par lien, avec mot de passe optionnel mélangé à la dérivation de clé.
- Expiration configurable (1 h, 1 jour, 1 semaine, 1 mois, jamais) avec purge automatique des secrets expirés.
- Destruction après première lecture, précédée d'un écran de confirmation pour éviter de consommer le secret par accident.
- Lien de suppression remis au créateur.
- Gestion des comptes en ligne de commande (`bin/user.php` : `add`, `passwd`, `del`, `list`).
- Stockage SQLite sans dépendance externe, schéma créé automatiquement.
- Interface en français, thème clair/sombre automatique.
- `Containerfile` Apache + mod_php pour tester avec Podman : code monté en lecture seule, base SQLite dans un volume dédié (chemin surchargeable via `STASHBIN_DB`).
- En-têtes de sécurité : CSP stricte, `X-Content-Type-Options`, `Referrer-Policy`, cookies `HttpOnly`/`SameSite`.

### Sécurité

- Le lien de suppression exige désormais une session authentifiée en plus du jeton : un lien qui fuite est inutilisable par un tiers non connecté.
