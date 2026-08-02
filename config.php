<?php
// Configuration de StashBin.
return [
    // Chemin de la base SQLite (le dossier doit être accessible en écriture par PHP).
    // Surchargeable par la variable d'environnement STASHBIN_DB (utile en conteneur).
    'db' => getenv('STASHBIN_DB') ?: __DIR__ . '/data/stashbin.sqlite',

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

    // Nom du cookie de session.
    'session_name' => 'stashbin',
];
