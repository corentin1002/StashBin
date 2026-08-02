#!/usr/bin/env php
<?php
// Gestion des utilisateurs autorisés à créer des secrets.
//
//   php bin/user.php add <utilisateur>      crée un compte (mot de passe demandé)
//   php bin/user.php passwd <utilisateur>   change le mot de passe
//   php bin/user.php del <utilisateur>      supprime le compte
//   php bin/user.php list                   liste les comptes

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

function prompt_password(string $label): string
{
    fwrite(STDOUT, $label . ' : ');
    shell_exec('stty -echo 2>/dev/null');
    $password = rtrim((string) fgets(STDIN), "\n");
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    if (strlen($password) < 8) {
        fwrite(STDERR, "Le mot de passe doit faire au moins 8 caractères.\n");
        exit(1);
    }
    return $password;
}

$command = $argv[1] ?? 'help';
$username = $argv[2] ?? null;

switch ($command) {
    case 'add':
        if (!$username) {
            fwrite(STDERR, "Usage : php bin/user.php add <utilisateur>\n");
            exit(1);
        }
        $hash = password_hash(prompt_password("Mot de passe pour « $username »"), PASSWORD_DEFAULT);
        try {
            db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
                ->execute([$username, $hash, time()]);
        } catch (PDOException) {
            fwrite(STDERR, "L'utilisateur « $username » existe déjà.\n");
            exit(1);
        }
        echo "Utilisateur « $username » créé.\n";
        break;

    case 'passwd':
        if (!$username) {
            fwrite(STDERR, "Usage : php bin/user.php passwd <utilisateur>\n");
            exit(1);
        }
        $hash = password_hash(prompt_password("Nouveau mot de passe pour « $username »"), PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET pass_hash = ? WHERE username = ?');
        $stmt->execute([$hash, $username]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "Utilisateur « $username » introuvable.\n");
            exit(1);
        }
        echo "Mot de passe de « $username » mis à jour.\n";
        break;

    case 'del':
        if (!$username) {
            fwrite(STDERR, "Usage : php bin/user.php del <utilisateur>\n");
            exit(1);
        }
        $stmt = db()->prepare('DELETE FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "Utilisateur « $username » introuvable.\n");
            exit(1);
        }
        echo "Utilisateur « $username » supprimé.\n";
        break;

    case 'list':
        foreach (db()->query('SELECT username, created FROM users ORDER BY username') as $row) {
            printf("%-24s créé le %s\n", $row['username'], date('Y-m-d', (int) $row['created']));
        }
        break;

    default:
        echo "Usage : php bin/user.php {add|passwd|del|list} [utilisateur]\n";
}
