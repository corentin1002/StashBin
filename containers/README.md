# Banc d'essai multi-versions

Faire tourner StashBin sur n'importe quelle combinaison de **PHP 8.3 / 8.4 / 8.5 / 8.6** et **Apache / nginx**, sans écrire une seule commande `podman`.

Tout passe par un script unique : `containers/stashbin.sh`.

## Démarrer

```bash
./containers/stashbin.sh up            # PHP 8.4 + Apache, les valeurs par défaut
./containers/stashbin.sh up 8.5 nginx  # ou toute autre combinaison
```

Le script construit l'image si nécessaire, démarre le conteneur, attend que le serveur réponde vraiment, puis affiche l'URL. Il ne rend la main qu'une fois le service joignable — pas de « ça devrait être prêt ».

Ensuite, un compte est nécessaire pour créer des secrets :

```bash
./containers/stashbin.sh user alice    # le mot de passe est demandé
```

→ **http://127.0.0.1:8081**

Pour éprouver l'instance ouverte, celle qui ne demande pas de compte :

```bash
AUTH=0 ./containers/stashbin.sh up
```

Le script pose `STASHBIN_AUTH=0` dans le conteneur, ce qui surcharge le `'auth' => true` de `config.php` sans y toucher — le dépôt est monté en lecture seule.

## Les six commandes

| Commande | Effet |
|---|---|
| `up [version] [serveur]` | Construit et démarre. Défaut : `8.4 apache` |
| `user add\|passwd\|del <nom>` | Gère les comptes de l'instance en cours |
| `user list` | Liste les comptes |
| `logs [-f]` | Journaux du conteneur |
| `down` | Arrête l'instance |
| `reset` | Efface la base (comptes et secrets) |
| `clean [--all]` | Retire conteneurs, volumes et images du banc d'essai |
| `list` | Combinaisons disponibles et état actuel |
| `test [version…]` | Rejoue le parcours complet sur toute la matrice |

`user` relaie les sous-commandes de `bin/user.php` dans le conteneur, en s'exécutant sous l'identité `www-data`. `user <nom>` sans sous-commande est un raccourci pour `user add <nom>`.

`up` remplace l'instance précédente : une seule tourne à la fois, il n'y a donc jamais à se demander laquelle répond sur quel port.

## Tester toute la matrice

```bash
./containers/stashbin.sh test          # les 8 combinaisons
./containers/stashbin.sh test 8.5 8.6  # seulement ces versions
```

Pour chaque combinaison, le script construit l'image, démarre la pile et rejoue un parcours réel contre le serveur web — connexion avec jeton CSRF, création d'un secret, relecture, destruction après lecture, rejet d'un identifiant invalide, service des fichiers statiques, et vérification que `config.php` et `src/` ne sont pas exposés hors de `public/`. Il termine par un tableau récapitulatif et sort en erreur si quoi que ce soit échoue.

```
  8.4  apache  OK (8.4.24)
  8.4  nginx   OK (8.4.24)
  8.5  apache  OK (8.5.9)
  ...
```

## Ranger après usage

```bash
./containers/stashbin.sh clean        # conteneurs, volumes et images construites ici
./containers/stashbin.sh clean --all  # + les images de base php:* téléchargées
```

`clean` ne touche que ce que le banc d'essai a fabriqué : les conteneurs `stashbin-test` et `stashbin-selftest`, les volumes `stashbin-test-data` et `stashbin-selftest-data`, et les images `stashbin:<version>-<serveur>`. Un volume ou une image que vous avez créé par ailleurs n'est jamais concerné. La commande annonce chaque suppression réelle et répond « Rien à nettoyer » quand il n'y a rien à faire, donc elle peut se relancer sans risque.

`--all` ajoute les tags officiels `php:<version>-apache` et `php:<version>-fpm` que le banc télécharge lui-même. Ce n'est pas une opération coûteuse à annuler : podman conserve les couches partagées, et un `up` suivant reconstruit en quelques secondes.

## Ce qu'il faut savoir

**Le code est monté, pas copié.** Une modification dans `public/` ou `src/` est visible au rechargement de la page, sans reconstruire. Seul un changement dans `containers/` demande un nouveau `up`.

**La base survit aux changements de version.** Le volume `stashbin-test-data` est partagé : passer de 8.4 à 8.6 conserve les comptes et les secrets, ce qui permet de tester une montée de version sur des données existantes. `reset` repart de zéro.

**Ports.** L'instance interactive écoute sur 8081, la matrice de test sur 8099, uniquement sur `127.0.0.1`. En cas de conflit :

```bash
PORT=8082 ./containers/stashbin.sh up
TEST_PORT=9099 ./containers/stashbin.sh test
```

**`AUTH`.** `AUTH=0 ./containers/stashbin.sh up` démarre une instance sans authentification (défaut : `1`). La variable ne concerne que `up` : `test` force l'authentification, puisque le parcours qu'il rejoue commence par une connexion.

**PHP 8.6 n'est pas sorti** (finale prévue le 19 novembre 2026) : `up 8.6` utilise l'image `php:8.6-rc`. Le script fait la traduction, il n'y a rien à ajuster.

## Les deux images

| Fichier | Pile | Base |
|---|---|---|
| `Containerfile.apache` | Apache + mod_php, un seul processus | `php:<version>-apache` |
| `Containerfile.nginx` | nginx + PHP-FPM dans un conteneur | `php:<version>-fpm` |

Les deux prennent la version en argument de build (`--build-arg PHP_TAG=…`), servent `public/` comme racine web, et rangent la base SQLite dans `/var/lib/stashbin` (volume), hors du code.

Deux détails valent d'être signalés, parce qu'ils coûtent chacun une heure de débogage quand on les découvre soi-même :

- **Apache est configuré avec `AllowOverride None`.** Sans cela, sur un montage en lecture seule, il tente de lire un `.htaccess`, échoue, et renvoie `403` sur *toutes* les pages avec le message « Server unable to read htaccess file, denying access to be safe ».
- **Les deux points d'entrée corrigent le propriétaire du volume au démarrage.** Si la base a été créée par root, PHP tourne ensuite en `www-data` et échoue sur `attempt to write a readonly database` — mais seulement au moment de créer un secret, pas à la connexion. C'est aussi pourquoi `stashbin.sh user` s'exécute en `www-data`.

## Construire à la main

Si vous préférez vous passer du script :

```bash
podman build -f containers/Containerfile.nginx \
    --build-arg PHP_TAG=8.5-fpm -t stashbin:8.5-nginx containers/

podman run -d --name stashbin -p 127.0.0.1:8081:80 \
    -v "$PWD:/var/www/stashbin:ro,z" \
    -v stashbin-test-data:/var/lib/stashbin:z \
    stashbin:8.5-nginx

podman exec -it -u www-data stashbin \
    php /var/www/stashbin/bin/user.php add alice
```

Le contexte de build est `containers/` (et non la racine) : les images ne contiennent que la configuration du serveur, jamais le code applicatif.
