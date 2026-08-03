<?php
// Configuration de StashBin. Chaque réglage est une valeur littérale : pour
// changer quoi que ce soit, écrivez la valeur voulue ici, rien d'autre.
//
// Deux réglages acceptent en plus une variable d'environnement, indispensable
// en conteneur où ce fichier est monté en lecture seule :
//
//   STASHBIN_DB      chemin de la base SQLite
//   STASHBIN_AUTH    « 0 », « false », « off » ou « no » désactivent
//                    l'authentification ; toute autre valeur l'exige
//   STASHBIN_LOCALE  langue de repli
//
// La variable l'emporte sur le fichier quand elle est définie et non vide.
// Absente, elle ne change rien : c'est ce fichier qui décide.
return [
    // Chemin de la base SQLite (le dossier doit être accessible en écriture par PHP).
    'db' => __DIR__ . '/data/stashbin.sqlite',

    // Authentification exigée pour créer et supprimer un secret. C'est le
    // fonctionnement normal de StashBin.
    //
    // false ouvre la création à tout visiteur : à ne faire que sur une instance
    // dont l'accès est déjà restreint autrement (réseau interne, proxy
    // authentifiant). Tout le reste est inchangé — le chiffrement se fait
    // toujours dans le navigateur et le serveur ne lit toujours rien.
    'auth' => true,

    // Taille maximale du payload chiffré accepté (en octets).
    'max_size' => 2 * 1024 * 1024,

    // Durées de vie proposées (clé => secondes, null = illimité).
    'expirations' => [
        '1h'    => 3600,
        '1d'    => 86400,
        '1w'    => 604800,
        '1m'    => 2592000,
        'never' => null,
    ],
    'default_expiration' => '1w',

    // Langue servie quand le navigateur n'en demande aucune de disponible —
    // l'anglais, faute de mieux, étant plus largement lu que le français.
    // Les langues offertes sont les fichiers de src/lang/ : en ajouter une,
    // c'est y déposer un fichier, sans toucher au code.
    //
    // À ne pas confondre avec src/lang/fr.php, qui reste le fichier de
    // référence : c'est de lui qu'une traduction incomplète emprunte ses
    // chaînes manquantes, quelle que soit la valeur réglée ici.
    'default_locale' => 'en',

    // Nom du cookie de session.
    'session_name' => 'stashbin',
];
