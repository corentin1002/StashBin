<?php
// French — StashBin's reference language.
//
// This file is the reference: every key used anywhere in the code must appear
// in it. Other languages may be incomplete; a key missing there is filled in
// from here rather than showing its identifier on screen.
//
// The "js." keys are published into the page for the JavaScript; the "error."
// keys are the API messages, whose code, by contrast, is not translated. The
// {placeholders} are substituted at display time.
return [
    // --- Creation page ------------------------------------------------------
    'create.page_title'          => 'StashBin — Nouveau secret',
    'create.logged_in'           => 'Connecté en tant que {user} — {logout}',
    'create.logout'              => 'déconnexion',
    'create.no_auth_notice'      => 'Authentification désactivée : quiconque atteint cette page peut créer un secret.',
    'create.secret_label'        => 'Secret à partager',
    'create.secret_placeholder'  => 'Collez ici le texte à chiffrer…',
    'create.expire_label'        => 'Expiration',
    'create.burn_label'          => 'Détruire après la première lecture',
    'create.password_label'      => 'Mot de passe (optionnel)',
    'create.password_placeholder' => 'Protection supplémentaire',
    'create.submit'              => 'Chiffrer et créer le lien',
    'create.done_title'          => 'Secret créé ✔',
    'create.done_note'           => 'Le contenu a été chiffré dans votre navigateur : le serveur ne peut pas le lire.',
    'create.share_link'          => 'Lien de partage',
    'create.delete_link'         => 'Lien de suppression',
    'create.delete_link_auth'    => 'Lien de suppression (nécessite d\'être connecté)',
    'create.copy'                => 'Copier',
    'create.another'             => 'Créer un autre secret',

    // --- Lifetimes ----------------------------------------------------------
    'expire.1h'    => '1 heure',
    'expire.1d'    => '1 jour',
    'expire.1w'    => '1 semaine',
    'expire.1m'    => '1 mois',
    'expire.never' => 'Jamais',

    // --- Reading page -------------------------------------------------------
    'view.page_title'        => 'StashBin — Secret partagé',
    'view.loading'           => 'Chargement…',
    'view.burn_warning'      => '⚠️ Ce secret sera {emphasis}. Ne continuez que si vous êtes prêt à le lire maintenant.',
    'view.burn_emphasis'     => 'détruit dès sa lecture',
    'view.reveal'            => 'Afficher le secret',
    'view.password_required' => 'Ce secret est protégé par un mot de passe.',
    'view.password_label'    => 'Mot de passe',
    'view.decrypt'           => 'Déchiffrer',
    'view.burned_notice'     => '⚠️ Ce secret vient d\'être détruit : cette page est la seule copie. Il ne sera plus accessible.',
    'view.copy_content'      => 'Copier le contenu',

    // --- Sign-in page -------------------------------------------------------
    'login.page_title'      => 'StashBin — Connexion',
    'login.intro'           => 'Connectez-vous pour créer un secret.',
    'login.username'        => 'Utilisateur',
    'login.password'        => 'Mot de passe',
    'login.submit'          => 'Se connecter',
    'login.expired'         => 'Session expirée, réessayez.',
    'login.bad_credentials' => 'Identifiants incorrects.',

    // --- JavaScript strings -------------------------------------------------
    'js.encrypting'           => 'Chiffrement…',
    'js.decrypting'           => 'Déchiffrement…',
    'js.copied'               => 'Copié ✔',
    'js.create_failed'        => 'Échec de la création : {error}',
    'js.bad_password'         => 'Mot de passe incorrect.',
    'js.burn_already_fetched' => '⚠️ Secret à lecture unique déjà récupéré : cette page est votre seule chance de le déchiffrer.',
    'js.missing_key'          => 'Lien invalide : il manque l’identifiant ou la clé de déchiffrement (après le #).',
    'js.not_found'            => 'Ce secret n’existe pas ou plus (expiré, supprimé, ou déjà lu).',
    'js.error'                => 'Erreur : {error}',
    'js.server_error'         => 'erreur serveur',

    // --- API messages -------------------------------------------------------
    'error.invalid_id'         => 'identifiant invalide',
    'error.not_found'          => 'introuvable',
    'error.bad_delete_token'   => 'jeton de suppression invalide',
    'error.unauthorized'       => 'authentification requise',
    'error.bad_csrf'           => 'jeton CSRF invalide',
    'error.too_large'          => 'contenu trop volumineux',
    'error.bad_request'        => 'requête invalide',
    'error.incomplete_payload' => 'payload incomplet',
    'error.unknown_expiration' => 'durée de vie inconnue',
    'error.method_not_allowed' => 'méthode non autorisée',
];
