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
    'create.logged_in'           => 'Connecté en tant que {user}',
    'create.logout'              => 'Déconnexion',
    'create.my_secrets'          => 'Mes secrets',
    'create.no_auth_notice'      => 'Authentification désactivée : quiconque atteint cette page peut créer un secret.',
    'create.secret_label'        => 'Secret à partager',
    'create.secret_placeholder'  => 'Collez ici le texte à chiffrer…',
    'create.expire_label'        => 'Expiration',
    'create.expire_date_label'   => 'Date d\'expiration',
    'create.expire_time_label'   => 'Heure (celle de votre appareil)',
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
    'expire.custom' => 'Date et heure précises…',

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

    // --- Inventory page -----------------------------------------------------
    'secrets.page_title'      => 'StashBin — Mes secrets',
    'secrets.heading'         => 'Mes secrets',
    'secrets.intro'           => 'Ce que vous avez créé, et ce qu\'il en est advenu. Chaque entrée porte l\'identifiant qui figure dans son lien : le serveur ne sait rien d\'autre du secret, et ne peut pas le déchiffrer — sa clé n\'a jamais quitté votre navigateur.',
    'secrets.back'            => 'Créer un secret',
    'secrets.empty'           => 'Vous n\'avez encore créé aucun secret.',
    'secrets.created'         => 'Créé le {date} UTC',
    'secrets.expires'         => 'Expire le {date} UTC',
    'secrets.expires_never'   => 'Sans expiration',
    'secrets.burn_badge'      => 'Usage unique',
    'secrets.state_unread'    => 'Jamais consulté',
    'secrets.state_read_one'  => 'Consulté une fois',
    'secrets.state_read_many' => 'Consulté {count} fois',
    'secrets.state_burned'    => 'Détruit après lecture',
    'secrets.state_deleted'   => 'Supprimé',
    'secrets.state_expired'   => 'Expiré',
    'secrets.gone_on'         => 'Disparu le {date} UTC',
    'secrets.delete'          => 'Supprimer',
    'secrets.forget'          => 'Retirer de l\'historique',
    'secrets.deleted_notice'  => 'Secret supprimé : le contenu chiffré a été effacé.',
    'secrets.forgotten_notice' => 'Entrée retirée de votre historique.',
    'secrets.clear'           => 'Vider l\'historique ({count} terminés)',
    'secrets.cleared_one'     => 'Une entrée terminée retirée de votre historique.',
    'secrets.cleared_many'    => '{count} entrées terminées retirées de votre historique.',
    'secrets.access_log'      => 'Accès ({count})',
    'secrets.access_none'     => 'Aucun accès enregistré.',
    'secrets.access_when'     => 'Date (UTC)',
    'secrets.access_outcome'  => 'Issue',
    'secrets.access_ip'       => 'Adresse IP',
    'secrets.access_agent'    => 'Navigateur',
    'secrets.outcome_served'  => 'Secret remis',
    'secrets.outcome_gone'    => 'Lien rejoué, secret déjà disparu',
    'secrets.unknown'         => '—',

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
    'js.bad_expiry'           => 'Choisissez une date et une heure à venir.',
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
    'error.bad_expiry'         => 'date d\'expiration invalide',
    'error.method_not_allowed' => 'méthode non autorisée',
];
